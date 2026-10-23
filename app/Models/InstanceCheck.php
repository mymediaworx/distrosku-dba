<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstanceCheck extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ok' => 'boolean', 'checked_at' => 'datetime'];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }
}
