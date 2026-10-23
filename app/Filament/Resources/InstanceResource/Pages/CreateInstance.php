<?php

namespace App\Filament\Resources\InstanceResource\Pages;

use App\Filament\Resources\InstanceResource;
use App\Filament\Support\InstanceActions;
use App\Models\AuditLog;
use Filament\Resources\Pages\CreateRecord;

class CreateInstance extends CreateRecord
{
    protected static string $resource = InstanceResource::class;

    protected function afterCreate(): void
    {
        AuditLog::record('instance_registered', $this->record, "Registered {$this->record->url}");
        InstanceActions::showSecret($this->record, 'Link secret');
    }

    protected function getCreatedNotification(): ?\Filament\Notifications\Notification
    {
        return null;
    }

    protected function getRedirectUrl(): string
    {
        return InstanceResource::getUrl('view', ['record' => $this->record]);
    }
}
