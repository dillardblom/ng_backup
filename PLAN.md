# ng_backup — plan

Een webgebaseerde, versleutelde backup- en restore-app voor Nextcloud 32–35, bedoeld voor MKB
zonder eigen IT-afdeling en voor gehoste Nextcloud zonder SSH. occ-commando's onder `backup:`.

Opgesteld 2026-10-01, na de evaluatie van nextcloud/backup op NC34 (zie onderaan).

## 1. Uitgangspunten

1. **Werkt zonder shell.** Geen `pg_dump`, `mysqldump`, rclone, rsync, tar of extra PHP-extensies.
   Alleen wat Nextcloud zelf meelevert (files_external-opslagklassen, AWS-SDK, phpseclib, sabre,
   Doctrine/DBAL, sodium).
2. **Werkt op elke variant:** AIO, losse installatie (Docker, bare metal, snap) én gehoste
   Nextcloud zonder SSH. Juist gehoste gebruikers hebben er het meeste aan; alles wat alleen met
   shell kan, is hooguit een optionele versnelling.
3. **Backuplocaties zijn tussentijds niet gekoppeld.** Geen mount in Files, geen open verbinding
   buiten een run; de locatie is voor gebruikers onzichtbaar.
4. **Restore is een hoofdfunctie, geen bijzaak.** Alles wat via occ kan, kan ook via de webinterface.
5. **Nooit een instantie achterlaten in onderhoudsmodus.** Backups draaien zonder onderhoudsmodus;
   waar die toch nodig is (volledige restore), altijd met gegarandeerd herstel.
6. **Hervatbaar.** Gehoste omgevingen hebben korte PHP-tijdslimieten en 512 MB geheugen: elk werk
   gebeurt in kleine, hervatbare stappen met checkpoints. Geheugengebruik onafhankelijk van datagrootte.
7. **Streamend van bron naar doel, zonder lokale tussenkopie.** Lezen → opdelen → comprimeren →
   versleutelen → uploaden gebeurt als één doorlopende stroom; restore andersom (downloaden →
   ontsleutelen → uitpakken → wegschrijven). Er is nooit een lokaal backupbestand of een tweede
   kopie van de data nodig; de lokale schijf hoeft hooguit ruimte te hebben voor één pack in
   aanbouw (~32 MB, instelbaar), en ook dat bij voorkeur in het geheugen.
8. **Alleen wat nodig is.** Data, database en config, geen Nextcloud-code en geen apps uit de store
   (die zijn opnieuw te installeren; de lijst met apps en versies gaat wel mee).
9. **Aantoonbaar betrouwbaar.** Elke release draait in CI een volledige backup→restore-rondgang op
   NC32–35 × SQLite/MySQL/PostgreSQL.

## 2. Wat de app doet (functioneel)

| Functie | Fase |
|---|---|
| Geplande backups (dagelijks/wekelijks, tijdvenster) en handmatig starten | 1 |
| Versleuteling, altijd aan, met herstelsleutel om te downloaden | 1 |
| Opslag via files_external (S3, SFTP, WebDAV, lokaal pad), aangestuurd door ng_backup, zonder koppeling of share | 1 |
| FTP(S), SMB, Swift; NFS/rclone via gateway (zie 3.5) | 2 |
| Append-only-modus en advies voor object lock (bescherming tegen ransomware) | 1 |
| Restore van één bestand of map, per gebruiker, naar elk herstelpunt | 1 |
| Backup en restore per gebruiker via user_migration (bestanden, agenda, contacten, Deck, Mail-instellingen, profiel, prullenbak, ...) | 2 |
| Volledige restore (disaster recovery) op een verse installatie | 2 |
| Volledige restore in-place op de bestaande instantie | 3 (onderzoeken) |
| Bewaarbeleid (bijv. 7 dagelijks, 4 wekelijks, 6 maandelijks) | 1 |
| Verificatie: periodiek checksums controleren, steekproef-restore | 2 |
| Meldingen bij mislukken of te oude backup (Nextcloud-notificatie + e-mail), setup-check in Beheer | 1 |
| Differentiële/incrementele backups met deduplicatie | 1 (zit in het formaat) |

## 3. Architectuur

### 3.1 Backupformaat

Content-addressed opslag, vergelijkbaar met restic/borg, zodat incrementeel en deduplicatie vanzelf gaan:

- **Blobs:** bestandsinhoud in stukken van max. 4–8 MB, geïdentificeerd met een hash (BLAKE2b via sodium)
  van de *onversleutelde* inhoud met een geheime sleutel (keyed hash, zodat de hash niets verraadt).
  Een blob die al op de opslag staat, wordt niet opnieuw geüpload.
- **Packs:** blobs worden gebundeld tot packbestanden van ~32 MB (minder kleine objecten op S3/SFTP).
- **Snapshot-manifest:** per herstelpunt een versleuteld manifest: tijdstip, NC-versie, app-lijst met
  versies, databasetype, en per gebruiker/map de boom met bestanden → blobs, plus de database-dump.
- **Index:** welke blob in welk pack zit, zodat restore van één bestand alleen de nodige packs ophaalt.

**Datastroom zonder tussenopslag:**

- *Backup:* bestand lezen als stream → blobs van 4–8 MB → elke blob gecomprimeerd (zstd als
  beschikbaar, anders gzip via zlib) en versleuteld in het geheugen → toegevoegd aan het pack in
  aanbouw. Een vol pack (~32 MB) wordt direct geüpload (bij S3 als multipart-onderdeel, bij
  SFTP/WebDAV als stream) en daarna uit het geheugen verwijderd. Piekgebruik: één blob + één pack,
  ongeacht de grootte van de backup.
- *Database:* de dump gaat tabel voor tabel als stream door dezelfde pijplijn; er komt nooit een
  dumpbestand op schijf.
- *Restore:* alleen de benodigde packs worden opgehaald, per blob ontsleuteld en gecontroleerd
  (AEAD) en direct naar het doelbestand geschreven. Een groot bestand terugzetten kost dus geen
  dubbele ruimte; een half teruggezet bestand wordt eerst als tijdelijk bestand naast het doel
  geschreven en pas na volledige controle op zijn plek gezet (alleen dát ene bestand dubbel).
- *Volledige restore van de database:* rijen worden in batches uit de stroom geïmporteerd.
- Als het geheugen te krap is voor een pack in aanbouw, kan dat naar een tijdelijk bestand
  (instelbaar), maar nooit meer dan één pack tegelijk.

Waarom niet zip/tar per backup: daarmee is één bestand of één gebruiker terugzetten traag (alles
uitpakken) en kost elke backup weer de volle ruimte. Dat was precies het probleem bij nextcloud/backup.

### 3.2 Versleuteling

- libsodium **secretstream (XChaCha20-Poly1305)**: streamend, dus het geheugen blijft klein, ongeacht de grootte.
- Een willekeurige **hoofdsleutel** per backup-repository; die wordt versleuteld opgeslagen met een
  sleutel afgeleid van een **wachtwoordzin** (Argon2id, `sodium_crypto_pwhash`).
- **Herstelkit** (wachtwoordzin-hint, versleutelde hoofdsleutel, repository-ID; als tekst/PDF + QR,
  te downloaden bij inrichten). Zonder die kit is een backup na verlies van de server onbruikbaar.
- **Verplichte bevestiging door de beheerder** voordat de eerste backup draait (en opnieuw bij een
  nieuwe sleutel):
  1. de kit downloaden (de knop verder blijft uit tot de download is gestart);
  2. een vinkje: *"Ik heb de herstelkit gedownload en veilig opgeslagen, buiten deze server. Ik
     begrijp dat het zorgvuldig bewaren van deze sleutels mijn eigen verantwoordelijkheid is en dat
     backups zonder deze sleutels niet te herstellen zijn."*;
  3. pas dan wordt de knop *"Yes, I confirm"* actief; klikken bevestigt en start de inrichting.
  Wie, wanneer en welke sleutelversie bevestigd is, wordt vastgelegd (audit log + instellingenpagina).
- De herstelkit kan op verzoek ook versleuteld naar het e-mailadres van de beheerder worden
  gestuurd, als extra kopie; dit vervangt de bevestiging niet.
- De sleutel staat **niet onversleuteld in de database**; de database gaat zelf ook mee in de backup.
- Integriteit: elk object is geauthenticeerd (AEAD); manifesten verwijzen met hashes naar hun inhoud.

### 3.3 Database-dump zonder externe tools

- **Schema:** via Nextcloud's eigen `SchemaEncoder`/Doctrine-schema-export (zoals `occ db:export-schema`).
- **Data:** per tabel in batches (bijv. 5.000 rijen, gesorteerd op primaire sleutel) via `IDBConnection`,
  weggeschreven als regels JSON met type-informatie, gestreamd door de versleuteling heen. Dit is
  hetzelfde principe als `occ db:convert-type`, dat ook rijen tussen databases kopieert.
- **Consistentie:** de dump draait binnen één transactie met `REPEATABLE READ` (PostgreSQL/MySQL),
  zodat de snapshot consistent is zonder onderhoudsmodus. Voor SQLite: een kopie van het bestand.
- **Restore:** werkt ook naar een ander databasetype (bijv. van MySQL naar PostgreSQL bij verhuizen).
- Grote tabellen die opnieuw op te bouwen zijn (bijv. `filecache` van externe opslag, previews,
  zoekindexen) zijn instelbaar uit te sluiten.

### 3.4 Bestanden

- Gelezen via de Nextcloud Files-API / opslaglaag (zodat ook object storage als primaire opslag
  werkt), niet rechtstreeks uit de datamap.
- Bron van wijzigingen: `filecache` (mtime, etag, size), zodat een incrementele run niet alle
  bestanden opnieuw hoeft te lezen.
- Externe opslag gaat standaard **niet** mee (instelbaar per koppeling).
- Server-side encryptie van Nextcloud: bestanden worden ontsleuteld gelezen en in de backup
  opnieuw versleuteld; documenteren dat de master key dan ook in de herstelkit moet.

### 3.5 Opslagdoelen

**files_external is een vereiste; ng_backup stuurt het aan, zonder koppeling.** De meegeleverde
app files_external heeft alle verbindingscode al: backends voor S3, SFTP, FTP, SMB, Swift en
WebDAV, met hun inlogmethoden (wachtwoord, SFTP-sleutel, enz.) en de beschrijving van hun
instellingenvelden. ng_backup bouwt daar niets van na, maar gebruikt files_external zoals het zichzelf
gebruikt (in NC34 nagekeken):

1. `BackendService::getAvailableBackends()` levert de beschikbare backends en hun parameters; het
   instellingenformulier van ng_backup wordt daaruit **automatisch opgebouwd** (zelfde velden en
   validatie als in files_external, ook voor backends die later bijkomen).
2. Bij een run maakt ng_backup een **niet-opgeslagen** `StorageConfig`, laat backend en
   inlogmethode hun `manipulateStorageConfig()` doen, en maakt de opslag aan met
   `new $storageClass($options)`, precies zoals `ConfigAdapter::constructStorage()` dat doet.
3. Na de run wordt het object vrijgegeven; er blijft geen verbinding open.

**Waarom geen (verborgen) koppeling in files_external:** een globale koppeling zonder toegewezen
gebruikers of groepen geldt in files_external juist voor **alle** gebruikers. Een verborgen
koppeling zou een aparte groep of systeemgebruiker vereisen; één verkeerde groepswijziging en
de backups zijn zichtbaar en wijzigbaar in Files. Met de werkwijze hierboven bestaat de
backuplocatie nergens als koppeling of share:

- niet zichtbaar in Files, niet gescand, niet deelbaar, ook niet voor beheerders;
- geen verbinding buiten een run ("tussentijds niet gemount");
- instellingen en inloggegevens in de eigen tabellen van ng_backup, versleuteld met `ICrypto`.

**Installatie en instellingen van files_external:**
- files_external is een meegeleverde app (`core/shipped.json`): aanwezig in elke reguliere
  installatie, standaard **uitgeschakeld**. ng_backup **verandert de status niet**: staat het uit,
  dan blijft het uit en laadt ng_backup alleen de klassen (in fase 0 getest: werkt); staat het
  aan omdat de beheerder het zelf gebruikt, dan blijft het aan. Uit is hier juist goed:
  onzichtbaarheid van de backuplocatie beperkt wat ransomware of een overgenomen account kan zien.
  Laden van een uitgeschakelde app gaat via interne API → in de adapter, met CI per NC-versie.
  Ontbreekt files_external helemaal (aangepaste installatie), dan alleen de eigen doelen.
- Gebruikers hoeven files_external niet te zien: "gebruikers mogen externe opslag koppelen" kan
  uit blijven en er hoeven geen koppelingen te bestaan. ng_backup controleert dat en waarschuwt
  als gebruikers zelf externe opslag mogen koppelen (geen vereiste, wel een tip).
- Dunne eigen adapter (`IBackend`: put/get/list/delete/stat met streams) bovenop de opslagobjecten
  van files_external, omdat dit interne klassen zijn: wijzigingen tussen NC-versies op één plek
  opvangen, met CI op elke versie. Een eigen implementatie alleen waar files_external tekortschiet
  (bijv. S3 multipart met object lock).

| Doel | Via | Opmerking |
|---|---|---|
| S3 en compatibel (incl. MinIO, Wasabi, Hetzner Object Storage) | files_external `AmazonS3` of eigen adapter op `aws/aws-sdk-php` (core) | voorkeursdoel, object lock mogelijk |
| SFTP | files_external `SFTP` (phpseclib) | sleutel of wachtwoord |
| FTP(S) | files_external `FTP` | |
| SMB/CIFS | files_external `SMB` (icewind/smb) | vereist libsmbclient of `smbclient` op de server; op hosted vaak niet beschikbaar → melden bij instellen |
| WebDAV (ook een andere Nextcloud) | core DAV-opslag | |
| Swift | files_external `Swift` | |
| NFS | alleen als lokaal pad | NFS kan PHP niet zelf aanspreken; vereist een koppeling op OS-niveau, wat botst met "tussentijds niet gemount". Advies: NFS-doel via een SFTP/S3-gateway, of een systemd-automount die de beheerder buiten de app regelt. Op hosted niet mogelijk. |
| rclone | niet rechtstreeks | rclone is een los programma (shell nodig). Alternatief: `rclone serve sftp/webdav/s3` op de doelmachine en ng_backup daarheen laten schrijven; daarmee komen alle rclone-doelen beschikbaar zonder binary op de Nextcloud-server. |

**Bescherming van de backups zelf (ransomware, gehackte server):** de Nextcloud-server heeft
noodzakelijk schrijfrechten op het doel; wie de server overneemt, kan dus ook backups wissen.
Daarom:
- aanbevolen inrichting per doel documenteren: S3 met **versioning + object lock** en een sleutel
  zonder delete-recht; SFTP met een chroot-gebruiker zonder shell; bij voorkeur een doel dat niet
  op hetzelfde netwerk staat;
- **append-only-modus** als optie: ng_backup verwijdert dan zelf niets; opruimen (bewaarbeleid)
  gebeurt via een levenscyclusregel op het doel of via een aparte, handmatig gestarte run met
  andere inloggegevens;
- meerdere doelen tegelijk (3-2-1), elk met eigen inloggegevens.

### 3.6 Uitvoering: hervatbare jobs

- Eén `TimedJob` start een run binnen het ingestelde tijdvenster; het werk zelf is een
  **toestandsmachine** (dump DB → verzamel bestanden → upload → manifest → opruimen) waarvan elke stap
  in delen van ~20–30 seconden draait en zijn voortgang opslaat (eigen tabel `ng_backup_runs`).
- Werkt met system cron, webcron én AJAX-cron (dan trager, wel betrouwbaar). Bij system cron kan
  `occ backup:run` ook in één keer doorlopen.
- **Lock** per repository (`ILockingProvider` + heartbeat), zodat twee runs nooit tegelijk schrijven;
  een verlopen lock (crash) wordt na een time-out netjes vrijgegeven.
- Een afgebroken run wordt de volgende keer **hervat**, niet opnieuw begonnen.

### 3.7 Onderhoudsmodus en volledige restore

- **Backup:** nooit onderhoudsmodus (consistentie via DB-transactie, zie 3.3).
- **Volledige restore, fase 2 (disaster recovery):** op een **verse** Nextcloud met dezelfde
  hoofdversie: ng_backup installeren → herstelkit invoeren → opslagdoel koppelen → herstelpunt
  kiezen → restore. Database wordt vervangen, bestanden teruggezet, de app-lijst wordt getoond om
  apps opnieuw te installeren. Hier is onderhoudsmodus niet nodig omdat er nog niets in gebruik is.
- **Volledige restore in-place, fase 3:** lastig, omdat de app dan de database vervangt waarop hij
  zelf draait en de webinterface in onderhoudsmodus niet bereikbaar is. Te onderzoeken: een eigen
  "restore-modus" (middleware die alleen de beheerder en de restore-endpoints doorlaat) in plaats
  van onderhoudsmodus. Altijd met `try/finally` en een watchdog die de modus na een time-out opheft.

### 3.8 Backup en restore per gebruiker: via user_migration

In plaats van zelf te bepalen welke gegevens van een gebruiker meegaan, hergebruiken we
**user_migration** (officiële Nextcloud-app, actief onderhouden, NC29–36; draait ook op onze eigen productie-instantie).
Die app werkt met *migrators* die andere apps zelf aanleveren. Op onze productie-instantie zijn dat er al:
calendar, dav (agenda's en contactpersonen), deck, files_trashbin, mail, settings (profiel,
account, app-instellingen) en de bestanden zelf. Elke app die later een migrator toevoegt, gaat
automatisch mee.

**Koppeling:** user_migration schrijft normaal een zip naar de map van de gebruiker, via de
publieke interface `OCP\UserMigration\IExportDestination`. ng_backup levert een **eigen
`IExportDestination`** die alles direct in de versleutelde, ontdubbelde backupstroom zet (geen
zip, geen kopie in de map van de gebruiker, geen dubbele ruimte), en een **eigen `IImportSource`**
die bij restore uit een herstelpunt leest. Het aansturen gaat via `UserMigrationService::export()`
/ `import()` van user_migration.

- `copyFolder()` krijgt een `Folder` mee: daar gebruiken we etag/mtime uit de filecache, zodat
  ongewijzigde bestanden niet opnieuw gelezen of gehasht worden (incrementeel blijft goedkoop).
- Dezelfde migrators zorgen dat een gebruiker ook naar **een andere Nextcloud** kan worden
  teruggezet (verhuizen), omdat het formaat app-onafhankelijk is.
- Een gebruikersbackup en de volledige backup delen dezelfde blobs; dubbel opslaan kost niets.

**Afhankelijkheid en risico's (uitzoeken in fase 0):**
- `UserMigrationService` is een interne klasse van user_migration (geen publieke API); net als bij
  files_external via een eigen adapter, met CI op elke NC-versie. Zonder user_migration valt alleen
  de gebruikersbackup weg; de volledige backup werkt dan nog.
- **Restore over een bestaande gebruiker** is in fase 0 getest en niet schoon (contacten dubbel,
  extra `migrated-*`-agenda's). Daarom twee keuzes in de restore-wizard, nooit importeren over
  een bestaand account heen:
  1. **Vervangen:** eerst automatisch een veiligheidsexport van de huidige stand naar de
     repository, dan het account verwijderen, dan uit de backup opnieuw aanmaken.
  2. **Naast het bestaande account:** de backup terugzetten in een nieuw account
     `<gebruiker>-bak` (of `<gebruiker>-<datum>`), zodat de beheerder kan vergelijken en gericht
     overzetten. (Een gebruikers-id hernoemen kan Nextcloud niet; daarom krijgt de *backup* de
     nieuwe naam, niet het bestaande account.)
- Agenda's en adresboeken komen terug als `migrated-*` met nieuwe URI's: clients synchroniseren
  opnieuw, delen van die agenda's moeten opnieuw. In de wizard vermelden.
- **Versies en prullenbak gaan mee:** de FilesMigrator exporteert ook `files_versions` van de
  gebruiker, de trashbin-migrator de prullenbak. Alleen bestanden in de eigen opslag van de
  gebruiker; externe opslag en groepsmappen niet (die gaan wel mee in de volledige backup).
- Een terugzetting per bestand of map blijft via de eigen bestandsboom gaan (sneller, geen migrators nodig).

Niet alle apps hebben een migrator (bijv. Talk-gesprekken); in de restore-wizard tonen welke
onderdelen wel en niet terugkomen, op basis van de geregistreerde migrators.

## 4. Webinterface

- **Beheer → Backup:** status (laatste geslaagde backup, volgende run, opslaggebruik), opslagdoelen,
  schema, bewaarbeleid, uitsluitingen, sleutelbeheer (herstelkit opnieuw downloaden na wachtwoordcontrole).
- **Herstelpunten:** lijst met datum, grootte, status van verificatie; per herstelpunt bladeren door
  gebruikers en mappen, bestanden of mappen terugzetten (naar de oorspronkelijke plek of naar een
  map "Teruggezet <datum>").
- **Restore-wizard** voor één gebruiker en (fase 2) voor een volledige restore op een verse installatie.
- **Voortgang** van lopende runs, met logboek; bij fouten een duidelijke melding en wat te doen.
- **Gebruikers zelf** (optioneel, fase 3): eigen bestanden terugzetten vanuit de Files-app, zoals bij versies.

## 5. occ-commando's (`backup:`)

| Commando | Doel |
|---|---|
| `backup:status` | laatste runs, status per opslagdoel |
| `backup:run [--target=] [--full]` | backup nu draaien (doorlopend, voor system cron/handmatig) |
| `backup:list [--target=]` | herstelpunten |
| `backup:verify <snapshot> [--deep]` | checksums controleren, optioneel alles ontsleutelen |
| `backup:restore:file <snapshot> <user> <pad> [--to=]` | bestand/map terugzetten |
| `backup:user:restore <snapshot> <user> [--to-user=]` | gebruiker terugzetten via user_migration (fase 2) |
| `backup:restore:full <snapshot>` | volledige restore (fase 2, verse installatie) |
| `backup:target:add/list/remove/test` | opslagdoelen beheren en testen |
| `backup:key:export / import` | herstelkit |
| `backup:prune [--dry-run]` | bewaarbeleid toepassen |

**Let op:** nextcloud/backup gebruikt ook het voorvoegsel `backup:` (o.a. `backup:point:*`).
Als beide apps tegelijk aan staan, botsen commando's met dezelfde naam. Onze namen overlappen
niet met de hunne, maar bij installatie waarschuwen als `backup` ook actief is.

## 6. Kwaliteit en tests

- PHPUnit-unittests voor formaat, versleuteling, chunking, toestandsmachine, bewaarbeleid.
- Integratietests met echte databases: dump→restore→vergelijk (rij-voor-rij) op SQLite, MySQL/MariaDB, PostgreSQL.
- **End-to-end in CI:** NC32–35 opzetten, testdata (gebruikers, bestanden, agenda, contacten),
  backup naar MinIO (S3) en SFTP-container, data beschadigen, restore, alles vergelijken.
- Foutinjectie: crash halverwege een run/restore → instantie nooit in onderhoudsmodus, run hervat.
- Geheugen- en schijftest: backup én restore van 20 GB data binnen `memory_limit=256M` op een
  machine met minder dan 1 GB vrije tijdelijke ruimte (tmpfs met limiet); meten dat er nooit meer
  dan één pack lokaal staat.
- Psalm (niveau streng) tegen `nextcloud/ocp` van elke ondersteunde versie; geen private `OC\`-klassen.
- Code review per PR, zoals bij occweb.

## 7. Fasering en inschatting

| Fase | Inhoud | Indicatie |
|---|---|---|
| 0 | Onderzoek/prototype: formaat, secretstream, DB-dump via DBAL, S3/SFTP via files_external-klassen, eigen IExportDestination voor user_migration; meten op hosted-achtige limieten | 1–2 weken |
| 1 (MVP) | Geplande, versleutelde, incrementele backups naar lokaal/S3/SFTP/WebDAV; restore van bestanden/mappen via web en occ; bewaarbeleid; meldingen; CI-rondgang | 4–6 weken |
| 2 | Backup/restore per gebruiker via user_migration, volledige restore op verse installatie, FTP/SMB, verificatie | 3–4 weken |
| 3 | In-place volledige restore (restore-modus), zelfbediening voor gebruikers, extra doelen | open |

Release in de app store pas na fase 1 en een periode van eigen gebruik op onze productie-instantie (net als occweb):
een backup-app die niet betrouwbaar is, is erger dan geen backup-app.

## 8. Besluiten (2026-10-01)

1. **Doelgroep:** alle varianten: AIO, losse installaties én gehoste Nextcloud zonder SSH.
2. **Licentie:** AGPL-3.0-or-later.
3. **Repository:** eigen Gitea + GitHub (dillardblom), zoals occweb.
4. **Naam:** "NG Backup", app-id `ng_backup`, occ-voorvoegsel `backup:`.
5. **Herstelkit:** verplicht downloaden + expliciete bevestiging door de beheerder (vinkje +
   "Yes, I confirm"), zie 3.2; versleuteld per e-mail als optionele extra kopie.
6. **Opslag:** files_external wordt door ng_backup aangestuurd zonder koppeling of (verborgen)
   share; de app-status blijft zoals de beheerder hem had (uit blijft uit), zie 3.5.
7. **Restore per gebruiker:** nooit over een bestaand account heen; óf vervangen (veiligheids-
   export, verwijderen, opnieuw aanmaken), óf terugzetten als `<gebruiker>-bak`, zie 3.8.
8. **Fase 0 afgerond (2026-10-01),** resultaten in `spikes/RESULTS.md`.

## 9. Releases en versies (besluit 2026-10-02)

| Versie | Betekenis |
|---|---|
| 0.1.0-alpha.N | publiek; meetesten gewaardeerd op een kopie van productie (dev, test, staging) |
| 0.9.0-beta.N | mag meedraaien op productie, naast de reguliere backup; volledige restore aanwezig en getest |
| 1.0.0-rc.N | releasekandidaten, alleen bugfixes |
| 1.0.0 | stabiel: vervanging van de reguliere backup, noodherstel bewezen |
| 1.2.0 e.v. | functies van fase 2 (restore per gebruiker, FTP/SMB, verificatie, ...) |

Volgorde: fase 1 grondig testen, reviewen en debuggen; dan nadenken over 0.1.0-alpha.1. Een
minimale volledige restore (`occ backup:restore:full` op een verse installatie) komt vóór de beta.

**Roadmap-indeling fase 2 (besluit 2026-10-05):** naar v1.2.0 toewerken met in fase 2:
1. `backup:restore:full` (disaster recovery, verse installatie) — **klaar** (2026-10-05)
2. FTP/SMB-ondersteuning — **klaar** (2026-10-06): beide liepen al via files_external's generieke
   adapter zonder enige ng_backup-code; alleen testdekking toegevoegd
   (`tests/integration/targets-matrix.sh`) + bevindingen in README ("Storage backend experiences").
   SMB vereist de `smbclient` PECL-extensie (niet standaard in de Nextcloud-image, in CI nu
   on-the-fly gebouwd). NFS is geen eigen backend: lokaal mounten + `local`-backend erop richten.
3. Verificatie/checksum-audit-commando — **klaar** (2026-10-05)
4. Retentie/prune voor user-exports — **klaar** (2026-10-06): `occ backup:retention --user-last=N`
   (standaard 3, per installatie, net als de bestaande snapshot-policy), toegepast door
   `occ backup:prune` (geen prullenbak voor user-exports, in tegenstelling tot snapshots — on-demand,
   niet het primaire vangnet).
5. Automatische user-export in het reguliere schema — **naar backlog** (niet voor deze release; on-demand blijft)
9. SMB-roundtrip (protocol, niet een specifieke server) — **klaar** (2026-10-06): CI-matrix, 2 GB-roundtrip en
   user-export via SMB; NFS via de OMV-share getest (`local`-backend op een gemounte share, 2026-10-06)
10. `backup:user:restore` zonder user_migration: vraagt om toestemming om de app te installeren en te activeren (of
   `--install-user-migration`) — **klaar** (2026-10-06); waarschuwing over de settings-allowlist — **klaar**;
   agenda/contacten bij migratie naar een server zonder die gebruiker — **getest** (2026-10-06, naar `migrated-personal`/`migrated-contacts`)
    Backlog: (a) de originele weergavenaam herstellen (het label uit de export is niet getest als
    weergavenaam); (b) de interne `migrated-`-naam hernoemen (vereist directe databasewijziging, niet voor de beta).

**Backlog uit de pre-beta review (2026-10-07)** — geen belemmering voor 0.9.0-beta of 1.0.0, staat
in README "Known limitations":
1. Geheugen bij grote instanties (>1 TB, miljoenen bestanden): blob-index, prune-set, browse van een
   snapshot in de web-UI, `trashExtras` bij restore en het manifest van een user-export staan volledig
   in het geheugen; streaming/paginering nodig.
2. Twee servers op één locatie: catalogus kan breken (generatie overschreven, of anchor >200 generaties
   achter). Docs-waarschuwing staat er; mogelijk later een echte put-if-absent per backend.
3. Sleutelrotatie: een slot verwijderen trekt geen toegang in (master-key blijft gelijk).
4. Rollback vóór DR niet detecteerbaar: referentie-generatie en -hash in de kit opnemen of tonen.
5. Kit-velden buiten `wrapped_key` (o.a. `delete_delay_days`) zijn niet geauthenticeerd; nu alleen getoond.
6. Repository-id in de AD van objecten (formaatwijziging).
7. Wachtwoorden van locaties via `--option-file` of omgevingsvariabele i.p.v. de commandoregel.
8. Tijdelijke restore-bestanden niet via de Files-API verwijderen (komen nu in de prullenbak).
9. DB-restore in foreign-key-volgorde (cascades).
10. Packs zonder index (index-upload mislukt) opruimen.
11. `backup:restore:full` in onderhoudsmodus en hervatbaar maken; nu: opnieuw draaien met `--force`.
12. Config/rename op FTP/SMB-servers die geen rename over een bestaand bestand toestaan.

Doorgeschoven naar **v1.3.0** (fase 3, niet meer fase 2): in-place volledige restore
(eigen restore-modus i.p.v. onderhoudsmodus) en zelfbediening voor gebruikers (eigen bestanden
terugzetten vanuit de Files-app).

**Let op (ontdekt 2026-10-05):** Nextcloud's eigen `info.xsd` staat voor `<version>` alleen
`[0-9]+(\.[0-9]+){0,2}` toe — geen `-alpha`/`-beta`/`-rc`-achtervoegsel. De stabiliteitsaanduiding
uit deze tabel gaat dus niet in `info.xml` (dat blijft gewoon oplopend, bijv. `0.1.0`), maar in de
git-tag (`v0.1.0-alpha.1`), de GitHub/Gitea release (gemarkeerd als pre-release) en de CHANGELOG.

## Bijlage: lessen uit nextcloud/backup (test NC34, 2026-10-01)

- Externe programma's (`pg_dump`) en extensies (`pgsql`, `mysqli`) → onbruikbaar op standaard/hosted.
- Crash tijdens backup/restore laat de instantie in onderhoudsmodus; occ-commando's van de app
  zijn dan niet beschikbaar.
- Hele chunks in het geheugen (`ZipArchive::getFromName()` als bestaan-check) → OOM bij restore.
- Bij een fout eindeloos "opnieuw proberen?" zonder invoer → hangende restore.
- Backupt de hele webroot + apps → groot en traag; restore alleen via occ in meerdere stappen.
- Herbruikbaar als idee: checksums + metadata per herstelpunt, AES-GCM/sodium, differentiële backups.
