<?php

namespace Tests\Feature;

use App\Dba\Signature;
use Illuminate\Support\Facades\DB;

class CheckInSignatureTest extends DbaTestCase
{
    private function push(string $slug, array $body, string $secret, ?int $ts = null, ?array $headers = null)
    {
        $json = json_encode($body);
        $h = $headers ?? Signature::headers($secret, 'POST', "/api/check-in/{$slug}", $json, $ts);

        return $this->call('POST', "/api/check-in/{$slug}", [], [], [], $this->transformHeadersToServerVars($h + ['Content-Type' => 'application/json', 'Accept' => 'application/json']), $json);
    }

    public function test_secret_is_random_and_stored_encrypted(): void
    {
        $i = $this->makeInstance();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $i->secret);
        $raw = DB::table('instances')->value('secret');
        $this->assertNotSame($i->secret, $raw);
        $this->assertStringNotContainsString($i->secret, $raw);
        $this->assertArrayNotHasKey('secret', $i->toArray());
    }

    public function test_valid_check_in_updates_the_instance(): void
    {
        $i = $this->makeInstance();
        $this->push('vegatest1', $this->erpStatus(), $i->secret)->assertOk()->assertJsonPath('status', 'active');
        $i->refresh();
        $this->assertSame('0.20.8', $i->version);
        $this->assertNotNull($i->last_check_in_at);
        $this->assertSame(1, $i->backups()->count());
        $this->assertSame('push', $i->checks()->first()->source);
    }

    public function test_bad_stale_replayed_and_unknown_requests_are_rejected(): void
    {
        $i = $this->makeInstance();
        $this->push('vegatest1', $this->erpStatus(), str_repeat('0', 64))->assertStatus(401)->assertJsonPath('error', 'bad signature');
        $this->push('vegatest1', $this->erpStatus(), $i->secret, time() - 301)->assertStatus(401)->assertJsonPath('error', 'stale timestamp');
        $this->push('vegatest1', $this->erpStatus(), $i->secret, time() + 301)->assertStatus(401)->assertJsonPath('error', 'stale timestamp');
        $this->push('nope', $this->erpStatus(), $i->secret)->assertStatus(401);
        $this->postJson('/api/check-in/vegatest1', $this->erpStatus())->assertStatus(401)->assertJsonPath('error', 'missing signature headers');
        // tampered body
        $h = Signature::headers($i->secret, 'POST', '/api/check-in/vegatest1', json_encode($this->erpStatus()));
        $this->push('vegatest1', $this->erpStatus(['version' => '9.9.9']), $i->secret, null, $h)->assertStatus(401);
        $this->assertNull($i->fresh()->version);
        // replay
        $h = Signature::headers($i->secret, 'POST', '/api/check-in/vegatest1', json_encode($this->erpStatus()));
        $this->push('vegatest1', $this->erpStatus(), $i->secret, null, $h)->assertOk();
        $this->push('vegatest1', $this->erpStatus(), $i->secret, null, $h)->assertStatus(401)->assertJsonPath('error', 'replayed request');
        $this->assertDatabaseHas('audit_logs', ['event' => 'check_in_rejected', 'description' => 'replayed request']);
    }

    public function test_signature_helper_rejects_malformed_headers(): void
    {
        $this->assertSame('not linked', Signature::check('', 'POST', '/x', '1', 'abcdabcdabcdabcd', 'f', ''));
        $this->assertSame('missing signature headers', Signature::check('s', 'POST', '/x', 'abc', 'abcdabcdabcdabcd', 'f', ''));
        $this->assertSame('missing signature headers', Signature::check('s', 'POST', '/x', (string) time(), 'short', 'f', ''));
        $h = Signature::headers('s', 'POST', '/x', 'body');
        $this->assertNull(Signature::check('s', 'post', 'x', $h['X-Dba-Timestamp'], $h['X-Dba-Nonce'], strtoupper($h['X-Dba-Signature']), 'body'));
        $this->assertSame('bad signature', Signature::check('s', 'GET', '/x', $h['X-Dba-Timestamp'], $h['X-Dba-Nonce'], $h['X-Dba-Signature'], 'body'));
    }
}
