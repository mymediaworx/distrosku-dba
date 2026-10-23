<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RuleSet extends Model
{
    public const FORMAT = 'distrosku-rules/1';

    public const SETS = ['license_types' => 'License types', 'eligibility_rules' => 'Eligibility rules', 'compliance_rules' => 'Compliance rules', 'tax_rates' => 'Tax rates'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'published_at' => 'datetime'];
    }

    public function pushes(): HasMany
    {
        return $this->hasMany(RulePush::class);
    }

    public static function latestPublished(): ?self
    {
        return static::where('status', 'published')->orderByDesc('version')->first();
    }

    public static function nextVersion(): int
    {
        return (int) static::max('version') + 1;
    }

    /** The wire format sent to an ERP instance. */
    public function toRulesPayload(): array
    {
        return ['format' => self::FORMAT, 'version' => $this->version, 'name' => $this->name, 'sets' => $this->payload];
    }

    public function counts(): array
    {
        return collect(self::SETS)->map(fn ($l, $k) => count($this->payload[$k] ?? []))->all();
    }

    /** Validate + normalise an ERP export (distrosku-rules/1). */
    public static function normalise(array $data): array
    {
        if (($data['format'] ?? null) !== self::FORMAT || ! is_array($data['sets'] ?? null)) {
            throw new \InvalidArgumentException('Not a '.self::FORMAT.' file.');
        }
        $sets = [];
        foreach (array_keys(self::SETS) as $k) {
            $sets[$k] = [];
            $seen = [];
            foreach ($data['sets'][$k] ?? [] as $row) {
                $code = (string) ($row['code'] ?? '');
                if ($code === '' || ! is_array($row['data'] ?? null)) {
                    throw new \InvalidArgumentException("{$k}: every row needs a code and data.");
                }
                if (isset($seen[$code])) {
                    throw new \InvalidArgumentException("{$k}: duplicate code {$code}.");
                }
                $seen[$code] = true;
                $sets[$k][] = ['code' => $code, 'overridable' => (bool) ($row['overridable'] ?? false), 'data' => $row['data']];
            }
        }

        return $sets;
    }
}
