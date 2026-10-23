<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RulePush extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ok' => 'boolean', 'result' => 'array'];
    }

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(RuleSet::class);
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }
}
