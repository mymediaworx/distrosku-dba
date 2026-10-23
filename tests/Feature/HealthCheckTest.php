<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Notifications\InstanceStatusChanged;
use App\Services\HealthChecker;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

class HealthCheckTest extends DbaTestCase
{
    public function test_poll_signs_requests_and_records_health(): void
    {
        $i = $this->makeInstance();
        $this->fakeErp($i, ['status' => $this->erpStatus(['rules_version' => 1])]);
        $this->artisan('dba:poll')->assertSuccessful();
        $i->refresh();
        $this->assertSame('active', $i->status);
        $this->assertSame('0.20.8', $i->version);
        $this->assertSame(1, $i->rules_version);
        $this->assertSame('ok', $i->last_backup_status);
        $this->assertSame(2, $i->checks()->first()->queue_pending);
        $this->assertSame(5_000_000_000, $i->checks()->first()->disk_free);
        $this->assertSame('status', $this->erpCalls[0]['path']);
    }

    public function test_a_wrong_secret_is_a_failed_check(): void
    {
        $i = $this->makeInstance();
        $this->fakeErp($i, ['status' => $this->erpStatus()]);
        $stale = $i->fresh(); // in-memory copy keeps signing with the old secret
        $i->forceFill(['secret' => str_repeat('b', 64)])->save(); // ERP now expects a different secret
        $c = app(HealthChecker::class)->check($stale);
        $this->assertFalse($c->ok);
        $this->assertStringContainsString('bad signature', $c->error);
    }

    public function test_down_after_two_failures_alerts_once_and_recovery_alerts(): void
    {
        Notification::fake();
        $i = $this->makeInstance();
        Http::fake(['*' => Http::response('Service Unavailable', 503)]);
        $h = app(HealthChecker::class);
        $h->check($i);
        $this->assertSame('active', $i->fresh()->status);
        Notification::assertNothingSent();
        $h->check($i->fresh());
        $h->check($i->fresh());
        $this->assertSame('down', $i->fresh()->status);
        Notification::assertSentTo($this->admin, InstanceStatusChanged::class, fn ($n) => $n->down);
        Notification::assertSentToTimes($this->admin, InstanceStatusChanged::class, 1);
        $this->assertSame(1, AuditLog::where('event', 'instance_down')->count());

        $this->fakeErp($i, ['status' => $this->erpStatus()]);
        $h->check($i->fresh());
        $this->assertSame('active', $i->fresh()->status);
        $this->assertSame(0, $i->fresh()->consecutive_failures);
        Notification::assertSentToTimes($this->admin, InstanceStatusChanged::class, 2);
        $this->assertSame(1, AuditLog::where('event', 'instance_up')->count());
        $mail = (new InstanceStatusChanged($i->fresh(), true, 'HTTP 503'))->toMail($this->admin);
        $this->assertStringContainsString('[DOWN]', $mail->subject);
    }

    public function test_suspended_state_and_backup_list_sync(): void
    {
        $i = $this->makeInstance();
        $this->fakeErp($i, ['status' => $this->erpStatus(['suspended' => true, 'suspended_reason' => 'Billing'])]);
        app(HealthChecker::class)->check($i);
        $this->assertSame('suspended', $i->fresh()->status);
        $this->assertSame('Billing', $i->fresh()->suspend_reason);
        $this->assertSame('present', $i->backups()->first()->status);
        app(HealthChecker::class)->syncBackups($i, []);
        $this->assertSame('gone', $i->backups()->first()->status);
    }
}
