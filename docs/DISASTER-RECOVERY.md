# Disaster recovery: your server is gone

Your Nextcloud server is lost. You have a new, empty server. These are the steps to get your
Nextcloud back from an NG Backup location. Do them in this order.

## What you need

- [ ] **One passphrase** of the old server, and if you have it, the **recovery kit** file
      (`ng_backup-kit.json`).
- [ ] The **connection details of the backup location**: host, user, password or keys, bucket, folder.
- [ ] A new server with the **same Nextcloud major version** as the old one (for example 34),
      a database (PostgreSQL, MySQL/MariaDB or SQLite; it may differ from the old one) and room for
      the whole backup plus a margin.
- [ ] **Shell access** to the new server, to run `occ` (on a hosted Nextcloud without it, your
      provider runs these steps).
- [ ] A list of the apps that are **not in the app store** (your own builds, apps copied in by
      hand). Keep their packages somewhere safe beforehand.

No kit? Since version 1.1.0 the passphrase is enough: the key is also stored on the location (in
its `config` file), locked with the same passphrases. Skip step 4. The kit is still the safer
copy: it helps when that file on the location is damaged. Keep it off the server, next to the
passphrase.

## Steps

**1. Install a fresh Nextcloud** of the same major version. Create an admin account. Do not add
users or files.

**2. Do not let background jobs run yet.** A new Nextcloud runs them on page visits (AJAX).
Switch that off:

    occ background:cron

As long as no cron is set up, nothing runs. Check that none is:

- Regular server: `sudo crontab -u www-data -l`. If it shows a line with `cron.php`, remove it
  for now with `sudo crontab -u www-data -e` (put it back in step 9).
- Docker Compose with a cron service: `docker compose stop cron`.
- Nextcloud All-in-One: cron runs inside the Nextcloud container; leave it. It does no harm here:
  the restore keeps NG Backup's own settings of the new server, so it does not start backups.

This keeps the new server quiet (no mail syncs, no notifications, no backups) until it is ready.

**3. Install NG Backup** (the same version or newer) and enable it.

**4. Import the backup key** (with a kit; without one, go to step 5). Copy the kit to the new
server so the web server user can read it.

From your own computer:

    scp ng_backup-kit.json root@newserver:/tmp/

Then on the server, one of:

    chown www-data /tmp/ng_backup-kit.json                                    # regular server
    docker cp /tmp/ng_backup-kit.json <container>:/tmp/ && \
      docker exec <container> chown www-data /tmp/ng_backup-kit.json          # Docker

`<container>` is the name of your Nextcloud container: `docker ps` shows it (for example
`nextcloud-aio-nextcloud`, or `myproject-nextcloud-1` with Docker Compose).

Import it; you type the passphrase yourself:

    occ backup:key:import /tmp/ng_backup-kit.json

It shows the key fingerprint: it must match the one of your old server (on the recovery kit).

**5. Add the backup location** with the same details as before. On a large backup this first reads
the index and shows how far it is, for example
`Reading the index: 6,913/26,791 files (25%), about 12 min left`.

    occ backup:target:add offsite sftp -a password::password -o host=... -o user=... -o password=... -o root=... --path=...

Without a kit (step 4 skipped), it finds the backups and asks for a passphrase of the old server;
you type it yourself. It then shows the key fingerprint and the deletion delay of the old server.
A wrong passphrase changes nothing: run the command again. Backups made before version 1.1.0 did
not store the deletion delay on the location; it is then set to 7 days.

If it says that the location's **config file is gone**, the key is only in the recovery kit:
import it (step 4), then add the location with `--repair-config` at the end of the same command.
That writes the config again from the kit; it works for locations backed up by version 1.1.0 or
newer.

If it says "No backup key yet, and no backups at this location", the connection details or
`--path` differ from the old server's. Check them; do **not** run `backup:key:init`: that makes a
brand new key, and your backups were encrypted with the old one. NG Backup then refuses the
location ("created with a different key").

**Made a new key by mistake** (with `backup:key:init` or on the page) before adding the location?
As long as that key was never used (no location added with it, no backup), it can make way for the
key of your backups. NG Backup says so when it refuses the location. Add `--replace-unused-key` to
`occ backup:key:import` (with the kit) or to `occ backup:target:add` (without the kit; it asks for
a passphrase of the old server). The passphrases and kit of the unused key then stop working. A key
that was used for a location is never replaced: its own backups depend on it. A key made with a
version before 1.1.0 always counts as used (those versions did not keep track), also when it never
protected a location; recover onto a fresh Nextcloud installation instead.

**6. List the restore points** and pick one, normally the newest:

    occ backup:list offsite

**7. Start the restore, and let it install the apps.**

    occ backup:restore:full offsite <snapshot> --install-apps

`--install-apps` first installs every app the old server had enabled from the app store (or
switches it on, if it is part of Nextcloud). Tables of apps that are missing or were removed long
ago are created from the backup, so they do not stop the restore.

At the end it lists the apps it could not install, usually your own builds that are not in the
app store. Copy those into `custom_apps` and enable them (`occ app:enable <app>`); their data is
already restored.

Backups made before 1.0.0-rc.2 do not record the structure of their tables. If the restore lists
tables that do not exist here, install the apps they belong to and run it again, or leave them
out with `--skip-missing-tables`.

**8. Let the restore run.** It restores the database, then the data directory, and takes hours for
a large backup. Nextcloud is in maintenance mode meanwhile, so nobody and no background job
writes into it; the restore switches it off when it completes. (If you had switched maintenance
mode on yourself before, it stays on: switch it off with `occ maintenance:mode --off` when ready.)

If it stops halfway, maintenance mode stays on (the instance may be partly restored). Fix the cause,
then switch maintenance mode off and run the restore again with `--force`, in one line (the message
prints it): `occ maintenance:mode --off && occ backup:restore:full <location> <snapshot> --force`.
It goes on where it stopped: data files that are already in place with the size and modification
time of the backup are left, so only the rest comes from the location again (since 1.0.2; before,
it started over). The database is restored again in full, which takes minutes. Leave out `--install-apps` this time, so that the instance is open only for the seconds until the
restore has switched maintenance mode on again; apps the first run could not install are listed at
the end of the restore, install those afterwards.
If occ then says that Nextcloud or one of the apps requires an upgrade, the database is already
the old server's: run `occ upgrade` first, then the same command with `--force`.

**If the backup itself is damaged** (the restore stops with "Decryption failed", "Blob
verification failed" or a file that is missing on the location), try an older restore point
first. If none is better, `--salvage` restores whatever can be read:

    occ backup:restore:full offsite <snapshot> --install-apps --salvage

If a restore already stopped halfway, run it as in step 8: maintenance mode off, with `--force` and
without `--install-apps`:

    occ maintenance:mode --off && occ backup:restore:full offsite <snapshot> --salvage --force

- Files that cannot be read are left out and listed at the end; everything else is restored.
- A full disk is not damage: the restore stops, also with `--salvage`, and says so. Make room and
  run the restore again as in step 8.
- **On version 1.0.2, check the disk first if the reason is "Size mismatch".** There a full disk
  looks like damaged data: the message points at `--salvage`, and with `--salvage` the restore goes
  on and lists the remaining files. The backup itself is fine. Update NG Backup, make room and run
  the restore again as in step 8.
- If the database cannot be restored (its dump is damaged, or it does not fit this
  installation), the database stays as the fresh installation made it, and the data directory
  and config of the backup go to a folder of their own in the data directory,
  `ngb-salvage-<snapshot>`. Each user's files are in `data/<user>/files` there: create the users
  again and copy their files back by hand (then `occ files:scan --all`). Calendars, contacts and
  shares are in the database and cannot be recovered this way.

The command then ends with exit code 2 instead of 0. The same option exists for
`occ backup:restore`, to restore single folders.

**9. After the restore.** These worked in our tests:

    occ upgrade
    occ maintenance:repair
    occ files:scan --all

- Check `config/config.php`: `trusted_domains`, `overwrite.cli.url` and `overwritehost` come from
  the old server; change them if the new server has another name. The database settings and the
  data directory are kept from the new installation.
- From Nextcloud All-in-One to a regular server: also check the cache settings (`memcache.*`,
  `redis`), which point at AIO's containers.
- Log in, open a few files, a calendar and a contact.
- `occ files:scan` can report many folders as "Updated". That is expected: a restore does not yet put
  back the modification time of folders with files in them, so they show the time of the restore.
  The files themselves keep their time and content.
- Empty folders only come back from backups made with version 1.0.3 or newer. With an older
  restore point they are gone: `occ files:scan` then removes them from the file cache, so it reports
  them as removed.
- Start background jobs again (cron), as in step 2 in reverse.

**10. Point your domain at the new server** and check that clients reconnect.

**11. Make a backup of the new server** to the same location (`occ backup:run offsite`). The old
server must stay off: two servers must never write to the same location.

Restored without a kit? Then backups wait until you have a new one: download it on the backup
page (or `occ backup:key:kit --output=...`), store it off the server and confirm it
(`occ backup:key:confirm`).

## Never

- Never let the old server write to the location again if it comes back. Switch its backups off
  first.
- Never give your passphrase to anyone. Nobody helping you needs it.

## Help

Stuck? [Ensembia](https://www.ensembia.com), the developer of NG Backup, offers paid help with
restoring a backup after a crash.
