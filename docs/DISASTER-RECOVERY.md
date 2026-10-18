# Disaster recovery: your server is gone

Your Nextcloud server is lost. You have a new, empty server. These are the steps to get your
Nextcloud back from an NG Backup location. Do them in this order.

## What you need

- [ ] The **recovery kit** file (`ng_backup-kit.json`) and **one passphrase** of it.
- [ ] The **connection details of the backup location**: host, user, password or keys, bucket, folder.
- [ ] A new server with the **same Nextcloud major version** as the old one (for example 34),
      a database (PostgreSQL, MySQL/MariaDB or SQLite; it may differ from the old one) and room for
      the whole backup plus a margin.
- [ ] A list of the apps that are **not in the app store** (your own builds, apps copied in by
      hand). Keep their packages somewhere safe beforehand.

No kit? If the location still holds its `config` file, the key is there too, but this version
can only import it from a kit. Keep the kit off the server, next to the passphrase.

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

**4. Import the backup key.** Copy the kit to the new server so the web server user can read it.

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

**5. Add the backup location** with the same details as before. This can take a while on a large
backup (the index is read first) and shows nothing until it is done.

    occ backup:target:add offsite sftp -a password::password -o host=... -o user=... -o password=... -o root=... --path=...

If it says "No backup key yet", the key is not imported. Run step 4:

    occ backup:key:import /tmp/ng_backup-kit.json

Do **not** run `backup:key:init`, even though the message suggests it: that makes a brand new key,
and your backups were encrypted with the old one. NG Backup then refuses the location ("created
with a different key").

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
a large backup. If it stops halfway, fix the cause and run the same command again with `--force`.

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
- Start background jobs again (cron), as in step 2 in reverse.

**10. Point your domain at the new server** and check that clients reconnect.

**11. Make a backup of the new server** to the same location (`occ backup:run offsite`). The old
server must stay off: two servers must never write to the same location.

## Never

- Never let the old server write to the location again if it comes back. Switch its backups off
  first.
- Never give your passphrase to anyone. Nobody helping you needs it.

## Help

Stuck? [Ensembia](https://www.ensembia.com), the developer of NG Backup, offers paid help with
restoring a backup after a crash.
