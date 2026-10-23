<?php

namespace App\Filament\Resources\InstanceResource\Pages;

use App\Filament\Resources\InstanceResource;
use App\Filament\Support\InstanceActions;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewInstance extends ViewRecord
{
    protected static string $resource = InstanceResource::class;

    public function getSubheading(): ?string
    {
        return $this->record->url.' · '.($this->record->environment === 'prod' ? 'Production' : 'Test').($this->record->client ? ' · '.$this->record->client : '');
    }

    protected function getHeaderActions(): array
    {
        return [
            InstanceActions::check(Actions\Action::class),
            InstanceActions::backup(Actions\Action::class),
            InstanceActions::suspend(Actions\Action::class),
            InstanceActions::resume(Actions\Action::class),
            Actions\ActionGroup::make([
                InstanceActions::restore(Actions\Action::class),
                InstanceActions::rotateSecret(Actions\Action::class),
                InstanceActions::upgrade(Actions\Action::class),
                Actions\EditAction::make(),
            ])->label('More')->button()->color('gray')->icon('heroicon-m-ellipsis-vertical'),
        ];
    }
}
