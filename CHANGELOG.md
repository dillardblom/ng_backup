# Changelog

All notable changes to NG Backup are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning follows the stages in
`PLAN.md` section 9. The `<version>` in `appinfo/info.xml` is plain SemVer-numeric on purpose:
the app store accepts a `-beta` suffix, but Nextcloud servers then only offer the release on the
beta/daily update channel, which would hide the app from most admins. The stability stage is
instead carried by the app description, the git tag, the release, and this file.

## [Unreleased]

### Added
- The admin page asks for feedback, with a link to a short "Experience report" form on GitHub:
  which location, setup and size you used, and how it went.

### Changed
- Dates and times in the web interface follow the locale in the user's Nextcloud settings instead
  of the browser's language, so a 24-hour locale shows 16:00 rather than 4:00 PM.
- The list of backup runs (web interface and `occ backup:status`) shows the runs of the last 7
  days, those still running, and at least the 5 newest; a failed run stays visible for that week.
  `occ backup:status --all` and "Show older runs" in the web interface list all of them.
- `occ backup:status` and `occ backup:verify` show durations of a day or more with the days
  ("1d 12:10:13" instead of "12:10:13").

### Fixed
- With system cron, a running backup moved forward only every other cron run: Nextcloud
  skipped the background job when cron came a few seconds before its 5-minute interval was
  over, so a backup was idle 6 of every 10 minutes. The job now runs at every cron run.
- Photos, video and other compressed data are no longer deflated only to throw the result away:
  a quick test on three small slices of each block skips it. Compressing was the slowest part of
  a backup (about 24 MiB/s).
- Checking a backup on an S3 location (`occ backup:verify`) took hours on a large repository:
  the index files were downloaded one at a time, at about two a second. They are now downloaded
  16 at a time: on a production instance (27,585 index files, 1 TB) the check went from about
  4 hours to 3.5 minutes.
- Writing the snapshot at the end of a long backup to S3 took a quarter of an hour without any
  progress shown: it read back the file list of every step one at a time. These reads are now
  done 16 at a time on S3 (96 reads: 82 seconds before, 2 now), blobs that follow each other in
  a pack are read at once on every location, the snapshot is written in a step of its own, and
  the run shows "commit" while it does so.

## [0.9.6-beta.7] - 2026-10-14

Bugfix release for SMB locations, with `occ backup:cancel` to stop a backup that does not get
anywhere. Tested on a production instance: a first full backup of 151,021 files (807 GiB) to OVH
S3 completed, and a backup to a Hetzner Storage Box over SMB that had slowed to 12 GiB in 35
hours ran at about 5.6 MiB/s after the update, while the S3 backup was still reading.

### Added
- The list of backup runs (web interface and `occ backup:status`) shows the total size of each
  backup next to what it uploaded: an incremental backup of 1 TB that uploads 800 MB now shows
  both. `occ backup:status` shows sizes in GiB where that reads better than MiB.
- `occ backup:cancel <run>` stops a running backup; `occ backup:status` shows the run numbers.
  No new step of the run starts, the step in progress finishes (usually within a minute), then
  the process working on the run (`occ backup:run` or cron) stops. What it already stored is
  removed by the next cleanup. A backup started by an earlier version is stopped as well: after
  two minutes it is marked cancelled while it works, and its process stops at the end of the
  step.

### Fixed
- Backups to SMB locations, and to other slow locations with many packs, slowed to a crawl: each
  30-second step opened the location again, and reading the list of index files took longer
  than the step itself. Each step then stored a single 4 MiB pack, which made the list longer
  still. A first full backup of 0.8 TB to a Hetzner Storage Box over SMB managed 12 GiB in 35
  hours. `occ backup:run` and system cron now open the location once per run, opening no longer
  counts towards the step's time, and SMB folders are listed in one request instead of one per
  file. A backup to an SMB location that is not getting anywhere keeps running in its old
  process after the update: update first, then stop it with `occ backup:cancel <run>` and start
  it again. The new backup reuses the packs the old one stored.

## [0.9.5-beta.6] - 2026-10-12

Bugfix release for SFTP locations and large repositories. Tested on a production instance with a
1 TB repository on a Hetzner Storage Box over SFTP: a check in 5.5 minutes, a deep check of all
316,529 blobs (815 GiB) in 11 hours, and an incremental backup in under 8 minutes.

### Added
- Progress of a running backup in the web interface and `occ backup:status`, e.g.
  "running (files) 68%". The total is the size of the previous snapshot; for a first backup it
  is estimated from Nextcloud's file cache (users' files, versions and trash) and shown as
  "~68%".
- Edit a backup location (web interface and `occ backup:target:edit`): change the password, host,
  folder, connection type or name without losing its history. Before this the only option was a
  new location, which starts an empty repository and, with a retention lock at the provider,
  keeps paying for the old one. The new settings are only saved when they lead to the same
  repository; stored passwords are never sent to the browser, and an empty password field keeps
  the stored one. Administrators are notified of the change.
- `occ backup:verify` reports how far it is every 30 seconds, and `occ backup:status` shows the
  progress of a running check and how the last check of each location ended. On a large
  repository a check takes hours, a deep one days.

### Changed
- Checking and opening a large repository over SFTP is much faster: the index files (one per
  pack, 27,547 for 1 TB) are downloaded 32 at a time instead of one after the other, 114 instead
  of 6 files per second to a Hetzner Storage Box (about 4 minutes instead of 77 for 1 TB). A check
  of a repository with more than 256 packs also lists the pack folders (256 requests) instead of
  asking for every pack.
- App store listing: the author's e-mail address is shown.
- App store listing: the homepage link points to the GitHub repository instead of back to the
  app store page itself.
- The notification for a location without a successful backup read "No successful backup to …
  since never" when there had never been one; it now reads "No successful backup to … yet".

### Fixed
- 0.9.4 could not open a repository at the root of an SFTP location (empty path): its faster
  SFTP reads sent their first request before phpseclib had opened the SFTP channel, which only
  happens on the first SFTP call after login. Every backup then failed with "No repository at
  this location". The channel is now opened first.
- A check (`occ backup:verify`) did not renew its hold on the location while it downloaded the
  index; on a large repository that takes longer than the hold lasts, so a weekly cleanup could
  have started during the check.
- A long first backup could end as failed although its snapshot was complete: when its final
  step took longer than the step's lease (150 s with `occ backup:run`), the background job took
  the run over, finished it a second time and failed on the snapshot it had already recorded,
  overwriting the result. The lease now lasts at least 15 minutes, a step writes the run only if
  no other process wrote it since the step loaded it (in one database statement), and recording
  a snapshot that is already recorded is no longer an error.
- `occ backup:verify` on a large repository failed with "SFTP connection failed while opening
  snapshots/…" or "Not found: snapshots/…". A Hetzner Storage Box drops an SFTP connection that
  carried a lot of data (some 40 MB) and then stayed quiet for a few minutes, and the check went
  quiet after downloading the index: it refreshed the local index cache, first in 29 minutes (a
  pass over all blobs for every pack: 27,547 packs, 400,000 blobs for 1 TB), then still in more
  than 6 minutes (a database transaction per pack on PostgreSQL). Nextcloud's SFTP storage keeps
  using a dropped connection, on which every file looks missing. Now:
  - an SFTP connection that was not used for a minute is checked first, and one that turns out
    to be dead after a failed request is replaced, once per request; uploads, moves and deletes
    are only checked first, never repeated;
  - the index cache is written in one transaction (27,547 packs in 11 seconds on a test server,
    against about a minute row by row);
  - after a check the cache holds the index files as they are: before, a blob that was in two
    packs was only cached for one of them.

## [0.9.4-beta.5] - 2026-10-09

Bugfix release: faster restores.

### Fixed
- Restores and `occ backup:verify --deep` fetched every blob with its own request, so on a
  remote location they ran far below the speed of the connection. The blobs of a pack are now
  read from one open stream, in the order they were written. Restoring 86 MB in 202 files from a
  Hetzner Storage Box over SMB went from 0.78 MB/s to 4.2 MB/s, the speed of reading the packs
  as plain files over that connection.
- Reading from an SFTP location waited a full round trip for every 256 KiB, whatever the line
  speed. Reads now keep up to 8 requests in flight. Restoring 1.97 GB in 500 files from a Hetzner
  Storage Box (47 ms away) took 694 s before the fix above, 499 s with it and 142 s with both
  (2.8 → 3.9 → 13.9 MB/s); a full 51 GB restore ran at 13.0 MB/s. When a server error
  interrupts such a read, it goes on from the same position with Nextcloud's own SFTP stream;
  after three failing files in a row, or on a Nextcloud whose SFTP library lacks what this needs,
  only Nextcloud's own stream is used.
- README: a WebDAV location on a slow upload needs a higher `davstorage.request_timeout`.

## [0.9.3-beta.4] - 2026-10-08

Bugfix release.

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

[0.9.6-beta.7]: https://github.com/dillardblom/ng_backup/releases/tag/v0.9.6-beta.7
[0.9.5-beta.6]: https://github.com/dillardblom/ng_backup/releases/tag/v0.9.5-beta.6
[0.9.4-beta.5]: https://github.com/dillardblom/ng_backup/releases/tag/v0.9.4-beta.5
[0.9.3-beta.4]: https://github.com/dillardblom/ng_backup/releases/tag/v0.9.3-beta.4
[0.9.2-beta.3]: https://github.com/dillardblom/ng_backup/releases/tag/v0.9.2-beta.3
[0.9.1-beta.2]: https://github.com/dillardblom/ng_backup/releases/tag/v0.9.1-beta.2
[0.9.0-beta.1]: https://github.com/dillardblom/ng_backup/releases/tag/v0.9.0-beta.1
[0.1.0-alpha.1]: https://github.com/dillardblom/ng_backup/releases/tag/v0.1.0-alpha.1
