# DistroSKU DBA 0.1.1 — instance manager for DistroSKU ERP

`https://dba.distrosku.com` — one admin panel for every ERP instance: health, backups/restore, the suspend switch, versioned global rules and releases.
Laravel 12 + Filament 3, PHP 8.2+, MySQL. This file contains no secrets; every secret is created on the server.

## What it does
- **Instances** — register each ERP (name, slug, URL, prod/test). The manager generates a 64-char shared secret, shows it **once**, and stores it encrypted (it must be reversible to sign requests). *New link secret* replaces it any time (the old secret stops working immediately).
- **Health** — every 5 minutes (cron) the manager calls each ERP's signed `/dba-api/status`: version, DB, queue, disk, last backup, rules version. Two failed polls in a row = **down**: the dashboard flags it and admins (+ `DBA_ALERT_EMAILS`) get one email; another email when it recovers.
- **Backups** — *Back up now*, list (synced from the instance, with size + sha256), *Verify checksums*, *Restore…* (type the instance id to confirm; the ERP verifies the checksum and takes a safety backup first). Nightly backups (01:30, 14 daily + 8 weekly) run on the ERP itself and keep running while suspended.
- **Suspend switch** — *Suspend* (type the instance id) shows "This account is suspended" (HTTP 503) on the ERP for everyone; **no data is changed**. *Resume* restores it. Both are audited.
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
1. File Manager → open `dba.distrosku.com/` (the folder that contains `public_html`). Upload `distrosku-dba-0.1.1.zip` and extract it there.
   You get `dba.distrosku.com/laravel/` (the app, outside the web root) and `dba.distrosku.com/public_html/` (web files; `index.php` already points at `../laravel`), plus this README.
   If `public_html` already had files from SiteGround (default `index.html`), delete them.
2. Delete the zip.
3. Check the sha256 of the zip before uploading if you like: `sha256sum distrosku-dba-0.1.1.zip`.

## 3. Run the one-time web setup (no SSH needed)
The zip contains `public_html/setup-<token>.php`. The token is long and random; the exact filename and URL are given to you separately (it is not written in this README).
1. Open `https://dba.distrosku.com/setup-<token>.php?token=<token>` — **HTTPS only**; a wrong or missing token returns "Not found" (after a delay).
2. Fill in:
   - **Database:** host (`localhost` on SiteGround), database name, user, password (from step 1.4).
   - **Owner login:** email (prefilled `michael@brandworx.ai`), name, password + confirm (12+ characters).
   - **Mail for alerts** (optional, can be added later): SMTP host, port, username, password, from address. Without it, alert mails go to the log.
3. Press **Install**. It checks the database connection, then:
   writes `laravel/.env` (APP_ENV=production, APP_DEBUG=false, APP_URL=https://dba.distrosku.com, your DB/mail values; mode 600) →
   makes `storage/` and `bootstrap/cache/` writable → `key:generate` → `migrate --force` → creates the owner (password stored hashed only) →
   `dba:import-rules --publish` (global rules v1) → `optimize` + `filament:optimize` → **deletes itself** and shows **Setup complete**.
4. Sign in at `https://dba.distrosku.com/login`.
5. Confirm in File Manager that `public_html/setup-*.php` is gone (delete it if the page said it could not).

Safety: the page refuses to run if `laravel/.env` already exists (so it can never overwrite a live install), only accepts posts carrying its own signed form nonce (1-hour validity), and never shows a password in its output or log.
If it stops halfway after writing `.env`, fix the cause, delete `laravel/.env` in File Manager (don't open it), re-upload the setup file from the zip if it is gone, and run it again.
Later changes to settings (mail, GitHub repo, alert addresses) are made by editing `laravel/.env` — do that over SSH or by downloading/re-uploading it; don't open `.env` in the File Manager editor.

## 4. Cron (required — health checks every 5 minutes)
Site Tools → Devs → **Cron Jobs** → Create: interval **every minute** (`* * * * *`), command:
```
php /home/customer/www/dba.distrosku.com/laravel/artisan schedule:run >> /dev/null 2>&1
```
- SiteGround shows the correct home path and PHP command on the Cron Jobs page ("php /home/customer/www/…"); if yours differs, use that path. If `php` isn't found, use `/usr/local/bin/php` instead.
- Equivalent form: `cd /home/customer/www/dba.distrosku.com/laravel && php artisan schedule:run >> /dev/null 2>&1`.
- The scheduler runs `dba:poll` every 5 minutes. After a few minutes, each instance's *Last check-in* on the dashboard should update.

## 5. Register vegatest1
1. vegatest1 must run ERP **0.20.8** (run its upgrade page as usual) — the manager API is part of that release.
2. Manager → **Instances → Register instance**: Name `Vega Test 1`, Instance id `vegatest1`, URL `https://vegatest1.distrosku.com`, Environment `test` → Create.
3. Copy the secret from the notification. It is shown **once** and never again; if you lose it, use *New link secret* on the instance and paste the new one.
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
Upload the new zip, extract over `laravel/` and `public_html/` (keep `laravel/.env` and `laravel/storage/`), delete the new `public_html/setup-*.php` (it refuses to run on an installed site anyway), then run `php artisan migrate --force && php artisan optimize` (SSH, or a one-off cron job with the same `php …/artisan` form).

## Troubleshooting
- Instance shows "bad signature": the secret on the ERP and the manager differ — use *New link secret* and paste it on the ERP again.
- "stale timestamp": a server clock is off by more than 5 minutes.
- "HTTP 503 not linked": the ERP has no secret yet (step 5.4).
- Logs: `laravel/storage/logs/` (daily).
