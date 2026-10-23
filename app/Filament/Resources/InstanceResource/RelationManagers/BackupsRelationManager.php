<?php

namespace App\Filament\Resources\InstanceResource\RelationManagers;

use App\Models\AuditLog;
use App\Services\ErpClient;
use App\Services\HealthChecker;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Throwable;

class BackupsRelationManager extends RelationManager
{
    protected static string $relationship = 'backups';

    protected static ?string $icon = 'heroicon-o-archive-box';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('taken_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->where('status', '!=', 'gone'))
            ->columns([
                Tables\Columns\TextColumn::make('taken_at')->label('Taken')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('trigger')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('size')->formatStateUsing(fn ($state) => \Illuminate\Support\Number::fileSize((int) $state, 1)),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => match ($state) { 'verified' => 'success', 'corrupt' => 'danger', default => 'gray' }),
                Tables\Columns\TextColumn::make('filename')->toggleable(isToggledHiddenByDefault: true)->copyable(),
                Tables\Columns\TextColumn::make('sha256')->label('SHA-256')->limit(16)->copyable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Tables\Actions\Action::make('verify')->label('Verify checksums')->icon('heroicon-o-shield-check')->color('gray')
                    ->action(function () {
                        try {
                            $list = app(ErpClient::class)->backups($this->ownerRecord, true);
                            app(HealthChecker::class)->syncBackups($this->ownerRecord, $list);
                            $bad = collect($list)->where('status', '!=', 'verified')->count();
                            Notification::make()->{$bad ? 'danger' : 'success'}()->title($bad ? "{$bad} backup(s) failed verification" : 'All backups verified')->send();
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title('Could not list backups')->body($e->getMessage())->send();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('restore')->label('Restore')->icon('heroicon-o-arrow-uturn-left')->color('danger')
                    ->modalHeading(fn ($record) => 'Restore '.$record->filename)
                    ->modalDescription('The instance verifies the checksum and takes a safety backup first. Data and storage files are replaced; .env is not touched.')
                    ->form([\Filament\Forms\Components\TextInput::make('confirm')->label('Type the instance id to confirm')->required()
                        ->rule(fn () => 'in:'.$this->ownerRecord->slug)->validationMessages(['in' => 'Type the instance id exactly.'])])
                    ->action(function ($record) {
                        $i = $this->ownerRecord;
                        try {
                            $r = app(ErpClient::class)->restore($i, $record->filename);
                            AuditLog::record('restore', $i, "Restored {$record->filename} (safety backup {$r['safety_backup']})", $r);
                            app(HealthChecker::class)->check($i, 'manual');
                            Notification::make()->success()->persistent()->title('Restore complete')->body("{$r['rows']} rows restored. Safety backup: {$r['safety_backup']}")->send();
                        } catch (Throwable $e) {
                            AuditLog::record('restore', $i, 'Restore failed: '.$e->getMessage(), ['file' => $record->filename], false);
                            Notification::make()->danger()->persistent()->title('Restore failed')->body($e->getMessage())->send();
                        }
                    }),
            ])
            ->emptyStateHeading('No backups reported yet')
            ->emptyStateDescription('Backups appear after the next check-in. Nightly backups run at 01:30 on the instance.');
    }
}
