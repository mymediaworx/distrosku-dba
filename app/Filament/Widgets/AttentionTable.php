<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\InstanceResource;
use App\Models\Instance;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class AttentionTable extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Needs attention';

    protected static ?string $pollingInterval = '60s';

    public function table(Table $table): Table
    {
        return $table
            ->query(Instance::query()->where(fn ($q) => $q->whereIn('status', ['down', 'suspended'])->orWhere('consecutive_failures', '>', 0)
                ->orWhereNull('last_backup_at')->orWhere('last_backup_at', '<', now()->subHours(36))->orWhere('last_backup_status', 'failed')))
            ->columns([
                Tables\Columns\TextColumn::make('name')->weight('semibold'),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => InstanceResource::statusColor($state)),
                Tables\Columns\TextColumn::make('issue')->state(fn (Instance $i) => collect([
                    $i->status === 'down' ? 'Not responding ('.$i->consecutive_failures.' failed checks)' : null,
                    $i->status !== 'down' && $i->consecutive_failures ? $i->consecutive_failures.' failed check(s)' : null,
                    $i->status === 'suspended' ? 'Suspended'.($i->suspend_reason ? ': '.$i->suspend_reason : '') : null,
                    ! $i->last_backup_at ? 'No backup reported' : ($i->last_backup_at->lt(now()->subHours(36)) ? 'Last backup '.$i->last_backup_at->diffForHumans() : null),
                    $i->last_backup_status === 'failed' ? 'Last backup failed' : null,
                ])->filter()->implode(' · '))->wrap(),
                Tables\Columns\TextColumn::make('last_check_in_at')->label('Last check-in')->since()->placeholder('never'),
            ])
            ->recordUrl(fn (Instance $i) => InstanceResource::getUrl('view', ['record' => $i]))
            ->paginated(false)
            ->emptyStateHeading('All good')->emptyStateIcon('heroicon-o-check-circle')->emptyStateDescription('Every instance is up and backed up.');
    }
}
