<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InstanceResource\Pages;
use App\Filament\Resources\InstanceResource\RelationManagers;
use App\Filament\Support\InstanceActions;
use App\Models\Instance;
use App\Services\Releases;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class InstanceResource extends Resource
{
    protected static ?string $model = Instance::class;

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';

    protected static ?string $navigationGroup = 'Instances';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationBadge(): ?string
    {
        $down = Instance::where('status', 'down')->count();

        return $down ? (string) $down : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function statusColor(?string $s): string
    {
        return match ($s) { 'active' => 'success', 'suspended' => 'warning', 'down' => 'danger', default => 'gray' };
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Instance')->columns(2)->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(80)
                    ->live(onBlur: true)->afterStateUpdated(fn ($state, Forms\Set $set, ?Instance $record) => $record ? null : $set('slug', \Illuminate\Support\Str::slug($state))),
                Forms\Components\TextInput::make('slug')->label('Instance id')->required()->maxLength(60)->alphaDash()->unique(ignoreRecord: true)
                    ->helperText('Must match ERP_INSTANCE on the ERP (used for backup folders and restore confirmation).'),
                Forms\Components\TextInput::make('url')->label('URL')->required()->url()->placeholder('https://vegatest1.distrosku.com')->maxLength(200),
                Forms\Components\Select::make('environment')->options(['prod' => 'Production', 'test' => 'Test'])->required()->default('test')->native(false),
                Forms\Components\TextInput::make('client')->maxLength(120),
                Forms\Components\Textarea::make('notes')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $latest = app(Releases::class)->latest();

        return $table
            ->defaultSort('name')
            ->poll('60s')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable()->weight('semibold')
                    ->description(fn (Instance $r) => preg_replace('#^https?://#', '', $r->url)),
                Tables\Columns\TextColumn::make('environment')->label('Env')->badge()->color(fn ($state) => $state === 'prod' ? 'primary' : 'gray'),
                Tables\Columns\TextColumn::make('client')->searchable()->toggleable()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => self::statusColor($state))->sortable(),
                Tables\Columns\TextColumn::make('version')->badge()
                    ->color(fn (Instance $r) => $r->isBehind($latest) ? 'warning' : 'gray')
                    ->tooltip(fn (Instance $r) => $r->isBehind($latest) ? "Behind latest ({$latest})" : null),
                Tables\Columns\TextColumn::make('last_check_in_at')->label('Last check-in')->since()->sortable()->visibleFrom('md')
                    ->tooltip(fn (Instance $r) => $r->last_check_in_at?->toDayDateTimeString()),
                Tables\Columns\TextColumn::make('last_backup_at')->label('Last backup')->since()->sortable()->visibleFrom('lg')
                    ->color(fn (Instance $r) => ! $r->last_backup_at || $r->last_backup_at->lt(now()->subHours(36)) ? 'danger' : null)
                    ->placeholder('never'),
                Tables\Columns\TextColumn::make('rules_version')->label('Rules')->prefix('v')->placeholder('—')->visibleFrom('lg'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['active' => 'Active', 'suspended' => 'Suspended', 'down' => 'Down']),
                Tables\Filters\SelectFilter::make('environment')->options(['prod' => 'Production', 'test' => 'Test']),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->iconButton(),
                Tables\Actions\ActionGroup::make([
                    InstanceActions::check(Tables\Actions\Action::class),
                    InstanceActions::backup(Tables\Actions\Action::class),
                    InstanceActions::suspend(Tables\Actions\Action::class),
                    InstanceActions::resume(Tables\Actions\Action::class),
                    Tables\Actions\EditAction::make(),
                ]),
            ])
            ->emptyStateHeading('No instances yet')
            ->emptyStateDescription('Register an ERP instance to start monitoring it.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Health')->columns(['default' => 2, 'lg' => 4])->schema([
                Infolists\Components\TextEntry::make('status')->badge()->color(fn ($state) => self::statusColor($state)),
                Infolists\Components\TextEntry::make('version')->badge()->placeholder('unknown'),
                Infolists\Components\TextEntry::make('last_check_in_at')->label('Last check-in')->since()->placeholder('never'),
                Infolists\Components\TextEntry::make('last_ok_at')->label('Last OK')->since()->placeholder('never'),
                Infolists\Components\TextEntry::make('last_status.queue.pending')->label('Queue pending')->placeholder('—'),
                Infolists\Components\TextEntry::make('last_status.queue.failed')->label('Failed jobs')->placeholder('—')
                    ->color(fn ($state) => $state ? 'danger' : null),
                Infolists\Components\TextEntry::make('last_status.disk.free_bytes')->label('Disk free')->placeholder('—')
                    ->formatStateUsing(fn ($state) => $state ? \Illuminate\Support\Number::fileSize((int) $state, 1) : '—'),
                Infolists\Components\TextEntry::make('consecutive_failures')->label('Failed checks in a row'),
                Infolists\Components\TextEntry::make('suspend_reason')->label('Suspended because')->visible(fn (Instance $r) => $r->status === 'suspended')->columnSpanFull(),
            ]),
            Infolists\Components\Section::make('Details')->columns(['default' => 2, 'lg' => 4])->collapsible()->schema([
                Infolists\Components\TextEntry::make('url')->url(fn ($state) => $state, true)->columnSpan(2),
                Infolists\Components\TextEntry::make('slug')->label('Instance id')->copyable(),
                Infolists\Components\TextEntry::make('environment')->badge(),
                Infolists\Components\TextEntry::make('client')->placeholder('—'),
                Infolists\Components\TextEntry::make('rules_version')->label('Rules version')->prefix('v')->placeholder('none pushed'),
                Infolists\Components\TextEntry::make('last_backup_at')->label('Last backup')->dateTime()->placeholder('never'),
                Infolists\Components\TextEntry::make('last_backup_status')->label('Backup status')->badge()->placeholder('—')
                    ->color(fn ($state) => $state === 'ok' ? 'success' : 'danger'),
                Infolists\Components\TextEntry::make('last_status.php')->label('PHP')->placeholder('—'),
                Infolists\Components\TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\BackupsRelationManager::class, RelationManagers\ChecksRelationManager::class, RelationManagers\AuditsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInstances::route('/'),
            'create' => Pages\CreateInstance::route('/create'),
            'view' => Pages\ViewInstance::route('/{record}'),
            'edit' => Pages\EditInstance::route('/{record}/edit'),
        ];
    }
}
