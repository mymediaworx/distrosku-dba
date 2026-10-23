<?php

namespace App\Services;

use App\Models\RuleSet;

class RuleDiff
{
    /** @return array<string, array{added:list<string>, changed:list<string>, unchanged:int, dropped:list<string>}> */
    public static function between(?RuleSet $old, RuleSet $new): array
    {
        $out = [];
        foreach (array_keys(RuleSet::SETS) as $k) {
            $o = collect($old?->payload[$k] ?? [])->keyBy('code');
            $n = collect($new->payload[$k] ?? [])->keyBy('code');
            $out[$k] = [
                'added' => $n->keys()->diff($o->keys())->values()->all(),
                'changed' => $n->filter(fn ($r, $c) => $o->has($c) && $o[$c] != $r)->keys()->values()->all(),
                'unchanged' => $n->filter(fn ($r, $c) => $o->has($c) && $o[$c] == $r)->count(),
                // rows missing from a newer set are NOT deleted on instances; listed so it's visible
                'dropped' => $o->keys()->diff($n->keys())->values()->all(),
            ];
        }

        return $out;
    }
}
