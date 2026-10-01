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

	public function __construct(
		private Connection $db,
		private string $prefix,
	) {
	}

	/**
	 * @return array{tables: array<string, array{blobs: list<string>, rows: int, bytes: int, sha256: string}>, provider: string}
	 */
	public function dump(Repository $repo, array &$stats): array {
		$sm = $this->db->createSchemaManager();
		$tables = array_values(array_filter($sm->listTableNames(), fn (string $t) => str_starts_with($t, $this->prefix)));
		sort($tables);

		$this->db->setTransactionIsolation(TransactionIsolationLevel::REPEATABLE_READ);
		$this->db->beginTransaction();
		try {
			if ($this->db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
				$this->db->executeStatement('SET TRANSACTION READ ONLY');
			}
			$result = [];
			foreach ($tables as $table) {
				$writer = $repo->blobWriter($stats);
				$hash = hash_init('sha256');
				$rows = 0;
				foreach ($this->tableLines($sm->introspectTable($table)) as $kind => $line) {
					$writer->write($line . "\n");
					if ($kind === 'row') {
						hash_update($hash, $line . "\n");
						$rows++;
					}
				}
				$result[$table] = ['blobs' => $writer->finish(), 'rows' => $rows, 'bytes' => $writer->bytes(), 'sha256' => hash_final($hash)];
			}
		} finally {
			$this->db->rollBack();
		}
		$repo->flushPacks();
		return ['tables' => $result, 'provider' => get_class($this->db->getDatabasePlatform())];
	}

	/**
	 * Replace the contents of every table in the dump. Tables that exist but are not in the dump
	 * are left alone (e.g. a newer app); the caller decides whether that is acceptable.
	 *
	 * @param array{tables: array<string, array{blobs: list<string>, rows: int}>} $manifest
	 */
	public function restore(Repository $repo, array $manifest): int {
		$sm = $this->db->createSchemaManager();
		$existing = array_flip($sm->listTableNames());
		$total = 0;
		$this->db->beginTransaction();
		try {
			foreach ($manifest['tables'] as $table => $info) {
				if (!isset($existing[$table])) {
					throw new \RuntimeException("Table $table from the backup does not exist here (different app versions?)");
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
						$row[$col] = ($value !== null && isset($binary[$col])) ? base64_decode($value, true) : $value;
					}
					$batch[] = $row;
					if (count($batch) >= 500) {
						$total += $this->insertBatch($table, $batch, $binary);
						$batch = [];
					}
				}
				if ($batch !== []) {
					$total += $this->insertBatch($table, $batch, $binary);
				}
				$this->resetSequences($table, $sm->introspectTable($table));
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $total;
	}

	/** Same checksum as dump(): sha256 over the row lines in primary-key order. */
	public function checksum(string $table): array {
		$sm = $this->db->createSchemaManager();
		$hash = hash_init('sha256');
		$rows = 0;
		foreach ($this->tableLines($sm->introspectTable($table)) as $kind => $line) {
			if ($kind === 'row') {
				hash_update($hash, $line . "\n");
				$rows++;
			}
		}
		return ['rows' => $rows, 'sha256' => hash_final($hash)];
	}

	/** @return \Generator<string, string> 'header' => line, then 'row' => line ... */
	private function tableLines(\Doctrine\DBAL\Schema\Table $table): \Generator {
		$columns = array_values(array_map(fn ($c) => $c->getName(), $table->getColumns()));
		$binary = [];
		foreach ($table->getColumns() as $c) {
			if ($c->getType() instanceof BlobType || $c->getType() instanceof BinaryType) {
				$binary[] = $c->getName();
			}
		}
		$pk = $table->getPrimaryKey()?->getColumns() ?? [];
		yield 'header' => json_encode(['table' => $table->getName(), 'columns' => array_values($columns), 'binary' => $binary, 'pk' => $pk], JSON_THROW_ON_ERROR);

		$binaryFlip = array_flip($binary);
		$q = fn (string $c) => $this->db->quoteIdentifier($c);
		$select = 'SELECT ' . implode(', ', array_map($q, $columns)) . ' FROM ' . $q($table->getName());

		if ($pk === []) {
			// Nextcloud requires primary keys; fall back to one query for odd tables.
			$batches = [$this->db->fetchAllNumeric($select)];
		} else {
			$batches = $this->keysetBatches($select, $pk, $columns);
		}
		foreach ($batches as $rows) {
			foreach ($rows as $row) {
				foreach ($columns as $i => $col) {
					if (is_resource($row[$i])) {
						$row[$i] = stream_get_contents($row[$i]);
					}
					if ($row[$i] !== null && isset($binaryFlip[$col])) {
						$row[$i] = base64_encode((string)$row[$i]);
					} elseif (is_int($row[$i]) || is_float($row[$i]) || is_bool($row[$i])) {
						// Normalise: drivers differ in returning numbers as int or string.
						$row[$i] = (string)(is_bool($row[$i]) ? (int)$row[$i] : $row[$i]);
					}
				}
				yield 'row' => json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
			}
		}
	}

	/** @return \Generator<list<list<mixed>>> */
	private function keysetBatches(string $select, array $pk, array $columns): \Generator {
		$q = fn (string $c) => $this->db->quoteIdentifier($c);
		$order = ' ORDER BY ' . implode(', ', array_map($q, $pk));
		$pkIdx = array_map(fn ($c) => array_search($c, $columns, true), $pk);
		$last = null;
		while (true) {
			$sql = $select;
			$params = [];
			if ($last !== null) {
				// Row-value comparison (a, b) > (?, ?), supported by PostgreSQL, MySQL/MariaDB and SQLite.
				$sql .= ' WHERE (' . implode(', ', array_map($q, $pk)) . ') > (' . implode(', ', array_fill(0, count($pk), '?')) . ')';
				$params = $last;
			}
			$rows = $this->db->fetchAllNumeric($sql . $order . ' LIMIT ' . self::BATCH, $params);
			if ($rows === []) {
				return;
			}
			yield $rows;
			$end = end($rows);
			$last = array_map(fn ($i) => $end[$i], $pkIdx);
			if (count($rows) < self::BATCH) {
				return;
			}
		}
	}

	private function insertBatch(string $table, array $rows, array $binary): int {
		foreach ($rows as $row) {
			$types = [];
			foreach ($row as $col => $_) {
				$types[$col] = isset($binary[$col]) ? \Doctrine\DBAL\ParameterType::LARGE_OBJECT : \Doctrine\DBAL\ParameterType::STRING;
			}
			$this->db->insert($this->db->quoteIdentifier($table), array_combine(array_map(fn ($c) => $this->db->quoteIdentifier($c), array_keys($row)), $row), array_values($types));
		}
		return count($rows);
	}

	private function resetSequences(string $table, \Doctrine\DBAL\Schema\Table $schema): void {
		if (!$this->db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
			return; // MySQL/SQLite derive AUTO_INCREMENT from the data
		}
		foreach ($schema->getColumns() as $column) {
			if ($column->getAutoincrement()) {
				$this->db->executeStatement(sprintf(
					"SELECT setval(pg_get_serial_sequence('%s', '%s'), COALESCE((SELECT MAX(%s) FROM %s), 0) + 1, false)",
					$table, $column->getName(), $this->db->quoteIdentifier($column->getName()), $this->db->quoteIdentifier($table)));
			}
		}
	}
}
