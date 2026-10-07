# NG Backup

Encrypted, incremental backups of Nextcloud (database, configuration and data) with restore
from the web interface. Works without shell access: no external programs, no extra PHP
extensions beyond sodium for ng_backup itself. Runs on Nextcloud All-in-One, regular
installations and hosted Nextcloud. (Choosing SMB as a storage backend is the one exception:
see "Storage backend experiences" below — that requirement comes from files_external, not from
ng_backup, and applies to any Nextcloud app that writes to an SMB share.)

**Status: beta (0.9.2).** Tested thoroughly, usable alongside your current backup, but not yet a
drop-in replacement for it. See "Known limitations" below, `PLAN.md` for the design and
`docs/HOWTO.md` for a step-by-step guide from setup to a tested restore.

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
occ backup:key:kit --output=/var/tmp/ng_backup-kit.json
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
  same master key; to revoke it completely, start new locations with a new key. This is noted on
  the backlog and will be addressed in a future version (key rotation).
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
- **Whole server** (disaster recovery onto a fresh installation): `occ backup:restore:full`, see the how-to.
- **One user** (account, settings, files, calendars, contacts via user_migration): `occ backup:user:backup` and
  `occ backup:user:restore`, replacing the account or restoring it as `<uid>-bak`. See the how-to.

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

- **Hetzner Storage Box over WebDAV and SMB (sub-accounts, 2026-10-07):** both work end to end
  (backup, incremental run, restore of a folder, verify), tested from outside the Hetzner network
  with about 66 MiB of data. WebDAV: host `https://uXXXXX-subN.your-storagebox.de`, and the `root`
  must be a folder that already exists (or empty): WebDAV does not create missing parent folders,
  so a root that doesn't exist yet fails `backup:target:add` with `Sabre\HTTP\ClientHttpException:
  Conflict`. SMB: share = the sub-account name, port 445 must be reachable from your server (it was
  from a home connection here, but some providers block it), and the `smbclient` PECL extension is
  required (see the SMB entry below). Both have a noticeable fixed cost per run: an incremental run
  that uploads nothing took about 20 s over WebDAV and 50 s over SMB. A deep verify is slow over
  remote storage, since each blob is fetched with its own request (19 minutes for 66 MiB over
  WebDAV); run it occasionally, not after every backup.

- **FTP, server-dependent absolute-path handling:** files_external's FTP backend always builds
  absolute paths (e.g. `MKD /repo`) for the configured root, never relative ones. `atmoz/sftp`-style
  `vsftpd` test servers (chrooted) can reject that with a generic "create directory operation
  failed", even though a plain `ftp_mkdir()` with a *relative* path on the same server succeeds;
  `pure-ftpd` accepts the absolute form fine. If a working FTP server rejects ng_backup's writes,
  try a different FTP server implementation before assuming the backend itself is broken.

- **SMB/CIFS *lists* as available with just the `smbclient` CLI binary, but does not actually
  work for ng_backup without the `smbclient` PECL extension too.** files_external falls back to a
  shell-wrapped `smbclient` process when the native extension is missing, which registers the
  backend in `occ backup:target:backends` fine, but that fallback's stream cannot seek. ng_backup
  reads blobs back by byte range while backing up, so even the very first (small) backup fails
  with `Seek failed in packs/...`. Install the extension (`libsmbclient-dev` + `pecl install
  smbclient` + `docker-php-ext-enable smbclient` on Debian-based images), not just the CLI tool —
  the backend being listed is not proof it will work here. Once the extension is present it works
  the same as any other backend; no ng_backup-specific config beyond host/share/user/password.

- **NFS is not a files_external backend at all.** Mount the NFS export on the server yourself
  (e.g. via `/etc/fstab` or a systemd `.mount` unit) and point ng_backup's `local` backend at the
  mount point; ng_backup never mounts or unmounts anything on its own, so an NFS mount dropping
  mid-run behaves like any other local-path failure (the run resumes). See PLAN.md 3.5 for why NFS
  can't be driven from PHP directly.

## Known limitations

On the backlog; none of these block the beta or 1.0.0, but know them before you rely on it:

- **Very large instances** (well over 1 TB, millions of files): the blob index, cleanup, browsing a
  snapshot in the web interface and per-user export manifests are held in memory. Expect high
  memory use there; reports from such instances are very welcome.
- **One location, one server.** Never let two Nextcloud servers write to the same location (for
  example a disaster recovery test server pointed at the production location): they can break each
  other's catalog, and the location is then refused as possibly tampered with. Test a disaster
  recovery against a copy of the location, or only read from it.
- **Removing a passphrase slot does not revoke access.** The master key stays the same, so someone
  with an old recovery kit and that slot's passphrase can still decrypt the location if they can
  read it. Key rotation is not available yet.
- **Rollback before a disaster recovery is not detected.** A fresh server has no record of the
  newest catalog generation, so a location rolled back to an older state looks valid there.
- Location passwords passed with `-o password=...` are visible in the process list and shell
  history while the command runs.
- Temporary files of a restore into a user's files end up in that user's trash.
- Restoring the database does not order tables by foreign keys; apps with cascading foreign keys
  could lose rows (Nextcloud core does not use them).
- A pack whose index upload failed stays on the location until removed by hand.

## Commands

| Command | Purpose |
|---|---|
| `backup:key:init`, `backup:key:kit`, `backup:key:confirm` | create the key, recovery kit, confirmation |
| `backup:key:slots`, `backup:key:slot:add/replace/remove` | passphrase slots |
| `backup:target:backends/add/list/remove/append-only` | backup locations |
| `backup:run`, `backup:status`, `backup:list` | back up, status, restore points |
| `backup:browse`, `backup:restore` | browse and restore |
| `backup:retention`, `backup:prune`, `backup:trash`, `backup:trash:restore` | retention, cleanup, trash |
| `backup:verify` | checksum audit of a restore point (`--deep` downloads and decrypts everything) |
| `backup:restore:full` | whole-server restore onto a fresh installation (after `backup:key:import`) |
| `backup:key:import` | import a recovery kit on a fresh installation |
| `backup:user:backup`, `backup:user:restore` | one user via user_migration (asks before installing it) |
