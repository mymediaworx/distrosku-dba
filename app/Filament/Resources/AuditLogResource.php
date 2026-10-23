<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Audit log';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('event')->badge()->color(fn ($state) => match (true) {
                    in_array($state, ['suspend', 'instance_down', 'check_in_rejected'], true) => 'warning',
                    in_array($state, ['restore', 'instance_removed'], true) => 'danger',
                    in_array($state, ['resume', 'instance_up'], true) => 'success',
                    default => 'gray',
                })->searchable(),
                Tables\Columns\IconColumn::make('ok')->boolean(),
                Tables\Columns\TextColumn::make('instance.name')->label('Instance')->placeholder('—'),
                Tables\Columns\TextColumn::make('user')->placeholder('—')->visibleFrom('md'),
                Tables\Columns\TextColumn::make('description')->wrap()->limit(140)->searchable(),
                Tables\Columns\TextColumn::make('ip')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('event')->options(fn () => AuditLog::query()->distinct()->orderBy('event')->pluck('event', 'event')->all()),
                Tables\Filters\SelectFilter::make('instance')->relationship('instance', 'name'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAuditLogs::route('/')];
    }
}
