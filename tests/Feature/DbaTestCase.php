<?php

namespace Tests\Feature;

use App\Dba\Signature;
use App\Models\Instance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

abstract class DbaTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    /** @var list<array{path:string,body:array}> requests the fake ERP accepted */
    protected array $erpCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true, 'email' => 'owner@example.test']);
    }

    protected function makeInstance(array $attrs = []): Instance
    {
        return Instance::create($attrs + ['name' => 'Vega Test 1', 'slug' => 'vegatest1', 'url' => 'https://vegatest1.example.test', 'environment' => 'test']);
    }

    /**
     * A fake ERP that verifies the manager's signature exactly like the ERP does (shared Signature class, nonce replay check),
     * then answers with $responses[endpoint] (array or closure(body)).
     */
    protected function fakeErp(Instance $i, array $responses): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        $seen = [];
        Http::fake(function (Request $r) use ($i, $responses, &$seen) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            $err = Signature::check((string) $i->fresh()->secret, $r->method(), $path, $r->header('X-Dba-Timestamp')[0] ?? null,
                $r->header('X-Dba-Nonce')[0] ?? null, $r->header('X-Dba-Signature')[0] ?? null, $r->body());
            $nonce = $r->header('X-Dba-Nonce')[0] ?? '';
            if ($err === null && isset($seen[$nonce])) {
                $err = 'replayed request';
            }
            if ($err !== null) {
                return Http::response(['ok' => false, 'error' => $err], 401);
            }
            $seen[$nonce] = true;
            $ep = substr($path, strlen('/dba-api/'));
            $body = json_decode($r->body(), true) ?? [];
            $this->erpCalls[] = ['path' => $ep, 'body' => $body];
            $res = $responses[$ep] ?? null;
            if ($res === null) {
                return Http::response(['ok' => false, 'error' => 'not found'], 404);
            }
            $res = is_callable($res) ? $res($body) : $res;

            return $res instanceof \GuzzleHttp\Promise\PromiseInterface ? $res : Http::response($res, 200);
        });
    }

    protected function erpStatus(array $over = []): array
    {
        return array_replace_recursive([
            'app' => 'distrosku-erp', 'version' => '0.20.8', 'instance' => 'vegatest1', 'db_ok' => true, 'suspended' => false, 'suspended_reason' => null,
            'queue' => ['pending' => 2, 'failed' => 0], 'disk' => ['free_bytes' => 5_000_000_000, 'total_bytes' => 10_000_000_000],
            'rules_version' => null, 'last_backup' => ['status' => 'ok', 'at' => now()->subHours(3)->toIso8601String(), 'file' => 'vegatest1-a.zip'],
            'backups' => [
                ['file' => 'vegatest1-20261022-013000-nightly.zip', 'size' => 1234, 'sha256' => str_repeat('a', 64), 'created_at' => now()->subHours(3)->toIso8601String(), 'trigger' => 'nightly', 'status' => 'present'],
            ],
        ], $over);
    }
}
