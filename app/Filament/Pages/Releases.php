<?php

namespace App\Filament\Pages;

use App\Models\Instance;
use App\Services\Releases as ReleaseService;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class Releases extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-rocket-launch';

    protected static ?string $navigationGroup = 'Instances';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.releases';

    public function getViewData(): array
    {
        $r = app(ReleaseService::class);

        return ['latest' => $r->latest(), 'tags' => array_slice($r->tags(), 0, 8), 'source' => $r->tags() ? 'GitHub tags' : (config('dba.latest_version') ? 'DBA_LATEST_VERSION' : 'newest instance version')];
    }

    public function table(Table $table): Table
    {
        $latest = app(ReleaseService::class)->latest();

        return $table->query(Instance::query())->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->weight('semibold')->searchable(),
                Tables\Columns\TextColumn::make('environment')->label('Env')->badge()->color(fn ($state) => $state === 'prod' ? 'primary' : 'gray'),
                Tables\Columns\TextColumn::make('version')->badge()->placeholder('unknown')
                    ->color(fn (Instance $r) => $r->isBehind($latest) ? 'warning' : 'success'),
                Tables\Columns\TextColumn::make('behind')->label('Status')->badge()
                    ->state(fn (Instance $r) => ! $r->version ? 'unknown' : ($r->isBehind($latest) ? 'behind '.$latest : 'current'))
                    ->color(fn ($state) => str_starts_with($state, 'behind') ? 'warning' : ($state === 'current' ? 'success' : 'gray')),
                Tables\Columns\TextColumn::make('rules_version')->label('Rules')->prefix('v')->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\Action::make('upgrade')->label('Upgrade')->icon('heroicon-o-arrow-up-circle')->color('gray')
                    ->disabled()->tooltip('One-click upgrade is planned for v2 (designed: backup → upload → upgrade page → verify → restore on failure)'),
            ])
            ->filters([Tables\Filters\Filter::make('behind')->label('Behind only')
                ->query(fn ($query) => $latest ? $query->whereNotNull('version')->get()->filter(fn ($i) => $i->isBehind($latest))->pluck('id')->pipe(fn ($ids) => $query->whereIn('id', $ids)) : $query)]);
    }
}
