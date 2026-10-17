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

As long as no cron is set up, nothing runs. If your setup has a cron service or container (Docker,
All-in-One), stop it until step 9. This keeps the new server quiet (no mail syncs, no
notifications, no backups) until it is ready.

**3. Install NG Backup** (the same version or newer) and enable it.

**4. Import the backup key.** Copy the kit to the new server, readable for the web server user
(for example `scp ng_backup-kit.json newserver:/tmp/` and `chown www-data /tmp/ng_backup-kit.json`).
Then import it; you type the passphrase yourself:

    occ backup:key:import /tmp/ng_backup-kit.json

It shows the key fingerprint: it must match the one of your old server (on the recovery kit).

**5. Add the backup location** with the same details as before. This can take a while on a large
backup (the index is read first) and shows nothing until it is done.

    occ backup:target:add offsite sftp -a password::password -o host=... -o user=... -o password=... -o root=... --path=...

If it says "No backup key yet", go back to step 4. Do **not** run `backup:key:init`: that makes a
brand new key, and your backups were encrypted with the old one. NG Backup then refuses the
location ("created with a different key") and you still need the kit.

**6. List the restore points** and pick one, normally the newest:

    occ backup:list offsite

**7. Install the apps the old server had.** Run the restore; before it changes anything it checks
the apps and stops with what is missing:

    occ backup:restore:full offsite <snapshot>

- "install these apps first: …": install each from the app store (`occ app:install <app>`), or
  enable it if it is part of Nextcloud but switched off (`occ app:enable <app>`, for example
  `admin_audit`). Apps that are not in the app store: copy them into `custom_apps` and enable them.
- "Table … does not exist here": an app that was removed or switched off long ago left tables
  behind. Install that app (for example `deck`, `onlyoffice`), then run the restore again.

Repeat until the restore starts.

**8. Let the restore run.** It restores the database, then the data directory, and takes hours for
a large backup. If it stops halfway, fix the cause and run the same command again with `--force`.

**9. After the restore** <!-- completed after the PVE02 test -->

- Check `config/config.php`: `trusted_domains`, `overwrite.cli.url`, and the cache (Redis) host
  must match the new server. The database settings and data directory are kept from the new
  installation.
- `occ upgrade` (apps on the new server may be newer than in the backup), then
  `occ maintenance:repair`.
- Log in, open some files, a calendar and a contact.
- Start background jobs (cron).

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
