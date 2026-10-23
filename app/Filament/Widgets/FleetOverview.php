<?php

namespace App\Filament\Widgets;

use App\Models\Instance;
use App\Models\RuleSet;
use App\Services\Releases;
use Filament\Widgets\StatsOverviewWidget as Base;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FleetOverview extends Base
{
    protected static ?int $sort = 1;

    protected static ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $all = Instance::all();
        $latest = app(Releases::class)->latest();
        $down = $all->where('status', 'down')->count();
        $staleBackups = $all->filter(fn ($i) => ! $i->last_backup_at || $i->last_backup_at->lt(now()->subHours(36)))->count();
        $rules = RuleSet::latestPublished();

        return [
            Stat::make('Instances', $all->count())->description($all->where('environment', 'prod')->count().' production · '.$all->where('environment', 'test')->count().' test')->icon('heroicon-o-server-stack'),
            Stat::make('Down', $down)->color($down ? 'danger' : 'success')->description($down ? 'Needs attention' : 'All reachable')->icon('heroicon-o-exclamation-triangle'),
            Stat::make('Suspended', $all->where('status', 'suspended')->count())->color('warning')->icon('heroicon-o-pause-circle'),
            Stat::make('Behind latest', $latest ? $all->filter(fn ($i) => $i->isBehind($latest))->count() : '—')->description($latest ? 'latest v'.$latest : 'no release info')->icon('heroicon-o-rocket-launch'),
            Stat::make('Backups > 36h old', $staleBackups)->color($staleBackups ? 'warning' : 'success')->icon('heroicon-o-archive-box'),
            Stat::make('Global rules', $rules ? 'v'.$rules->version : '—')->description($rules ? $all->where('rules_version', $rules->version)->count().' of '.$all->count().' on latest' : 'none published')->icon('heroicon-o-scale'),
        ];
    }
}
