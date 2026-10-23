<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Latest ERP release: GitHub tags when DBA_GITHUB_REPO (+ optional token) is set, else DBA_LATEST_VERSION / the newest instance version. */
class Releases
{
    /** @return list<string> newest first */
    public function tags(): array
    {
        $repo = config('dba.github.repo');
        if (! $repo) {
            return [];
        }

        return Cache::remember('dba.github.tags', 900, function () use ($repo) {
            try {
                $req = Http::acceptJson()->timeout(10)->withUserAgent('distrosku-dba');
                if ($t = config('dba.github.token')) {
                    $req = $req->withToken($t);
                }
                $names = collect($req->get("https://api.github.com/repos/{$repo}/tags", ['per_page' => 50])->json() ?? [])
                    ->pluck('name')->filter(fn ($n) => is_string($n) && preg_match('/^v?\d+\.\d+\.\d+$/', $n))->values()->all();
                usort($names, fn ($a, $b) => version_compare(ltrim($b, 'v'), ltrim($a, 'v')));

                return $names;
            } catch (Throwable) {
                return [];
            }
        });
    }

    public function latest(): ?string
    {
        $tags = $this->tags();
        if ($tags) {
            return ltrim($tags[0], 'v');
        }
        if ($v = config('dba.latest_version')) {
            return ltrim($v, 'v');
        }
        $versions = \App\Models\Instance::whereNotNull('version')->pluck('version')->all();
        usort($versions, fn ($a, $b) => version_compare($b, $a));

        return $versions[0] ?? null;
    }
}
