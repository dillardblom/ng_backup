# Phase 0 — results (2026-10-01)

All spikes run on the standard `nextcloud:34-apache` image (PHP 8.5, sodium 1.0.18), without
`pg_dump`, `mysqldump`, the `pgsql`/`mysqli` extensions, rclone or a shell on the target.

| Spike | What | Result |
|---|---|---|
| A `01-stream-crypto.php` | Streaming encryption (secretstream XChaCha20-Poly1305), key wrapping (Argon2id) | 1 GiB round trip with `memory_limit=64M`: peak < 2 MiB, 160 MiB/s encrypt, 122 MiB/s decrypt, 0.03 % overhead. Tampering, truncation (also at a frame boundary), trailing data, wrong key/AD/passphrase rejected. |
| B `02-repository.php` | Repository format: 4 MiB blobs, deflate, packs, index, snapshots, dedup, incremental, restore | 390 MiB tree, peak 54 MiB. Unchanged run: 0.06 s, nothing read or uploaded. 1 MiB change in a 300 MiB file: one blob uploaded. Full and partial restore bit-identical, restore peak 17 MiB. Tampered pack detected. |
| C `03-db-dump.php` | Logical DB dump/restore via the Nextcloud connection (PostgreSQL) | 300k-row table with bytea (110 MB): dump 6.3 s, peak 50 MiB, 94 MiB JSON → 20 MiB stored. Unchanged second dump stores nothing (per-table blob streams). Restore 46 s: every table checksum-identical, all 110 sequences correct. |
| D `04-external-storage.php` | Repository on files_external storages without a mount | S3 (SeaweedFS) and SFTP: 120 MiB backup ~6 s, unchanged run uploads nothing, restore bit-identical, no row in `external_mounts`, /tmp stayed at 12 KiB (no local copy). Also works with files_external disabled when its classes are loaded. |
| E `05-user-migration.php` | Per-user backup/restore through user_migration with own `IExportDestination`/`IImportSource` | Migrators: calendar, contacts, trashbin, account, files. Export 0.8 s, unchanged re-export stores nothing. After deleting the user, import restores account, files (bit-identical), event and contact. |
| F `06-resumable.php` | Time-boxed, resumable steps in separate processes | 200 MiB file resumed mid-file across steps; with 6 random `kill -9` the run still completes and restores bit-identically; peak 29–53 MiB per step. |

## Findings that change or refine the plan

1. **Restore over an existing user (user_migration)** is not clean: contacts are duplicated and a
   `migrated-contact_birthdays` calendar is added; calendars/address books come back as
   `migrated-*` with new object URIs (clients resync; shares/subscriptions of those calendars
   break). → Per-user restore in phase 2 needs its own strategy: restore into a fresh account
   (after deleting or renaming the existing one, with a safety export first), or restore
   calendars/contacts at DAV level ourselves and use user_migration only for the rest.
2. **files_external without enabling it:** its classes can be loaded while the app is disabled
   (`OC_App::loadApp`, private API). Choice to make: rely on that (nothing visible to anyone), or
   require files_external enabled with user mounting off (public path, a bit more visible).
3. **Peak memory on S3/SFTP backups is 85–89 MiB** (local target 54 MiB): the files_external
   upload path buffers about one extra pack. Fine within 512 MB; to look at in phase 1
   (smaller packs on hosted, or our own S3 multipart adapter).
4. **DB restore speed:** row-by-row INSERT (46 s for 300k rows). Phase 1: multi-row INSERT,
   skip tables whose checksum matches the dump, disable/re-enable nothing (keep it portable).
5. **Fixed-size chunking:** a row change in a big table (e.g. `filecache`) or an insertion in a big
   file shifts all following blobs. Phase 1: content-defined chunking (FastCDC) for files and dumps.
6. **Index and snapshot entries are held in memory** in phase 0. Phase 1: index as a cache table in
   the Nextcloud database (rebuildable from `index/` and the pack headers), streaming comparison
   with the parent snapshot.
7. **user creation on import needs > 128 MB** (password_policy word list) — document 512 MB as minimum.
8. **Snowflake ids:** since NC 33 some tables (e.g. `oc_jobs`) have no sequence; the dump/restore
   handles that (no special case needed), the test just must not assume sequences everywhere.
9. **files_external S3 `writeStream()` closes the stream it is given** → `IBackend::put()` owns and
   closes the stream (contract documented).

## Not done in phase 0

- MySQL/MariaDB and SQLite runs of spike C (PostgreSQL only so far).
- Real hosted environment (webcron/AJAX cron, `max_execution_time`); spike F simulates it.
- Restore of the database while Nextcloud is running (spike C restored with Nextcloud idle).
- Schema introspection still uses the Doctrine connection behind `IDBConnection` (private API).
