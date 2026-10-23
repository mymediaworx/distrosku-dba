<?php

namespace App\Filament\Resources\InstanceResource\Pages;

use App\Filament\Resources\InstanceResource;
use App\Models\AuditLog;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditInstance extends EditRecord
{
    protected static string $resource = InstanceResource::class;

    protected function afterSave(): void
    {
        AuditLog::record('instance_updated', $this->record, 'Details updated', ['changed' => array_keys($this->record->getChanges())]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('Remove from manager')
                ->modalDescription('Removes the instance from this manager only (its history here too). The ERP itself and its data are not touched.')
                ->before(fn ($record) => AuditLog::record('instance_removed', null, "Removed {$record->name} ({$record->url}) from the manager")),
        ];
    }
}
