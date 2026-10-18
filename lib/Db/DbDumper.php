<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\DBAL\Types\BinaryType;
use Doctrine\DBAL\Types\BlobType;
use OCA\NgBackup\Repository\Repository;

/**
 * Logical database dump and restore through the Nextcloud database connection: no pg_dump,
 * mysqldump or extra PHP extensions.
 *
 * - All tables with the Nextcloud prefix are dumped inside one REPEATABLE READ, read-only
 *   transaction, so the dump is consistent without maintenance mode.
 * - Rows are read in primary-key order with keyset pagination (pdo_pgsql buffers whole result
 *   sets, so a single big SELECT would not stream).
 * - Each table becomes its own blob stream of JSON lines: a header line, then one JSON array per
 *   row. A table that did not change produces the same blobs and costs nothing in the next run.
 * - The structure of each table (columns, types, primary key, indexes) is recorded in the manifest,
 *   so a restore can create a table that does not exist on the new instance, for example one left
 *   behind by an app that was removed long ago. Tables that exist keep the structure the new
 *   instance's migrations gave them.
 * - Foreign keys are not checked while rows are loaded (tables are restored in name order, not in
 *   dependency order), and are checked again before the restore commits.
 *
 * Phase 0 uses the Doctrine connection behind IDBConnection (private API) for schema
 * introspection; to be replaced by information_schema queries or a public API.
 */
final class DbDumper {
	private const BATCH = 2000;
	/** Rows per INSERT on restore; keeps the number of bound parameters well below database limits. */
	private const INSERT_ROWS = 100;
	/** Tables without a primary key are read in one query; refuse that above this size. */
	private const MAX_ROWS_WITHOUT_PK = 50000;

	public function __construct(
		private Connection $db,
		private string $prefix,
	) {
	}

	/**
	 * Dump everything in one go (one transaction, fully consistent).
	 *
	 * @return array{tables: array<string, array{blobs: list<string>, rows: int, bytes: int, sha256: string}>, sequences: array, provider: string, consistent: bool}
	 */
	public function dump(Repository $repo, array &$stats): array {
		$state = $this->startDump();
		while (!$state['done']) {
			$state = $this->dumpStep($repo, $state, PHP_FLOAT_MAX, $stats);
		}
		return $this->manifest($state);
	}

	/** State for a resumable dump (see dumpStep()). */
	public function startDump(): array {
		$tables = array_values(array_filter($this->db->createSchemaManager()->listTableNames(), fn (string $t) => str_starts_with($t, $this->prefix)));
		sort($tables);
		return ['tables' => $tables, 'i' => 0, 'after' => null, 'segments' => [], 'rows' => 0, 'bytes' => 0, 'hash' => null,
			'result' => [], 'sequences' => [], 'steps' => 0, 'done' => false];
	}

	/**
	 * Dump tables until $deadline, inside one REPEATABLE READ transaction for this step. A table
	 * that does not fit is resumed in the next step at the last primary key written. If the whole
	 * dump fits in one step it is fully consistent; otherwise each step is consistent on its own
	 * ('consistent' => false in the manifest).
	 */
	public function dumpStep(Repository $repo, array $state, float $deadline, array &$stats): array {
		$state['steps']++;
		$sm = $this->db->createSchemaManager();
		// A pack that fills up during the dump is uploaded right away; its index cache row must
		// wait until the read-only transaction is over.
		$repo->holdIndexCache();
		try {
			$this->db->setTransactionIsolation(TransactionIsolationLevel::REPEATABLE_READ);
			$this->db->beginTransaction();
			try {
				if ($this->db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
					$this->db->executeStatement('SET TRANSACTION READ ONLY');
				}
				while ($state['i'] < count($state['tables'])) {
					$table = $state['tables'][$state['i']];
					$schema = $sm->introspectTable($table);
					$writer = $repo->blobWriter($stats);
					$hash = $state['hash'] !== null ? unserialize(base64_decode($state['hash']), ['allowed_classes' => [\HashContext::class]]) : hash_init('sha256');
					if ($state['after'] === null && $state['segments'] === []) {
						$writer->write($this->header($schema) . "\n");
					}
					$complete = true;
					foreach ($this->rowLines($schema, $state['after']) as [$key, $line]) {
						$writer->write($line . "\n");
						hash_update($hash, $line . "\n");
						$state['rows']++;
						$state['after'] = $key;
						if (microtime(true) >= $deadline) {
							$complete = false;
							break;
						}
					}
					$state['segments'][] = $writer->finish();
					$state['bytes'] += $writer->bytes();
					if (!$complete) {
						$state['hash'] = base64_encode(serialize($hash));
						break;
					}
					$state['result'][$table] = ['blobs' => array_merge(...$state['segments']), 'rows' => $state['rows'],
						'bytes' => $state['bytes'], 'sha256' => hash_final($hash), 'schema' => self::structure($schema)];
					$state = array_merge($state, ['i' => $state['i'] + 1, 'after' => null, 'segments' => [], 'rows' => 0, 'bytes' => 0, 'hash' => null]);
					if (microtime(true) >= $deadline && $state['i'] < count($state['tables'])) {
						break;
					}
				}
				if ($state['i'] >= count($state['tables'])) {
					$state['sequences'] = $this->sequenceStates($state['tables']);
					$state['done'] = true;
				}
			} finally {
				$this->db->rollBack();
			}
		} finally {
			$repo->releaseIndexCache();
		}
		$repo->flushPacks();
		return $state;
	}

	public function manifest(array $state): array {
		return ['tables' => $state['result'], 'sequences' => $state['sequences'],
			'provider' => get_class($this->db->getDatabasePlatform()), 'consistent' => $state['steps'] === 1];
	}

	/**
	 * Replace the contents of every table in the dump. Tables that exist but are not in the dump
	 * are left alone (e.g. a newer app); the caller decides whether that is acceptable.
	 *
	 * @param array{tables: array<string, array{blobs: list<string>, rows: int, sha256: string, schema?: array}>, sequences?: array} $manifest
	 */
	public function restore(Repository $repo, array $manifest, ?array &$report = null, ?callable $heartbeat = null, bool $skipMissing = false): int {
		$report = ['skipped' => [], 'restored' => [], 'created' => [], 'missing' => []];
		$source = self::dumpPrefix($manifest);
		if ($source !== null && $source !== $this->prefix) {
			// Every table would look missing and be created under the old prefix, unused.
			throw new \RuntimeException("The backup's tables use the prefix '$source', this installation '{$this->prefix}'. Install Nextcloud again with 'dbtableprefix' => '$source' in config/config.php before occ maintenance:install, and restore again.");
		}
		$missing = $this->missingTables($manifest);
		$unknown = array_values(array_filter($missing, fn (string $t) => !isset($manifest['tables'][$t]['schema'])));
		if ($unknown !== [] && !$skipMissing) {
			throw new \RuntimeException(self::describeMissing($unknown, $this->prefix));
		}
		// Tables the backup knows the structure of are created first (DDL is not transactional
		// everywhere); tables it does not know are left out, as asked.
		foreach ($missing as $table) {
			if (isset($manifest['tables'][$table]['schema'])) {
				$this->db->createSchemaManager()->createTable(self::tableFromStructure($table, $manifest['tables'][$table]['schema']));
				$report['created'][] = $table;
			} else {
				$report['missing'][] = $table;
			}
		}
		$total = 0;
		$foreignKeys = [];
		$this->db->beginTransaction();
		try {
			$foreignKeys = $this->suspendForeignKeys();
			foreach ($manifest['tables'] as $table => $info) {
				if (in_array($table, $report['missing'], true)) {
					continue;
				}
				// Unchanged since the backup: leave it alone (much faster than delete + insert).
				$live = $this->checksum($table);
				if ($live['rows'] === $info['rows'] && hash_equals($info['sha256'], $live['sha256'])) {
					$report['skipped'][] = $table;
					continue;
				}
				$this->db->executeStatement('DELETE FROM ' . $this->db->quoteIdentifier($table));
				$columns = null;
				$binary = [];
				$batch = [];
				foreach ($repo->readLines($info['blobs']) as $line) {
					if ($line === '') {
						continue;
					}
					$data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
					if ($columns === null) {
						$columns = $data['columns'];
						$binary = array_flip($data['binary']);
						continue;
					}
					$row = [];
					foreach ($columns as $i => $col) {
						$value = $data[$i];
						if (is_array($value) && isset($value['$b'])) {
							$value = base64_decode($value['$b'], true); // text that was not valid UTF-8
						}
						$row[] = ($value !== null && isset($binary[$col])) ? base64_decode($value, true) : $value;
					}
					$batch[] = $row;
					if (count($batch) >= self::INSERT_ROWS) {
						$total += $this->insertBatch($table, $columns, $batch, $binary);
						$batch = [];
						if ($heartbeat !== null) {
							$heartbeat();
						}
					}
				}
				if ($batch !== []) {
					$total += $this->insertBatch($table, $columns, $batch, $binary);
				}
				$report['restored'][] = $table;
			}
			$this->restoreSequences($manifest['sequences'] ?? []);
			$this->resumeForeignKeys($foreignKeys);
			$this->db->commit();
			// Foreign keys of the tables created above, now their rows are in. Not inside the
			// transaction: MySQL commits on DDL. A failure is reported, not fatal: the data is
			// restored either way.
			foreach ($report['created'] as $table) {
				$structure = $manifest['tables'][$table]['schema'] ?? [];
				foreach ($structure['foreignKeys'] ?? [] as $fk) {
					try {
						$this->db->createSchemaManager()->createForeignKey(
							new \Doctrine\DBAL\Schema\ForeignKeyConstraint($fk['columns'], $fk['table'], $fk['foreign'], $fk['name'], $fk['options'] ?? []),
							$table);
					} catch (\Throwable $e) {
						$report['foreignKeyErrors'][] = $table . '.' . $fk['name'] . ': ' . $e->getMessage();
					}
				}
			}
		} catch (\Throwable $e) {
			$this->db->rollBack();
			// PostgreSQL rolls the dropped foreign keys back with the transaction; MySQL's session
			// switch must be turned on again by hand.
			if (in_array('SET FOREIGN_KEY_CHECKS = 1', $foreignKeys, true)) {
				$this->db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
			}
			throw $e;
		}
		return $total;
	}

	/**
	 * The table prefix of the instance the dump was made on (from its appconfig table), or null
	 * when the dump has none.
	 *
	 */
	public static function dumpPrefix(array $manifest): ?string {
		/** @var list<string> $tables table names */
		$tables = array_keys($manifest['tables'] ?? []);
		foreach ($tables as $name) {
			if (str_ends_with($name, 'appconfig') && !str_ends_with($name, '_ex_appconfig')) {
				return substr($name, 0, -strlen('appconfig'));
			}
		}
		return null;
	}

	/**
	 * Tables in the dump that do not exist on this instance.
	 *
	 * @return list<string>
	 */
	public function missingTables(array $manifest): array {
		$existing = array_flip($this->db->createSchemaManager()->listTableNames());
		return array_values(array_filter(array_keys($manifest['tables']), fn (string $t) => !isset($existing[$t])));
	}

	/** One message for all missing tables of an older backup, grouped by the app they probably belong to. */
	public static function describeMissing(array $tables, string $prefix): string {
		$groups = [];
		foreach ($tables as $t) {
			$name = substr($t, strlen($prefix));
			$groups[explode('_', $name, 2)[0]][] = $t;
		}
		$lines = [];
		foreach ($groups as $app => $list) {
			$lines[] = '- ' . $app . ': ' . implode(', ', $list);
		}
		return "These tables from the backup do not exist here, and this backup does not record their structure (made before 1.0.0-rc.2):\n"
			. implode("\n", $lines)
			. "\nInstall the apps they belong to (often apps that were removed or switched off long ago) and run the restore again, or leave these tables out with --skip-missing-tables.";
	}

	/**
	 * Portable description of a table: enough to create it again, also on another database type.
	 *
	 * @return array{columns: list<array<string, mixed>>, pk: list<string>, indexes: list<array{name: string, columns: list<string>, unique: bool}>}
	 */
	private static function structure(\Doctrine\DBAL\Schema\Table $table): array {
		$columns = [];
		foreach ($table->getColumns() as $c) {
			$columns[] = ['name' => $c->getName(), 'type' => \Doctrine\DBAL\Types\Type::lookupName($c->getType()),
				'length' => $c->getLength(), 'precision' => $c->getPrecision(), 'scale' => $c->getScale(),
				'unsigned' => $c->getUnsigned(), 'fixed' => $c->getFixed(), 'notnull' => $c->getNotnull(),
				'default' => $c->getDefault(), 'autoincrement' => $c->getAutoincrement()];
		}
		$indexes = [];
		foreach ($table->getIndexes() as $i) {
			if (!$i->isPrimary()) {
				// options holds the prefix lengths MySQL needs for indexes on long text columns
				$indexes[] = ['name' => $i->getName(), 'columns' => array_values($i->getColumns()), 'unique' => $i->isUnique(),
					'flags' => array_values($i->getFlags()), 'options' => $i->getOptions()];
			}
		}
		$foreignKeys = [];
		foreach ($table->getForeignKeys() as $fk) {
			$foreignKeys[] = ['name' => $fk->getName(), 'columns' => array_values($fk->getLocalColumns()),
				'table' => $fk->getForeignTableName(), 'foreign' => array_values($fk->getForeignColumns()), 'options' => $fk->getOptions()];
		}
		return ['columns' => $columns, 'pk' => array_values($table->getPrimaryKey()?->getColumns() ?? []), 'indexes' => $indexes,
			'foreignKeys' => $foreignKeys];
	}

	private static function tableFromStructure(string $name, array $structure): \Doctrine\DBAL\Schema\Table {
		$table = new \Doctrine\DBAL\Schema\Table($name);
		foreach ($structure['columns'] as $c) {
			$table->addColumn($c['name'], $c['type'], array_filter([
				'length' => $c['length'], 'precision' => $c['precision'], 'scale' => $c['scale'],
				'unsigned' => $c['unsigned'], 'fixed' => $c['fixed'], 'notnull' => $c['notnull'],
				'default' => $c['default'], 'autoincrement' => $c['autoincrement'],
			], fn ($v) => $v !== null));
		}
		if ($structure['pk'] !== []) {
			$table->setPrimaryKey($structure['pk']);
		}
		foreach ($structure['indexes'] as $i) {
			$i['unique']
				? $table->addUniqueIndex($i['columns'], $i['name'], $i['options'] ?? [])
				: $table->addIndex($i['columns'], $i['name'], $i['flags'] ?? [], $i['options'] ?? []);
		}
		return $table;
	}

	/**
	 * Stop checking foreign keys while rows are loaded in name order. PostgreSQL has no session
	 * switch for this without superuser rights, so its foreign keys of Nextcloud tables are dropped
	 * here and added back (and so checked) by resumeForeignKeys(), inside the same transaction.
	 *
	 * @return list<string> statements that restore the foreign keys
	 */
	private function suspendForeignKeys(): array {
		$platform = $this->db->getDatabasePlatform();
		if ($platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
			$rows = $this->db->fetchAllAssociative(
				"SELECT conrelid::regclass::text AS tbl, conname, pg_get_constraintdef(oid) AS def
				 FROM pg_constraint WHERE contype = 'f' AND connamespace = current_schema()::regnamespace");
			$restore = [];
			foreach ($rows as $r) {
				if (!str_starts_with(trim($r['tbl'], '"'), $this->prefix)) {
					continue;
				}
				$this->db->executeStatement(sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $r['tbl'], $this->db->quoteIdentifier($r['conname'])));
				$restore[] = sprintf('ALTER TABLE %s ADD CONSTRAINT %s %s', $r['tbl'], $this->db->quoteIdentifier($r['conname']), $r['def']);
			}
			return $restore;
		}
		if ($platform instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
			// MySQL does not check rows again when FOREIGN_KEY_CHECKS goes back to 1, and its DDL
			// would commit the transaction halfway. So every foreign key is checked explicitly,
			// with a query that finds rows pointing at nothing (resumeForeignKeys()).
			$rows = $this->db->fetchAllAssociative(
				'SELECT TABLE_NAME AS tbl, CONSTRAINT_NAME AS name, COLUMN_NAME AS col, REFERENCED_TABLE_NAME AS rtbl, REFERENCED_COLUMN_NAME AS rcol
				 FROM information_schema.KEY_COLUMN_USAGE
				 WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
				 ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION');
			$keys = [];
			foreach ($rows as $r) {
				if (str_starts_with((string)$r['tbl'], $this->prefix)) {
					$keys[$r['tbl'] . "\0" . $r['name']][] = $r;
				}
			}
			$q = fn (string $i): string => $this->db->quoteIdentifier($i);
			$checks = ['SET FOREIGN_KEY_CHECKS = 1'];
			foreach ($keys as $parts) {
				$join = implode(' AND ', array_map(fn (array $r): string => 'c.' . $q($r['col']) . ' = p.' . $q($r['rcol']), $parts));
				$set = implode(' AND ', array_map(fn (array $r): string => 'c.' . $q($r['col']) . ' IS NOT NULL', $parts));
				$checks[] = sprintf('SELECT COUNT(*) FROM %s c LEFT JOIN %s p ON %s WHERE %s AND p.%s IS NULL /* %s */',
					$q($parts[0]['tbl']), $q($parts[0]['rtbl']), $join, $set, $q($parts[0]['rcol']), $parts[0]['name']);
			}
			$this->db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
			return $checks;
		}
		if ($platform instanceof \Doctrine\DBAL\Platforms\SqlitePlatform) {
			$this->db->executeStatement('PRAGMA defer_foreign_keys = ON'); // checked at commit
		}
		return [];
	}

	/**
	 * Check the foreign keys again; a row that breaks one fails the restore here, before it commits.
	 * Statements add them back (PostgreSQL) or switch the checks on (MySQL); queries count the rows
	 * that point at nothing (MySQL) and must find none.
	 */
	private function resumeForeignKeys(array $statements): void {
		foreach ($statements as $sql) {
			try {
				if (str_starts_with($sql, 'SELECT ')) {
					$orphans = (int)$this->db->fetchOne($sql);
					if ($orphans > 0) {
						throw new \RuntimeException("$orphans rows point at nothing");
					}
				} else {
					$this->db->executeStatement($sql);
				}
			} catch (\Throwable $e) {
				throw new \RuntimeException('The restored data breaks a foreign key (' . $sql . '): ' . $e->getMessage(), 0, $e);
			}
		}
	}

	/** Same checksum as dump(): sha256 over the row lines in primary-key order. */
	public function checksum(string $table): array {
		$hash = hash_init('sha256');
		$rows = 0;
		foreach ($this->rowLines($this->db->createSchemaManager()->introspectTable($table)) as [, $line]) {
			hash_update($hash, $line . "\n");
			$rows++;
		}
		return ['rows' => $rows, 'sha256' => hash_final($hash)];
	}

	private function header(\Doctrine\DBAL\Schema\Table $table): string {
		[$columns, $binary, $pk] = $this->describe($table);
		return json_encode(['table' => $table->getName(), 'columns' => $columns, 'binary' => $binary, 'pk' => $pk], JSON_THROW_ON_ERROR);
	}

	/** @return array{0: list<string>, 1: list<string>, 2: list<string>} columns, binary columns, primary key */
	private function describe(\Doctrine\DBAL\Schema\Table $table): array {
		$columns = array_values(array_map(fn ($c) => $c->getName(), $table->getColumns()));
		$binary = [];
		foreach ($table->getColumns() as $c) {
			if ($c->getType() instanceof BlobType || $c->getType() instanceof BinaryType) {
				$binary[] = $c->getName();
			}
		}
		return [$columns, $binary, $table->getPrimaryKey()?->getColumns() ?? []];
	}

	/**
	 * Rows in primary-key order, after the key $after (resume), as [key, JSON line].
	 *
	 * @return \Generator<array{0: list<mixed>|null, 1: string}>
	 */
	private function rowLines(\Doctrine\DBAL\Schema\Table $table, ?array $after = null): \Generator {
		[$columns, $binary, $pk] = $this->describe($table);
		$binaryFlip = array_flip($binary);
		$q = fn (string $c) => $this->db->quoteIdentifier($c);
		$select = 'SELECT ' . implode(', ', array_map($q, $columns)) . ' FROM ' . $q($table->getName());
		if ($pk === []) {
			// Nextcloud requires primary keys; small odd tables are read in one query (not resumable).
			$count = (int)$this->db->fetchOne('SELECT COUNT(*) FROM ' . $q($table->getName()));
			if ($count > self::MAX_ROWS_WITHOUT_PK) {
				throw new \RuntimeException("Table {$table->getName()} has no primary key and $count rows; cannot dump it with bounded memory");
			}
			$batches = [$this->db->fetchAllNumeric($select)];
			$pkIdx = [];
		} else {
			$batches = $this->keysetBatches($select, $pk, $columns, $after);
			$pkIdx = array_map(fn ($c) => array_search($c, $columns, true), $pk);
		}
		foreach ($batches as $rows) {
			foreach ($rows as $row) {
				$key = $pkIdx === [] ? null : array_map(fn ($i) => $row[$i], $pkIdx);
				foreach ($columns as $i => $col) {
					if (is_resource($row[$i])) {
						$row[$i] = stream_get_contents($row[$i]);
					}
					if ($row[$i] !== null && isset($binaryFlip[$col])) {
						$row[$i] = base64_encode((string)$row[$i]);
					} elseif (is_string($row[$i]) && !mb_check_encoding($row[$i], 'UTF-8')) {
						// Keep invalid UTF-8 byte-exact instead of substituting characters.
						$row[$i] = ['$b' => base64_encode($row[$i])];
					} elseif (is_float($row[$i])) {
						// var_export uses serialize_precision (shortest exact form), not the lossy
						// 14-digit 'precision' of a (string) cast.
						$row[$i] = var_export($row[$i], true);
					} elseif (is_int($row[$i]) || is_bool($row[$i])) {
						// Normalise: drivers differ in returning numbers as int or string.
						$row[$i] = (string)(is_bool($row[$i]) ? (int)$row[$i] : $row[$i]);
					}
				}
				yield [$key, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
			}
		}
	}

	/** @return \Generator<list<list<mixed>>> */
	private function keysetBatches(string $select, array $pk, array $columns, ?array $after): \Generator {
		$q = fn (string $c) => $this->db->quoteIdentifier($c);
		$order = ' ORDER BY ' . implode(', ', array_map($q, $pk));
		$cols = array_map(fn ($c) => $q($c), $pk);
		$last = $after;
		while (true) {
			$sql = $select;
			$params = [];
			if ($last !== null) {
				// Row-value comparison (a, b) > (?, ?), supported by PostgreSQL, MySQL/MariaDB and SQLite.
				$sql .= ' WHERE (' . implode(', ', $cols) . ') > (' . implode(', ', array_fill(0, count($pk), '?')) . ')';
				$params = $last;
			}
			$rows = $this->db->fetchAllNumeric($sql . $order . ' LIMIT ' . self::BATCH, $params);
			if ($rows === []) {
				return;
			}
			yield $rows;
			if (count($rows) < self::BATCH) {
				return;
			}
			$end = end($rows);
			$last = array_map(fn ($c) => $end[array_search($c, $columns, true)], $pk);
		}
	}

	/** One INSERT with several rows (portable multi-row VALUES). */
	private function insertBatch(string $table, array $columns, array $rows, array $binary): int {
		$q = fn (string $c) => $this->db->quoteIdentifier($c);
		$placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
		$sql = 'INSERT INTO ' . $q($table) . ' (' . implode(', ', array_map($q, $columns)) . ') VALUES '
			. implode(', ', array_fill(0, count($rows), $placeholders));
		$params = [];
		$types = [];
		foreach ($rows as $row) {
			foreach ($columns as $i => $col) {
				$params[] = $row[$i];
				$types[] = isset($binary[$col]) ? \Doctrine\DBAL\ParameterType::LARGE_OBJECT : ($row[$i] === null ? \Doctrine\DBAL\ParameterType::NULL : \Doctrine\DBAL\ParameterType::STRING);
			}
		}
		$this->db->executeStatement($sql, $params, $types);
		return count($rows);
	}

	/**
	 * Exact state of every sequence owned by a dumped column (PostgreSQL), so a restore continues
	 * where the original left off, not at MAX(id)+1.
	 *
	 * @return array<string, array{table:string, column:string, last:int|null, called:bool}>
	 */
	private function sequenceStates(array $tables): array {
		if (!$this->db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
			return []; // MySQL/SQLite derive AUTO_INCREMENT from the data
		}
		$rows = $this->db->fetchAllAssociative(
			"SELECT c.table_name, c.column_name, pg_get_serial_sequence(quote_ident(c.table_name), c.column_name) AS seq
			 FROM information_schema.columns c
			 WHERE c.table_schema = current_schema() AND pg_get_serial_sequence(quote_ident(c.table_name), c.column_name) IS NOT NULL");
		$wanted = array_flip($tables);
		$result = [];
		foreach ($rows as $r) {
			if (!isset($wanted[$r['table_name']])) {
				continue;
			}
			$state = $this->db->fetchAssociative('SELECT last_value, is_called FROM ' . self::sequenceIdentifier($r['seq']));
			$result[$r['seq']] = ['table' => $r['table_name'], 'column' => $r['column_name'],
				// A never-used sequence reports last_value = start value with is_called = false.
				'last' => $state ? (int)$state['last_value'] : null,
				'called' => (bool)($state['is_called'] ?? false)];
		}
		return $result;
	}

	/** pg_get_serial_sequence() returns a catalog name like public.oc_x_id_seq or "My"."seq"; refuse anything else. */
	private static function sequenceIdentifier(string $name): string {
		if (!preg_match('/^("[^"]+"|[a-z_][a-z0-9_$]*)\.("[^"]+"|[a-z_][a-z0-9_$]*)$/i', $name)) {
			throw new \RuntimeException('Unexpected sequence name: ' . $name);
		}
		return $name;
	}

	private function restoreSequences(array $sequences): void {
		if (!$this->db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
			return;
		}
		foreach ($sequences as $info) {
			$seq = $this->db->fetchOne('SELECT pg_get_serial_sequence(quote_ident(?), ?)', [$info['table'], $info['column']]);
			if ($seq === false || $seq === null) {
				continue;
			}
			if ($info['last'] === null) {
				// Never used: start at 1 (or after the data, if rows were inserted with explicit ids).
				$max = (int)$this->db->fetchOne(sprintf('SELECT COALESCE(MAX(%s), 0) FROM %s',
					$this->db->quoteIdentifier($info['column']), $this->db->quoteIdentifier($info['table'])));
				$this->db->executeQuery('SELECT setval(CAST(? AS regclass), ?, false)', [$seq, $max + 1]);
			} else {
				$this->db->executeQuery('SELECT setval(CAST(? AS regclass), ?, ?)', [$seq, $info['last'], $info['called']],
					[\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::BOOLEAN]);
			}
		}
	}
}
