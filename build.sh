#!/bin/bash
# Packages DistroSKU DBA for SiteGround: laravel/ + public_html/ + README_DEPLOY.md. No .env, no storage contents, no secrets.
set -euo pipefail
cd "$(dirname "$0")"
V=$(grep -oP "'version' => '\K[0-9.]+" config/dba.php)
OUT=distrosku-dba-$V.zip
rm -rf stage "$OUT" && mkdir -p stage/laravel stage/public_html
rsync -a --exclude /public --exclude /vendor --exclude /node_modules --exclude /.env --exclude '/.env.*' --include /.env.example \
  --exclude /.git --exclude /tests --exclude /stage --exclude '*.zip' --exclude /build.sh --exclude /phpunit.xml --exclude 'database/*.sqlite' \
  --exclude '/storage/logs/*' --exclude '/storage/framework/sessions/*' --exclude '/storage/framework/views/*' --exclude '/storage/framework/cache/data/*' \
  --exclude '/storage/app/private/*' --exclude '/storage/app/public/*' --exclude 'bootstrap/cache/*.php' --exclude .phpunit.result.cache ./ stage/laravel/
cp .env.example stage/laravel/.env.example
(cd stage/laravel && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction -q --no-scripts && php artisan package:discover -q)
rsync -a --exclude /storage --exclude /hot ./public/ stage/public_html/
sed -i "s#__DIR__.'/../storage/framework/maintenance.php'#__DIR__.'/../laravel/storage/framework/maintenance.php'#; s#__DIR__.'/../vendor/autoload.php'#__DIR__.'/../laravel/vendor/autoload.php'#; s#__DIR__.'/../bootstrap/app.php'#__DIR__.'/../laravel/bootstrap/app.php'#" stage/public_html/index.php
grep -q "/../laravel/bootstrap/app.php" stage/public_html/index.php || { echo "CHECK FAILED: index.php not rewritten"; exit 1; }
# deny direct access in case laravel/ ends up web-reachable
printf 'Require all denied\n' > stage/laravel/.htaccess
cp README_DEPLOY.md stage/
# checks: no env files, no sqlite, no storage contents, no known identifiers
test ! -e stage/laravel/.env || { echo "CHECK FAILED: .env"; exit 1; }
! find stage -name '*.sqlite' | grep -q . || { echo "CHECK FAILED: sqlite"; exit 1; }
! grep -rIl --exclude-dir=vendor -E '17001783|github_pat_|ghp_' stage/ || { echo "CHECK FAILED: secret-looking string"; exit 1; }
(cd stage && php laravel/artisan --version >/dev/null)
(cd stage && zip -qr ../"$OUT" laravel public_html README_DEPLOY.md)
sha256sum "$OUT" | tee "$OUT.sha256"
