<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Instance;
use App\Models\InstanceBackup;
use App\Models\InstanceCheck;
use App\Models\User;
use App\Notifications\InstanceStatusChanged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Throwable;

/** Polls instances, records checks, flips up/down and mails admins on state changes. */
class HealthChecker
{
    public function __construct(private ErpClient $client) {}

    public function check(Instance $i, string $source = 'poll'): InstanceCheck
    {
        try {
            $s = $this->client->status($i);

            return $this->record($i, $s, $source);
        } catch (Throwable $e) {
            return $this->fail($i, mb_substr($e->getMessage(), 0, 250), $source);
        }
    }

    /** A healthy status payload (polled, or pushed by the instance). */
    public function record(Instance $i, array $s, string $source = 'poll'): InstanceCheck
    {
        $ok = ($s['db_ok'] ?? true) !== false;
        $check = $i->checks()->create([
            'ok' => $ok, 'source' => $source, 'response_ms' => $s['_ms'] ?? null, 'version' => $s['version'] ?? null,
            'queue_pending' => $s['queue']['pending'] ?? null, 'queue_failed' => $s['queue']['failed'] ?? null,
            'disk_free' => $s['disk']['free_bytes'] ?? null, 'error' => $ok ? null : 'database not reachable', 'checked_at' => now(),
        ]);
        if (! $ok) {
            return $this->fail($i, 'database not reachable', $source, $check);
        }
        $wasDown = $i->alerting;
        $lb = $s['last_backup'] ?? null;
        $i->forceFill([
            'version' => $s['version'] ?? $i->version,
            'last_check_in_at' => now(), 'last_ok_at' => now(), 'consecutive_failures' => 0, 'alerting' => false,
            'status' => ! empty($s['suspended']) ? 'suspended' : 'active',
            'suspend_reason' => ! empty($s['suspended']) ? ($s['suspended_reason'] ?? null) : null,
            'rules_version' => $s['rules_version'] ?? null,
            'last_backup_at' => isset($lb['at']) ? Carbon::parse($lb['at']) : $i->last_backup_at,
            'last_backup_status' => $lb['status'] ?? $i->last_backup_status,
            'last_status' => array_diff_key($s, array_flip(['backups', '_ms', '_status'])),
        ])->save();
        if (isset($s['backups']) && is_array($s['backups'])) {
            $this->syncBackups($i, $s['backups']);
        }
        if ($wasDown) {
            AuditLog::record('instance_up', $i, 'Instance is reachable again.', user: 'system');
            $this->notify($i, false);
        }

        return $check;
    }

    private function fail(Instance $i, string $error, string $source, ?InstanceCheck $check = null): InstanceCheck
    {
        $check ??= $i->checks()->create(['ok' => false, 'source' => $source, 'error' => $error, 'checked_at' => now()]);
        $i->forceFill(['last_check_in_at' => now(), 'consecutive_failures' => $i->consecutive_failures + 1])->save();
        if (! $i->alerting && $i->consecutive_failures >= (int) config('dba.down_after', 2)) {
            $i->forceFill(['alerting' => true, 'status' => 'down'])->save();
            AuditLog::record('instance_down', $i, $error, ok: false, user: 'system');
            $this->notify($i, true, $error);
        }

        return $check;
    }

    public function syncBackups(Instance $i, array $list): void
    {
        $seen = [];
        foreach ($list as $b) {
            if (empty($b['file'])) {
                continue;
            }
            $seen[] = $b['file'];
            InstanceBackup::updateOrCreate(['instance_id' => $i->id, 'filename' => $b['file']], [
                'size' => (int) ($b['size'] ?? 0), 'sha256' => $b['sha256'] ?? null, 'trigger' => $b['trigger'] ?? null,
                'status' => $b['status'] ?? 'present', 'taken_at' => isset($b['created_at']) ? Carbon::parse($b['created_at']) : null,
            ]);
        }
        // the ERP prunes (14 daily + 8 weekly); anything it no longer lists is gone
        $i->backups()->whereNotIn('filename', $seen ?: ['-'])->where('status', '!=', 'gone')->update(['status' => 'gone']);
    }

    private function notify(Instance $i, bool $down, ?string $error = null): void
    {
        $to = User::where('is_admin', true)->get();
        $extra = array_filter(array_map('trim', explode(',', (string) config('dba.alert_emails'))));
        try {
            Notification::send($to, new InstanceStatusChanged($i, $down, $error));
            foreach ($extra as $mail) {
                Notification::route('mail', $mail)->notify(new InstanceStatusChanged($i, $down, $error));
            }
        } catch (Throwable $e) {
            report($e); // a broken mailer must not stop polling; the dashboard still shows the alert
        }
    }

    public function prune(int $days = 30): void
    {
        InstanceCheck::where('checked_at', '<', now()->subDays($days))->delete();
    }
}
