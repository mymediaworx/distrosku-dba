<?php

namespace App\Filament\Resources\InstanceResource\Pages;

use App\Filament\Resources\InstanceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInstances extends ListRecords
{
    protected static string $resource = InstanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('pollAll')->label('Check all')->icon('heroicon-o-signal')->color('gray')
                ->action(function () {
                    \Illuminate\Support\Facades\Artisan::call('dba:poll');
                    \Filament\Notifications\Notification::make()->success()->title('All instances checked')->send();
                }),
            Actions\CreateAction::make()->label('Register instance'),
        ];
    }
}
