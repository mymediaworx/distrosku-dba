# DistroSKU DBA 0.1.0 — instance manager for DistroSKU ERP

`https://dba.distrosku.com` — one admin panel for every ERP instance: health, backups/restore, the suspend switch, versioned global rules and releases.
Laravel 12 + Filament 3, PHP 8.2+, MySQL. This file contains no secrets; every secret is created on the server.

## What it does
- **Instances** — register each ERP (name, slug, URL, prod/test). The manager generates a 64-char shared secret, shows it **once**, and stores it encrypted (it must be reversible to sign requests). Rotate any time (the old secret stops working immediately).
- **Health** — every 5 minutes (cron) the manager calls each ERP's signed `/dba-api/status`: version, DB, queue, disk, last backup, rules version. Two failed polls in a row = **down**: the dashboard flags it and admins (+ `DBA_ALERT_EMAILS`) get one email; another email when it recovers.
- **Backups** — *Back up now*, list (synced from the instance, with size + sha256), *Verify*, *Restore* (type the slug to confirm; the ERP verifies the checksum and takes a safety backup first). Nightly backups (01:30, 14 daily + 8 weekly) run on the ERP itself and keep running while suspended.
- **Suspend switch** — *Suspend* (type the slug) shows "This account is suspended" (HTTP 503) on the ERP for everyone; **no data is changed**. *Resume* restores it. Both are audited.
- **Global rules** — versioned sets (license types, eligibility rules, compliance rules, tax rates). v1 is imported from the ERP export. Drafts are editable, published sets are immutable; *Preview & push* shows per-instance create/update/same counts before writing; pushes are idempotent, rows are upserted by code and marked *managed* (read-only) on the ERP, never deleted.
- **Releases** — each instance's version vs the latest (GitHub tags if `DBA_GITHUB_REPO` is set, else `DBA_LATEST_VERSION`, else the newest instance). One-click upgrade is a disabled stub in 0.1.0.
- **Audit log** — every sign-in, registration, secret rotation, backup, restore, suspend/resume, rule push and rejected signature.

Security: admin-only panel (users without `is_admin` get 403); no default user or password; manager↔ERP traffic is HMAC-SHA256 signed (method, path, timestamp, nonce, body hash), 5-minute window, single-use nonces. Requires ERP **0.20.8** or later.

## 1. SiteGround: create the subdomain
1. Site Tools → Domain → Subdomains → create `dba.distrosku.com` (document root `dba.distrosku.com/public_html`).
2. Security → SSL Manager → install Let's Encrypt for `dba.distrosku.com`; Security → HTTPS Enforce on.
3. Devs → PHP Manager → set PHP **8.2** (or newer) for the subdomain.
4. Site Tools → MySQL → create a database and a user with All Privileges on it. Note the names and password (keep them in your password manager, not in a file).

## 2. Upload
1. File Manager → open `dba.distrosku.com/` (the folder that contains `public_html`). Upload `distrosku-dba-0.1.0.zip` and extract it there.
   You get `dba.distrosku.com/laravel/` (the app, outside the web root) and `dba.distrosku.com/public_html/` (web files; `index.php` already points at `../laravel`), plus this README.
   If `public_html` already had files from SiteGround (default `index.html`), delete them.
2. Delete the zip.
3. Check the sha256 of the zip before uploading if you like: `sha256sum distrosku-dba-0.1.0.zip`.

## 3. Configure (SSH — Site Tools → Devs → SSH Keys Manager)
```bash
cd ~/www/dba.distrosku.com/laravel
cp .env.example .env
chmod 600 .env
nano .env        # edit over SSH — do NOT open .env in the File Manager editor
```
Fill in: `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, the `MAIL_*` settings (SiteGround mail or SMTP), `MAIL_FROM_ADDRESS`, `DBA_OWNER_EMAIL=michael@brandworx.ai`, `DBA_OWNER_NAME`. Leave `DBA_OWNER_PASSWORD` empty (you'll be prompted). Optional: `DBA_ALERT_EMAILS`, `DBA_GITHUB_REPO` (+ a read-only `DBA_GITHUB_TOKEN` if the repo is private) or `DBA_LATEST_VERSION`.

```bash
php artisan key:generate --force
php artisan migrate --force
php artisan dba:make-owner                 # prompts for a password (min 12 chars); creates the admin
php artisan dba:import-rules --publish     # global rules v1 (bundled database/data/rules-v1.json)
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan filament:optimize
```
Then sign in at `https://dba.distrosku.com/login`.

## 4. Cron (required — health checks every 5 minutes)
Site Tools → Devs → Cron Jobs → every minute (`* * * * *`):
```
cd ~/www/dba.distrosku.com/laravel && php artisan schedule:run >> /dev/null 2>&1
```
(Adjust the path to what `pwd` shows in step 3.) Check it with `php artisan schedule:list`; run one poll by hand with `php artisan dba:poll`.

## 5. Register vegatest1
1. vegatest1 must run ERP **0.20.8** (run its upgrade page as usual) — the manager API is part of that release.
2. Manager → **Instances → Register instance**: Name `Vega Test 1`, Slug `vegatest1`, URL `https://vegatest1.distrosku.com`, Environment `test` → Create.
3. Copy the secret from the notification (shown once; *Show secret* on the instance re-displays it for admins, *Rotate secret* replaces it).
4. On vegatest1: **Settings → Instance manager** → paste the secret → Save. (Alternative: `ERP_DBA_SECRET=` and `ERP_INSTANCE=vegatest1` in vegatest1's `.env` over SSH, then `php artisan config:cache`.)
5. Manager → the instance → **Check now**. It should turn **Active** and show version 0.20.8. Then **Back up now** and confirm the backup appears in *Backups*.
6. Global rules → v1 → **Preview & push** → select vegatest1 → Preview → Push. The instance shows rules v1.

## Backup / restore from the command line (on the ERP host)
```bash
php artisan erp:backup                         # checksummed zip in ~/erp-backups/<instance>/
php artisan erp:backup --list --verify
php artisan erp:restore <file-or-full-path>    # verifies, safety backup first, then restores
```
Restoring a backup **from another instance** is CLI-only in 0.1.0: copy the zip to the target host and run `erp:restore /full/path/to.zip`. Encrypted settings only stay readable when both instances share the same APP_KEY.

## Upgrading the manager later
Upload the new zip, extract over `laravel/` and `public_html/` (keep `laravel/.env` and `laravel/storage/`), then `php artisan migrate --force && php artisan optimize`.

## Troubleshooting
- Instance shows "bad signature": the secret on the ERP and the manager differ — rotate and paste again.
- "stale timestamp": a server clock is off by more than 5 minutes.
- "HTTP 503 not linked": the ERP has no secret yet (step 5.4).
- Logs: `laravel/storage/logs/` (daily).
