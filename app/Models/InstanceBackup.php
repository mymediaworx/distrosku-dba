<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstanceBackup extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['taken_at' => 'datetime'];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }
}
