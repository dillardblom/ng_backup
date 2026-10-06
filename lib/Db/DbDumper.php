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
 * - The schema itself is not dumped: it comes from Nextcloud's migrations on the instance that is
 *   restored into (same Nextcloud and app versions). The dump records those versions.
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
					'bytes' => $state['bytes'], 'sha256' => hash_final($hash)];
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
	 * @param array{tables: array<string, array{blobs: list<string>, rows: int}>} $manifest
	 */
	public function restore(Repository $repo, array $manifest, ?array &$report = null, ?callable $heartbeat = null): int {
		$sm = $this->db->createSchemaManager();
		$existing = array_flip($sm->listTableNames());
		$total = 0;
		$report = ['skipped' => [], 'restored' => []];
		$this->db->beginTransaction();
		try {
			foreach ($manifest['tables'] as $table => $info) {
				if (!isset($existing[$table])) {
					throw new \RuntimeException("Table $table from the backup does not exist here (different app versions?)");
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
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $total;
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
