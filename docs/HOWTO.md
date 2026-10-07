# NG Backup how-to

Step by step: from first install to a tested restore. All commands run as the web user
(`sudo -u www-data php occ ...`, or `docker exec -u www-data <container> php occ ...` in
All-in-One and Docker setups). Replace names in `<angle brackets>` with your own.

## 1. Create the key and the recovery kit

```
occ backup:key:init --label="Main" --delete-delay-days=7
occ backup:key:kit --output=/var/tmp/ng_backup-kit.json
occ backup:key:confirm -u <your-admin-uid> --code=<code shown> --confirm="Yes, I confirm"
```

- The passphrase protects the backup. Keep it somewhere other than this server.
- Optional: extra passphrases for other people with `occ backup:key:slot:add --label="..."`.
  Any one passphrase is enough to restore. Removing a slot later does not revoke access for someone
  who kept an old kit and that passphrase (see "Known limitations" in the README).
- The recovery kit (`/var/tmp/ng_backup-kit.json` above) is also required for disaster recovery. The web
  user writes it there; then move it off the server as root, next to the passphrase, and delete the
  copy in `/var/tmp`. Without the kit and a passphrase, the backup cannot be read.

## 2. Add a backup location

NG Backup uses Nextcloud's External storage backends. Check what your server supports first:

```
occ backup:target:backends
```

Examples:

```
occ backup:target:add offsite amazons3 -a amazons3::accesskey -o bucket=<bucket> -o key=<key> -o secret=<secret>
occ backup:target:add nas sftp -a password::password -o host=<host> -o user=<user> -o password=<pw> -o root=home
occ backup:target:add local local -a null::null -o datadir=/mnt/backup
```

- **SFTP on a Hetzner Storage Box sub-account:** use `-o root=home`, not `/` (see README).
- **FTP:** use a server that accepts absolute paths (for example `pure-ftpd`).
- **SMB:** requires the native `smbclient` PHP extension. The CLI binary alone is not enough (see README).
  `backup:target:add` refuses SMB when the extension is missing.
- **NFS:** not a backend of its own. Mount the export on the server and use the `local` backend
  with the mount point as `datadir`.

Verify the location before relying on it:

```
occ backup:target:list
occ backup:status
```

## 3. Run the first backup

```
occ backup:run offsite -l first
occ backup:list
```

Later runs upload only changed data. An unchanged run uploads (almost) nothing; the output shows
the number of uploaded bytes, so check that value when you test incremental behaviour.

Schedule `occ backup:run` from system cron (or use the Administration settings, if you prefer).

## 4. Check the backup regularly

```
occ backup:verify offsite <snapshot>          # shallow: all blocks are present
occ backup:verify offsite <snapshot> --deep   # also downloads and decrypts every block
```

Run the deep check at least once after setting up a location, and after changing it.

## 5. Restore files and folders

In the web interface, or with occ:

```
occ backup:browse offsite <snapshot>
occ backup:restore offsite <snapshot> data/<uid>/files/Docs --mode=new-folder
occ backup:restore offsite <snapshot> data/<uid>/files/Docs --mode=merge
occ backup:restore offsite <snapshot> data/<uid>/files/Docs --mode=replace
occ backup:restore offsite <snapshot> config --to-directory=/var/tmp/restore-config
```

- `new-folder` (default): puts the files in "Restored <date>" next to the original. Safest choice.
- `merge`: adds missing files, keeps current ones.
- `replace`: overwrites the folder. Replaced files keep their old content as a version, and removed
  files go to the trash.
- `--to-directory`: writes raw files to a local directory (for example `config/`).

## 6. Back up and restore one user

Needs the `user_migration` app. NG Backup asks before installing it:

```
occ backup:user:backup offsite <uid>
occ backup:user:restore offsite users/<uid>/<exportId>               # replace (default)
occ backup:user:restore offsite users/<uid>/<exportId> --mode=as-backup
```

- If `user_migration` is missing, the command asks to install and enable it. For non-interactive
  use, add `--install-user-migration` to approve that explicitly. The command stops after the
  installation; run it once more.
- `replace` first makes a safety export of the current account, then recreates the user under the
  same id. When the account exists, the command asks first; in scripts, add `--force`. Shares to
  others and data of apps without a user_migration migrator are not restored, so prefer
  `as-backup` when in doubt: it restores into `<uid>-bak` and leaves the original untouched.
- A restore onto a server where the user does not exist works. Calendars and address books get an
  internal name with the prefix `migrated-` (for example `migrated-personal`). That prefix comes from
  user_migration and cannot be turned off. The user can change the display name of these calendars and
  address books in the app as usual; the internal name stays as it is.
- **Settings:** on restore, `user_migration` imports only the app settings on its own allowlist
  (for example calendar view and reminder settings). Other app settings of the user are skipped.
  NG Backup reuses `user_migration`'s importer, so this list is not extended.
- Keep the last few exports per user: `occ backup:retention --user-last=3`.

## 7. Disaster recovery: restore the whole server

Use a fresh Nextcloud installation of the same major version, with NG Backup installed and
the same location available. For a test, use a **copy** of the location: two servers writing to one
location break each other's catalog.

```
occ backup:key:import /path/ng_backup-kit.json --passphrase-file=/path/passphrase
occ backup:target:add offsite <backend> ...          # same details as on the original server
occ backup:list offsite                              # the snapshots must be listed
occ backup:restore:full offsite <snapshot>
```

The command refuses on an installation that already has more than one user, and asks before it
starts; `--force` skips both (also needed to rerun it after it stopped halfway).

Afterwards, check that users, files and the database are complete, and run a backup of the restored
server. The restored installation keeps its own app configuration and secret.

## 8. Retention

```
occ backup:retention --last=3 --daily=7 --weekly=4 --monthly=12
occ backup:prune offsite --dry-run   # show first
occ backup:prune offsite             # apply the policy; forgotten snapshots go to the trash first
occ backup:trash                  # list the trash
occ backup:trash:restore offsite <id>
```

Snapshots are kept according to the policy, and their data is deleted only after the deletion delay
(set with `backup:key:init --delete-delay-days`). `occ backup:prune offsite --dry-run` shows what would be
removed without changing anything.

## 9. Checklist before you rely on it

- [ ] Key created, passphrase stored off-server, recovery kit stored off-server
- [ ] Location added; `backup:status` shows it reachable
- [ ] First backup done and listed in `backup:list`
- [ ] `backup:verify --deep` succeeds
- [ ] One file restore tested (`new-folder`)
- [ ] Disaster recovery tested on a second, fresh installation (or in a test environment)
