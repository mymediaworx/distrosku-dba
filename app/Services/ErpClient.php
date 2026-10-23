<?php

namespace App\Services;

use App\Dba\Signature;
use App\Models\Instance;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Signed calls to an ERP instance's /dba-api/* endpoints (POST, HMAC-SHA256, per-instance secret). */
class ErpClient
{
    public function call(Instance $instance, string $endpoint, array $body = [], ?int $timeout = null): array
    {
        $path = '/dba-api/'.ltrim($endpoint, '/');
        $json = json_encode((object) $body, JSON_UNESCAPED_SLASHES);
        $headers = Signature::headers((string) $instance->secret, 'POST', $path, $json);
        $started = microtime(true);
        $res = Http::withHeaders($headers + ['Accept' => 'application/json', 'User-Agent' => 'distrosku-dba/'.config('dba.version')])
            ->withBody($json, 'application/json')
            ->timeout($timeout ?? (int) config('dba.http_timeout', 20))
            ->connectTimeout(10)
            ->post($instance->url.$path);
        $data = $res->json();
        if (! is_array($data)) {
            throw new RuntimeException("{$instance->name}: HTTP {$res->status()} (not a manager API response)");
        }
        $data['_ms'] = (int) round((microtime(true) - $started) * 1000);
        $data['_status'] = $res->status();
        if (! $res->successful() || ($data['ok'] ?? true) === false) {
            throw new RuntimeException("{$instance->name}: ".($data['error'] ?? 'HTTP '.$res->status()));
        }

        return $data;
    }

    public function status(Instance $i): array
    {
        return $this->call($i, 'status', [], 15);
    }

    public function by(): ?string
    {
        return auth()->user()?->email ?? 'manager';
    }

    public function backup(Instance $i): array
    {
        return $this->call($i, 'backup', ['by' => $this->by()], 600)['backup'];
    }

    public function backups(Instance $i, bool $verify = false): array
    {
        return $this->call($i, 'backups', ['verify' => $verify], $verify ? 300 : 30)['backups'];
    }

    public function restore(Instance $i, string $file): array
    {
        return $this->call($i, 'restore', ['file' => basename($file), 'confirm' => $i->slug, 'by' => $this->by()], 900)['result'];
    }

    public function suspend(Instance $i, ?string $reason): void
    {
        $this->call($i, 'suspend', ['reason' => $reason, 'by' => $this->by()]);
    }

    public function resume(Instance $i): void
    {
        $this->call($i, 'resume', ['by' => $this->by()]);
    }

    public function rulesPreview(Instance $i, array $ruleset): array
    {
        return $this->call($i, 'rules/preview', ['ruleset' => $ruleset], 60)['preview'];
    }

    public function rulesApply(Instance $i, array $ruleset): array
    {
        return $this->call($i, 'rules/apply', ['ruleset' => $ruleset, 'by' => $this->by()], 120)['result'];
    }

    public function rulesExport(Instance $i): array
    {
        return $this->call($i, 'rules/export', [], 60)['ruleset'];
    }
}
