<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Instance extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'last_status' => 'array',
            'last_check_in_at' => 'datetime',
            'last_ok_at' => 'datetime',
            'last_backup_at' => 'datetime',
            'alerting' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Instance $i) {
            $i->slug = $i->slug ?: Str::slug($i->name);
            $i->secret = $i->secret ?: self::newSecret();
            $i->url = rtrim($i->url, '/');
        });
    }

    public static function newSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function checks(): HasMany
    {
        return $this->hasMany(InstanceCheck::class);
    }

    public function backups(): HasMany
    {
        return $this->hasMany(InstanceBackup::class);
    }

    public function pushes(): HasMany
    {
        return $this->hasMany(RulePush::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function isBehind(?string $latest): bool
    {
        return $latest && $this->version && version_compare(ltrim($this->version, 'v'), ltrim($latest, 'v'), '<');
    }
}
