<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'ok' => 'boolean', 'created_at' => 'datetime'];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }

    public static function record(string $event, ?Instance $instance = null, ?string $description = null, array $meta = [], bool $ok = true, ?string $user = null): self
    {
        return static::create([
            'event' => $event,
            'instance_id' => $instance?->id,
            'user' => $user ?? auth()->user()?->email ?? (app()->runningInConsole() ? 'system' : null),
            'ip' => app()->runningInConsole() ? null : request()->ip(),
            'ok' => $ok,
            'description' => $description,
            'meta' => $meta ?: null,
        ]);
    }
}
