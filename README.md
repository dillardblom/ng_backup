# NG Backup

Encrypted, incremental backups of Nextcloud (database, configuration and data) with restore
from the web interface. Works without shell access: no external programs, no extra PHP
extensions beyond sodium. Runs on Nextcloud All-in-One, regular installations and hosted
Nextcloud.

**Status: in development (phase 1). Not ready for production use.** See `PLAN.md` for the design.

License: AGPL-3.0-or-later.

## How it works

- Backups go to a **location**: S3 (and S3-compatible storage), SFTP, WebDAV, FTP, Swift or a
  local path. NG Backup uses the connection code of Nextcloud's External storage app, but never
  mounts the location: it is only connected while a backup or restore runs, and it never
  appears in Files, for anyone.
- Data is cut into blocks, deduplicated, compressed and encrypted (XChaCha20-Poly1305) before it
  leaves the server, and streamed straight to the location: no local copy, no double disk space.
  Unchanged files are not read again; only new data is uploaded.
- The database is dumped through Nextcloud's own connection, inside one consistent transaction.
- Restores go through Nextcloud, so overwritten files keep their old content as a version and
  removed files go to the trash.

## Quick start

```
occ backup:key:init --label="Safe"                 # first passphrase, deletion delay (default 7 days)
occ backup:key:slot:add --label="CTO"              # optional second and third passphrase
occ backup:key:slot:add --label="Head of IT"
occ backup:key:kit --output=/root/ng_backup-kit.json
occ backup:key:confirm
occ backup:target:add offsite amazons3 -a amazons3::accesskey -o bucket=... -o key=... -o secret=...
occ backup:run
```

Or use **Administration settings → NG Backup**.

## The backup key, passphrases and the recovery kit

All backups of an installation are encrypted with one master key.

- The **server** keeps the master key, encrypted with the instance secret, so scheduled backups
  and restores work without anyone typing a passphrase. Any administrator can restore from the
  web interface. Consequence: whoever fully controls the server can read the backups; whoever
  only gets hold of the backup location cannot.
- For a **restore on a new server** (disaster recovery) you need the **recovery kit** and **one
  passphrase**. The kit contains the master key, encrypted separately with each passphrase.
- Up to **three passphrases** ("slots") can open the key, so one person leaving or dying does not
  lock the company out. A suggested setup:

  | Slot | Kept by |
  |---|---|
  | 1 | in the safe (sealed envelope) |
  | 2 | the CTO |
  | 3 | the head of IT |

  Every holder keeps a copy of the same recovery kit and only knows their own passphrase. Using
  a single slot is possible, but then that one passphrase is a single point of failure.
- When a holder leaves, give their slot a new passphrase (`occ backup:key:slot:replace`), download
  the new kit and confirm it. Note: an old kit together with the old passphrase still opens the
  same master key; to revoke it completely, start new locations with a new key.
- Downloading the kit, and every change to the slots, requires the administrator's password, is
  written to the audit log and is announced to all administrators.

## Protection against ransomware and a compromised server

A backup is only useful if an attacker cannot destroy it. An attacker with administrator access
to Nextcloud could try to remove old restore points so that only infected data remains.
NG Backup makes this hard and visible, but **software alone can never guarantee it**: the
location must refuse deletions itself.

What NG Backup does:

- **Deletion delay.** Removed restore points first go to a trash on the location and are only
  deleted after the delay chosen at installation (default 7 days, cannot be changed afterwards).
  Within that time any administrator can bring them back (`occ backup:trash:restore`, or in the
  web interface).
- **Notifications to all administrators** when restore points are removed, the retention is
  changed, append-only is turned off, a location is removed or the recovery kit is downloaded,
  and an entry in the audit log (admin_audit).
- **Append-only locations** (`occ backup:target:append-only NAME on`): NG Backup never deletes
  anything there; old data is cleaned up by the location's own lifecycle rules.
- Removing a location in NG Backup only removes the connection. The backups on the location are
  not touched and nothing keeps cleaning up in the background.

What you should do on the location (strongly recommended):

- **S3: enable Object Lock** on the bucket, in **compliance mode**, with a default retention of
  for example **90 days**, plus versioning. Then nobody, not even with the server's credentials,
  can delete or overwrite backups younger than 90 days. Combine with an append-only location in
  NG Backup and a lifecycle rule that removes old versions after the lock expires.
  With Object Lock, a deletion by NG Backup (or by an attacker through it) only adds a *delete
  marker*: the locked data stays and can be brought back from the bucket's object versions until
  the lock expires. Tested with an S3-compatible server; still to be verified on AWS itself, which
  requires a checksum on uploads to locked buckets.
- Use **credentials without delete rights** for the backup location where the storage supports it.
- **SFTP / storage boxes:** enable the provider's own snapshots (e.g. daily, kept 30-90 days) and
  give the backup user access to its own folder only, without a shell.
- Keep at least one location **off-site**, on a different network than the Nextcloud server.

## Restore

- **Files and folders** of a user: in the web interface or `occ backup:restore`, into a new folder
  ("Restored <date>"), merged into the original place, or replacing it.
- **Raw restore** of any path (for example `config/`) to a local directory: `occ backup:restore ... --to-directory=DIR`.
- **Whole server** (disaster recovery onto a fresh installation): planned for phase 2.

## Storage backend experiences

Notes from actually running a backend, beyond what's in files_external's own documentation.
Contributions welcome via PR — add what you ran into with your own provider.

- **Hetzner Storage Box, sub-account:** the SFTP backend's `root` option must be `home`, not `/`.
  A sub-account's SFTP login lands in `/home` (confirmed via `pwd()` after login); writing to the
  literal chroot root (`/`) fails with a generic `NET_SFTP_STATUS_FAILURE` from the SFTP server,
  while the same write under `home` (or a path below it) succeeds. This affects any backend option
  form that lets you set a path relative to the account's root, not just ng_backup. Not yet
  verified whether this also applies to a Hetzner Storage Box **main** account (only tested with a
  sub-account so far) — if you've tried a main account, please say so in a PR.

## Commands

| Command | Purpose |
|---|---|
| `backup:key:init`, `backup:key:kit`, `backup:key:confirm` | create the key, recovery kit, confirmation |
| `backup:key:slots`, `backup:key:slot:add/replace/remove` | passphrase slots |
| `backup:target:backends/add/list/remove/append-only` | backup locations |
| `backup:run`, `backup:status`, `backup:list` | back up, status, restore points |
| `backup:browse`, `backup:restore` | browse and restore |
| `backup:retention`, `backup:prune`, `backup:trash`, `backup:trash:restore` | retention, cleanup, trash |
