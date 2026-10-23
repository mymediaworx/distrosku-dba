<?php

namespace App\Filament\Resources\InstanceResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ChecksRelationManager extends RelationManager
{
    protected static string $relationship = 'checks';

    protected static ?string $title = 'Health checks';

    protected static ?string $icon = 'heroicon-o-heart';

    public function table(Table $table): Table
    {
        return $table->defaultSort('checked_at', 'desc')->paginated([10, 25, 50])
            ->columns([
                Tables\Columns\TextColumn::make('checked_at')->label('When')->dateTime()->sortable(),
                Tables\Columns\IconColumn::make('ok')->boolean(),
                Tables\Columns\TextColumn::make('source')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('response_ms')->label('ms')->numeric(),
                Tables\Columns\TextColumn::make('version')->visibleFrom('md'),
                Tables\Columns\TextColumn::make('queue_pending')->label('Queue')->visibleFrom('md'),
                Tables\Columns\TextColumn::make('queue_failed')->label('Failed')->visibleFrom('md'),
                Tables\Columns\TextColumn::make('error')->wrap()->color('danger'),
            ]);
    }
}
