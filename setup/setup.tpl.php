<?php
/**
 * DistroSKU DBA one-time web installer (for hosts without SSH). Deletes itself after a successful run.
 * Visit: https://dba.distrosku.com/setup-<token>.php?token=<token>   (the token is only in the filename and the URL you were given)
 */
declare(strict_types=1);
@ini_set('display_errors', '0');
@ini_set('log_errors', '0'); // never let posted values reach a PHP error log
error_reporting(0);
@set_time_limit(300);
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

const SETUP_TOKEN = '__TOKEN__';
$base = defined('DBA_SETUP_BASE') ? DBA_SETUP_BASE : __DIR__ . '/../laravel';
$base = realpath($base) ?: $base;
$allowSqlite = defined('DBA_SETUP_ALLOW_SQLITE'); // test harness only

function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function envq(string $v): string { return '"' . str_replace(['\\', '"', "\n", "\r", '$'], ['\\\\', '\\"', '', '', '\\$'], $v) . '"'; }
function nonce_for(int $ts): string { return $ts . '.' . hash_hmac('sha256', 'dba-setup-form|' . $ts, SETUP_TOKEN); }
function nonce_ok(string $n): bool
{
    if (!preg_match('/^(\d{10})\.[a-f0-9]{64}$/', $n, $m)) return false;
    $ts = (int) $m[1];
    return $ts <= time() + 60 && $ts >= time() - 3600 && hash_equals(nonce_for($ts), $n);
}
function stop(int $code, string $msg): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    exit($msg);
}

// 1. Token (constant-time compare; slow down guessing)
$token = $_GET['token'] ?? null;
if (!is_string($token) || !hash_equals(SETUP_TOKEN, $token)) {
    if (!defined('DBA_SETUP_NO_SLEEP')) sleep(3);
    stop(404, 'Not found');
}

// 2. HTTPS only (SiteGround terminates TLS at nginx and passes HTTPS / X-Forwarded-Proto)
$https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
    || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
if (!$https) stop(403, 'HTTPS required. Open this page with https://');

// 3. Guards
if (!is_file($base . '/vendor/autoload.php') || !is_file($base . '/.env.example')) {
    stop(500, 'App folder not found at ../laravel (expected dba.distrosku.com/laravel next to public_html).');
}
if (file_exists($base . '/.env')) {
    stop(409, 'Already installed: laravel/.env exists. Setup will not run again. Delete this setup file in File Manager.');
}

$log = [];
$errors = [];
$done = false;
$deleted = false;
$secrets = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = fn($k, $d = '') => trim((string) ($_POST[$k] ?? $d));
    $raw = fn($k) => (string) ($_POST[$k] ?? '');
    if (!nonce_ok($in('nonce'))) {
        $errors[] = 'The form expired or was not sent from this page. Reload and try again.';
    } else {
        $driver = ($allowSqlite && $in('db_driver') === 'sqlite') ? 'sqlite' : 'mysql';
        $db = ['host' => $in('db_host') ?: 'localhost', 'name' => $in('db_name'), 'user' => $in('db_user'), 'pass' => $raw('db_pass')];
        $owner = ['email' => strtolower($in('owner_email')), 'name' => $in('owner_name') ?: 'Owner', 'pass' => $raw('owner_pass')];
        $mail = ['host' => $in('mail_host'), 'port' => $in('mail_port') ?: '587', 'user' => $in('mail_user'), 'pass' => $raw('mail_pass'), 'from' => $in('mail_from')];
        $secrets = array_filter([$db['pass'], $owner['pass'], $mail['pass']], fn($s) => $s !== '');

        if ($driver === 'mysql' && ($db['name'] === '' || $db['user'] === '')) $errors[] = 'Database name and user are required.';
        if (!preg_match('/^[A-Za-z0-9._-]+(:\d+)?$/', $db['host'])) $errors[] = 'Database host is invalid.';
        if (!filter_var($owner['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Owner email is invalid.';
        if (strlen($owner['pass']) < 12) $errors[] = 'Owner password must be at least 12 characters.';
        if (!hash_equals($owner['pass'], $raw('owner_pass2'))) $errors[] = 'Owner passwords do not match.';
        if ($mail['host'] !== '' && !preg_match('/^[A-Za-z0-9.-]+$/', $mail['host'])) $errors[] = 'Mail host is invalid.';
        if (!ctype_digit($mail['port'])) $errors[] = 'Mail port must be a number.';
        if ($mail['from'] !== '' && !filter_var($mail['from'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Mail "from" address is invalid.';

        [$dbHost, $dbPort] = array_pad(explode(':', $db['host'], 2), 2, '3306');
        if (!$errors && $driver === 'mysql') {
            try {
                new PDO('mysql:host=' . $dbHost . ';port=' . $dbPort . ';dbname=' . $db['name'] . ';charset=utf8mb4', $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
                $log[] = 'Database connection OK.';
            } catch (Throwable $e) {
                $errors[] = 'Could not connect to the database. Check host, name, user and password.';
            }
        }

        if (!$errors) {
            try {
                // a. .env from .env.example (exclusive create: never overwrite an existing one)
                $tpl = (string) file_get_contents($base . '/.env.example');
                $set = [
                    'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_KEY' => '', 'APP_URL' => 'https://dba.distrosku.com',
                    'DB_CONNECTION' => $driver, 'DB_HOST' => envq($dbHost), 'DB_PORT' => $dbPort,
                    'DB_DATABASE' => $driver === 'sqlite' ? envq($base . '/database/database.sqlite') : envq($db['name']),
                    'DB_USERNAME' => envq($db['user']), 'DB_PASSWORD' => envq($db['pass']),
                    'DBA_OWNER_EMAIL' => envq($owner['email']), 'DBA_OWNER_NAME' => envq($owner['name']), 'DBA_OWNER_PASSWORD' => '',
                ];
                if ($mail['host'] !== '') {
                    $set += ['MAIL_MAILER' => 'smtp', 'MAIL_HOST' => envq($mail['host']), 'MAIL_PORT' => $mail['port'], 'MAIL_USERNAME' => envq($mail['user']), 'MAIL_PASSWORD' => envq($mail['pass'])];
                } else {
                    $set['MAIL_MAILER'] = 'log';
                }
                if ($mail['from'] !== '') $set['MAIL_FROM_ADDRESS'] = envq($mail['from']);
                foreach ($set as $k => $v) {
                    $tpl = preg_match('/^' . $k . '=.*$/m', $tpl)
                        ? preg_replace_callback('/^' . $k . '=.*$/m', fn() => $k . '=' . $v, $tpl)
                        : rtrim($tpl) . "\n" . $k . '=' . $v . "\n";
                }
                if ($driver === 'sqlite' && !is_file($base . '/database/database.sqlite')) touch($base . '/database/database.sqlite');
                $fh = @fopen($base . '/.env', 'x');
                if ($fh === false) throw new RuntimeException('Could not create laravel/.env (it may already exist, or the folder is not writable).');
                @chmod($base . '/.env', 0600);
                fwrite($fh, $tpl);
                fclose($fh);
                $log[] = '.env written (mode 600).';

                // b. storage + cache dirs writable
                foreach (['storage/app/private', 'storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $d) {
                    if (!is_dir($base . '/' . $d)) @mkdir($base . '/' . $d, 0775, true);
                }
                foreach (['storage', 'bootstrap/cache'] as $dir) {
                    @chmod($base . '/' . $dir, 0775);
                    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base . '/' . $dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
                    foreach ($it as $f) { if (!$f->isLink()) @chmod($f->getPathname(), $f->isDir() ? 0775 : 0664); }
                }
                foreach (glob($base . '/bootstrap/cache/*.php') ?: [] as $f) @unlink($f);
                $log[] = 'storage/ and bootstrap/cache/ are writable.';

                // c. boot Laravel and run the install commands
                require $base . '/vendor/autoload.php';
                $app = require $base . '/bootstrap/app.php';
                $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
                $kernel->bootstrap();
                $run = function (string $cmd, array $args = []) use ($kernel, &$log) {
                    $code = $kernel->call($cmd, $args);
                    $out = trim(preg_replace('/\e\[[\d;]*m/', '', $kernel->output()));
                    $log[] = '$ php artisan ' . $cmd . ' (exit ' . $code . ')' . ($out !== '' ? "\n" . $out : '');
                    if ($code !== 0) throw new RuntimeException('Command failed: ' . $cmd);
                };
                $run('key:generate', ['--force' => true]);
                $app->forgetInstance('encrypter');
                Illuminate\Support\Facades\Facade::clearResolvedInstance('encrypter');
                $run('migrate', ['--force' => true]);

                // d. owner (same as `php artisan dba:make-owner`, non-interactive from the posted values; the password is hashed, never written to .env)
                $u = App\Models\User::firstOrNew(['email' => $owner['email']]);
                $u->forceFill(['name' => $u->name ?: $owner['name'], 'password' => $owner['pass'], 'is_admin' => true, 'email_verified_at' => now()])->save();
                App\Models\AuditLog::record('owner_set', null, "Admin {$owner['email']} created by the web installer", user: 'setup');
                $log[] = 'Admin ' . $owner['email'] . ' is ready.';

                $run('dba:import-rules', ['--publish' => true]);
                $run('optimize');
                $run('filament:optimize');
                $done = true;
            } catch (Throwable $e) {
                $errors[] = 'Setup stopped: ' . $e->getMessage();
                if (!empty($fh) || is_file($base . '/.env')) {
                    $errors[] = 'laravel/.env was created. Fix the problem, delete laravel/.env in File Manager (do not open it), and run this page again.';
                }
            }
            if ($done && !defined('DBA_SETUP_KEEP')) {
                $deleted = @unlink(__FILE__);
            }
        }
    }
}
// never echo a posted secret, even inside an exception message
$scrub = fn(string $s): string => $secrets ? str_replace($secrets, '********', $s) : $s;
$log = array_map($scrub, $log);
$errors = array_map($scrub, $errors);
$nonce = nonce_for(time());
$v = fn($k, $d = '') => h($_POST[$k] ?? $d);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>DistroSKU DBA setup</title>
<style>body{font-family:Inter,system-ui,sans-serif;max-width:640px;margin:2rem auto;padding:0 1rem;color:#0f172a;background:#f8fafc}h1{font-size:1.4rem}fieldset{border:1px solid #e2e8f0;border-radius:.75rem;background:#fff;margin:1rem 0;padding:1rem}legend{font-weight:600;padding:0 .4rem}label{display:block;margin:.6rem 0 .2rem;font-size:.9rem;font-weight:600}input{width:100%;padding:.55rem;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:.5rem}button{margin-top:.5rem;padding:.7rem 1.4rem;background:#d97706;color:#fff;border:0;border-radius:.5rem;font-weight:600;cursor:pointer}pre{background:#0f172a;color:#e2e8f0;padding:1rem;white-space:pre-wrap;font-size:.8rem;border-radius:.5rem}.err{color:#b91c1c}.ok{color:#15803d}small{color:#64748b}</style></head><body>
<h1>DistroSKU DBA — one-time setup</h1>
<?php foreach ($errors as $e): ?><p class="err"><?= h($e) ?></p><?php endforeach; ?>
<?php if ($done): ?>
  <h2 class="ok">Setup complete</h2>
  <p><a href="https://dba.distrosku.com/login">Sign in at dba.distrosku.com/login</a></p>
  <p><?= $deleted ? 'This setup file has deleted itself.' : '<strong class="err">Could not delete this setup file. Delete it now in File Manager.</strong>' ?></p>
  <p>Next: add the cron job (Site Tools → Devs → Cron Jobs), see README_DEPLOY.md.</p>
<?php endif; ?>
<?php if ($log): ?><details<?= $done ? '' : ' open' ?>><summary>Log</summary><pre><?= h(implode("\n", $log)) ?></pre></details><?php endif; ?>
<?php if (!$done): ?>
<form method="post" action="?token=<?= h(SETUP_TOKEN) ?>" autocomplete="off">
  <input type="hidden" name="nonce" value="<?= h($nonce) ?>">
  <fieldset><legend>Database (MySQL)</legend>
  <?php if ($allowSqlite): ?><label>DB driver</label><input name="db_driver" value="<?= $v('db_driver', 'mysql') ?>"><?php endif; ?>
  <label>Host</label><input name="db_host" value="<?= $v('db_host', 'localhost') ?>">
  <label>Database name</label><input name="db_name" required value="<?= $v('db_name') ?>">
  <label>User</label><input name="db_user" value="<?= $v('db_user') ?>">
  <label>Password</label><input type="password" name="db_pass" autocomplete="new-password">
  </fieldset>
  <fieldset><legend>Owner login</legend>
  <label>Email</label><input type="email" name="owner_email" required value="<?= $v('owner_email', 'michael@brandworx.ai') ?>">
  <label>Name</label><input name="owner_name" value="<?= $v('owner_name', 'Michael') ?>">
  <label>Password <small>(12+ characters)</small></label><input type="password" name="owner_pass" minlength="12" required autocomplete="new-password">
  <label>Confirm password</label><input type="password" name="owner_pass2" minlength="12" required autocomplete="new-password">
  </fieldset>
  <fieldset><legend>Mail for alerts <small>(optional — can be added later)</small></legend>
  <label>SMTP host</label><input name="mail_host" value="<?= $v('mail_host') ?>">
  <label>Port</label><input name="mail_port" value="<?= $v('mail_port', '587') ?>">
  <label>Username</label><input name="mail_user" value="<?= $v('mail_user') ?>">
  <label>Password</label><input type="password" name="mail_pass" autocomplete="new-password">
  <label>From address</label><input type="email" name="mail_from" value="<?= $v('mail_from') ?>">
  </fieldset>
  <button type="submit">Install</button>
</form>
<?php endif; ?>
</body></html>
