# Changelog

All notable changes to NG Backup are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning follows the stages in
`PLAN.md` section 9. The `<version>` in `appinfo/info.xml` is plain SemVer-numeric on purpose:
the app store accepts a `-beta` suffix, but Nextcloud servers then only offer the release on the
beta/daily update channel, which would hide the app from most admins. The stability stage is
instead carried by the app description, the git tag, the release, and this file.

## [Unreleased]

### Fixed
- A backup failed with "cannot execute INSERT in a read-only transaction" on PostgreSQL as soon
  as the database dump filled a whole pack (32 MiB): the pack's index cache row was written
  inside the read-only dump transaction. On other databases that row was rolled back silently.
- Backups to a remote location slowed down as the repository grew: every backup step listed the
  repository's index with a round trip per file. Over SFTP to a Hetzner Storage Box a 60 GiB
  repository already lost a large part of each step to it; backing up continuously
  (`occ backup:run`) went from 45 KiB/s to 5.8 MB/s with the fix.

## [0.9.2-beta.3] - 2026-10-08

Bugfix release.

### Added
- README: notes on using a Hetzner Storage Box over WebDAV and SMB.

### Fixed
- Adding a backup location in the web interface failed with "Request failed with status code 403"
  when the login was more than 30 minutes old: the form did not ask for password confirmation.
- Settings page: the "Backup and restore" / "Settings" tabs line up with the sections below them.
- Backups: the runs table scrolls horizontally on narrow screens instead of running off the page.
- `occ backup:verify --deep`: a blob that could not be read (network or storage error) was reported
  as "corrupted or tampered". Reads are now retried, and a read that keeps failing is reported
  separately as unreadable, with the error (run the check again; if the same blobs stay unreadable,
  their pack may be damaged or truncated). Only a blob that was read but fails decryption or its
  hash check counts as corrupted. Found on a Hetzner Storage Box over WebDAV, where the blob
  turned out to be intact.

## [0.9.1-beta.2] - 2026-10-07

Second beta, the first release in the Nextcloud app store (signed).

### Added
- App store listing: screenshots, website, repository and documentation link in `appinfo/info.xml`.

### Fixed
- Restore: the "new folder" option showed `"Restored "` without the date pattern.
- Restore: the restore-point dropdown cut off the time.
- Settings: the passphrase actions are on one line instead of stacked.
- `docs/HOWTO.md`: the SFTP example used a non-existent auth mechanism (`sftp::password`
  instead of `password::password`).

## [0.9.0-beta.1] - 2026-10-07

First beta. Tested thoroughly and usable **alongside** your current backup, but **not yet a
drop-in replacement** for it. We test what we can ourselves (Nextcloud 32–35, SQLite, MariaDB and
PostgreSQL, S3, SFTP, FTP, SMB and NFS), but we need field experience from other setups: please
report what works and what doesn't, especially on large instances and on storage providers not
listed in the README.

### Added
- Disaster recovery: `occ backup:key:import` and `occ backup:restore:full` restore the database,
  data directory and config onto a fresh installation of the same major version.
- Per-user backup and restore via user_migration: `occ backup:user:backup` and
  `occ backup:user:restore` (replace the account after a safety export, or restore as
  `<uid>-bak`), also onto a server where the user does not exist yet. The commands ask before
  installing user_migration (or take `--install-user-migration`).
- Checksum audit: `occ backup:verify`, shallow or `--deep`, for snapshots and per-user exports.
- Retention for per-user exports: `occ backup:retention --user-last=N`.
- FTP and SMB covered by the integration tests; NFS through the `local` backend on a mounted share.
- `docs/HOWTO.md`: step by step from setup to a tested restore.
- Warnings when a backup finishes with unreadable files or folders, or with files that kept
  changing while being read (log, `occ`, notification, snapshot).

### Changed
- `backup:restore:full` refuses on an installation with more than one user and asks first;
  `backup:user:restore --mode=replace` asks before deleting an existing account (`--force` for
  scripts in both cases).
- A failed scheduled backup is retried at most three times a day, an hour apart.
- The recovery kit carries the deletion delay; the kit file is created owner-only.
- Adding a location through the web API needs password confirmation.

### Fixed
- Exports of users whose id contains `.part-` could be deleted by cleanup on remote locations.
- Cleanup no longer frees the data of a per-user export whose manifest is missing.
- Crash safety of moving snapshots to and from the trash, of catalog writes by concurrent
  backups and per-user exports, and of short uploads.
- A file that keeps changing no longer makes a backup restart forever.
- Long restores and exports keep their lock on the location alive.
- Floating-point database columns are dumped exactly.

### Known limitations
See "Known limitations" in the README: memory use on very large instances, never two servers
writing to one location, removing a passphrase slot does not revoke access, no rollback detection
before a disaster recovery, and a few smaller points. None of them block the road to 1.0.0; they
are on the backlog in `PLAN.md`.

## [0.1.0-alpha.1] - 2026-10-05

First public alpha. Feature-complete for phase 1: scheduled, encrypted, incremental backups, and
restore of files and folders. **Not for production use** — test on a copy of production (dev,
test, staging). Full disaster recovery (`backup:restore:full` on a fresh installation) is phase 2
and not implemented yet.

### Added
- Encrypted (XChaCha20-Poly1305), content-addressed, deduplicated, incremental backups, streamed
  from source to destination without a local copy of the data.
- Backup locations via files_external's own backend/auth classes, without ever mounting the
  location: S3 (and compatible services), SFTP, WebDAV, FTP, Swift, or a local path.
- A recovery kit with up to three passphrase slots, mandatory download-and-confirm before the
  first backup runs, and an optional encrypted copy by email.
- Resumable, time-boxed background jobs (database dump, file walk, upload), so a run survives
  short PHP time limits and a crash is resumed, never restarted, without leaving the instance in
  maintenance mode.
- Database dump and restore without external tools, via Nextcloud's own `IDBConnection`/DBAL,
  streamed through the same pipeline as file data.
- Restore of files and folders via the web interface and `occ`, into a new folder, merged, or
  replacing the original (with the previous content going to the trash).
- Retention policy (last/daily/weekly/monthly), prune with pack repacking, and a snapshot trash
  with a configurable deletion delay.
- Append-only locations and documented recommendations against ransomware and a compromised
  server (object lock, delete-less credentials, off-site copies).
- Admin settings page (locations, schedule, retention, key management) and a JSON API.
- Notifications and an admin setup check for a failed or overdue backup.
- CI: unit tests on PHP 8.2–8.4, and an end-to-end backup→restore round trip on Nextcloud 32–35 ×
  SQLite/MariaDB/PostgreSQL, and against real S3 and SFTP backends (not just a local path).

### Known limitations
- No full/disaster-recovery restore yet (phase 2).
- No per-user backup/restore via user_migration yet (phase 2).
- No FTP/SMB coverage in CI yet, and no verification/checksum-audit command yet (phase 2).
- See the "Storage backend experiences" section of the README for provider-specific quirks found
  so far (e.g. Hetzner Storage Box sub-accounts).

[0.9.2-beta.3]: https://github.com/dillardblom/ng_backup/releases/tag/v0.9.2-beta.3
[0.9.1-beta.2]: https://github.com/dillardblom/ng_backup/releases/tag/v0.9.1-beta.2
[0.9.0-beta.1]: https://github.com/dillardblom/ng_backup/releases/tag/v0.9.0-beta.1
[0.1.0-alpha.1]: https://github.com/dillardblom/ng_backup/releases/tag/v0.1.0-alpha.1
