<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/** Runs the real setup template (rendered with a test token) in a separate PHP process against a throw-away app folder. */
class SetupScriptTest extends TestCase
{
    private const TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/dba-setup-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/public_html', 0777, true);
        mkdir($this->dir.'/laravel/vendor', 0777, true);
        file_put_contents($this->dir.'/laravel/vendor/autoload.php', "<?php throw new RuntimeException('must not boot in guard tests');");
        copy(__DIR__.'/../../.env.example', $this->dir.'/laravel/.env.example');
        file_put_contents($this->dir.'/public_html/setup-'.self::TOKEN.'.php',
            str_replace('__TOKEN__', self::TOKEN, file_get_contents(__DIR__.'/../../setup/setup.tpl.php')));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    /** @return array{0:int,1:string} */
    private function hit(array $get, array $post = [], bool $https = true, string $method = 'GET'): array
    {
        $script = $this->dir.'/public_html/setup-'.self::TOKEN.'.php';
        $harness = $this->dir.'/harness.php';
        file_put_contents($harness, '<?php define("DBA_SETUP_BASE", '.var_export($this->dir.'/laravel', true).'); define("DBA_SETUP_NO_SLEEP", 1);'
            .' $_SERVER["REQUEST_METHOD"] = '.var_export($method, true).';'.($https ? ' $_SERVER["HTTPS"] = "on";' : '')
            .' $_GET = '.var_export($get, true).'; $_POST = '.var_export($post, true).';'
            .' register_shutdown_function(function () { file_put_contents("php://stderr", "CODE:".(http_response_code() ?: 200)); });'
            .' include '.var_export($script, true).';');
        $p = proc_open([PHP_BINARY, $harness], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($p);
        preg_match('/CODE:(\d+)/', $err, $m);

        return [(int) ($m[1] ?? 0), $out];
    }

    private function nonce(): string
    {
        [, $html] = $this->hit(['token' => self::TOKEN]);
        preg_match('/name="nonce" value="([^"]+)"/', $html, $m);

        return $m[1];
    }

    public function test_wrong_or_missing_token_is_a_404(): void
    {
        foreach ([[], ['token' => ''], ['token' => 'nope'], ['token' => strtoupper(self::TOKEN)], ['token' => [self::TOKEN]]] as $get) {
            [$code, $out] = $this->hit($get);
            $this->assertSame(404, $code);
            $this->assertSame('Not found', $out);
        }
    }

    public function test_the_template_sleeps_on_a_bad_token(): void
    {
        $tpl = file_get_contents(__DIR__.'/../../setup/setup.tpl.php');
        $this->assertStringContainsString("if (!defined('DBA_SETUP_NO_SLEEP')) sleep(3);", $tpl);
        $this->assertStringContainsString('hash_equals(SETUP_TOKEN, $token)', $tpl);
    }

    public function test_https_is_required(): void
    {
        [$code, $out] = $this->hit(['token' => self::TOKEN], [], false);
        $this->assertSame(403, $code);
        $this->assertStringContainsString('HTTPS required', $out);
    }

    public function test_valid_token_shows_the_form_with_a_nonce_and_defaults(): void
    {
        [$code, $html] = $this->hit(['token' => self::TOKEN]);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('one-time setup', $html);
        $this->assertMatchesRegularExpression('/name="nonce" value="\d{10}\.[a-f0-9]{64}"/', $html);
        $this->assertStringContainsString('value="michael@brandworx.ai"', $html);
        $this->assertStringContainsString('name="db_host" value="localhost"', $html);
    }

    public function test_refuses_when_env_exists(): void
    {
        file_put_contents($this->dir.'/laravel/.env', "APP_KEY=keep-me\n");
        foreach (['GET', 'POST'] as $m) {
            [$code, $out] = $this->hit(['token' => self::TOKEN], ['nonce' => 'x'], true, $m);
            $this->assertSame(409, $code);
            $this->assertStringContainsString('Already installed', $out);
        }
        $this->assertSame("APP_KEY=keep-me\n", file_get_contents($this->dir.'/laravel/.env'));
    }

    public function test_post_without_a_valid_nonce_does_nothing(): void
    {
        foreach (['', 'garbage', time().'.'.str_repeat('a', 64), (time() - 7200).'.'.hash_hmac('sha256', 'dba-setup-form|'.(time() - 7200), self::TOKEN)] as $n) {
            [, $html] = $this->hit(['token' => self::TOKEN], ['nonce' => $n, 'db_name' => 'x', 'db_user' => 'x', 'owner_email' => 'a@b.co', 'owner_pass' => str_repeat('p', 12), 'owner_pass2' => str_repeat('p', 12)], true, 'POST');
            $this->assertStringContainsString('form expired', $html);
        }
        $this->assertFileDoesNotExist($this->dir.'/laravel/.env');
    }

    public function test_validation_errors_never_echo_passwords(): void
    {
        [, $html] = $this->hit(['token' => self::TOKEN], ['nonce' => $this->nonce(), 'db_name' => 'n', 'db_user' => 'u', 'db_pass' => 'DbSecret-123!',
            'owner_email' => 'bad', 'owner_pass' => 'OwnerSecret-123', 'owner_pass2' => 'OwnerSecret-124', 'mail_pass' => 'MailSecret-1'], true, 'POST');
        $this->assertStringContainsString('Owner email is invalid', $html);
        $this->assertStringContainsString('do not match', $html);
        foreach (['DbSecret-123!', 'OwnerSecret-123', 'OwnerSecret-124', 'MailSecret-1'] as $s) {
            $this->assertStringNotContainsString($s, $html);
        }
        $this->assertFileDoesNotExist($this->dir.'/laravel/.env');
    }

    public function test_bad_database_credentials_stop_before_writing_env(): void
    {
        [, $html] = $this->hit(['token' => self::TOKEN], ['nonce' => $this->nonce(), 'db_host' => '127.0.0.1:1', 'db_name' => 'n', 'db_user' => 'u', 'db_pass' => 'DbSecret-123!',
            'owner_email' => 'a@b.co', 'owner_pass' => 'OwnerSecret-123', 'owner_pass2' => 'OwnerSecret-123'], true, 'POST');
        $this->assertStringContainsString('Could not connect to the database', $html);
        $this->assertStringNotContainsString('DbSecret-123!', $html);
        $this->assertFileDoesNotExist($this->dir.'/laravel/.env');
    }
}
