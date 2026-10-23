<?php

namespace App\Filament\Resources\RuleSetResource\Pages;

use App\Filament\Resources\RuleSetResource;
use App\Models\AuditLog;
use App\Models\RuleSet;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListRuleSets extends ListRecords
{
    protected static string $resource = RuleSetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('import')->label('Import JSON')->icon('heroicon-o-arrow-up-tray')->color('gray')
                ->modalDescription('Upload a file made by `php artisan erp:rules-export` on an ERP instance. It becomes the next draft version.')
                ->form([Forms\Components\FileUpload::make('file')->required()->acceptedFileTypes(['application/json', 'text/plain'])->storeFiles(false)->maxSize(4096)])
                ->action(function (array $data) {
                    try {
                        $raw = json_decode($data['file']->get(), true);
                        $sets = RuleSet::normalise((array) $raw);
                    } catch (\Throwable $e) {
                        Notification::make()->danger()->title('Import failed')->body($e->getMessage())->send();

                        return;
                    }
                    $set = RuleSet::create(['version' => RuleSet::nextVersion(), 'name' => $raw['name'] ?? 'Imported', 'payload' => $sets, 'status' => 'draft', 'created_by' => auth()->user()?->email]);
                    AuditLog::record('rules_imported', null, "Rule set v{$set->version} imported", ['counts' => $set->counts()]);
                    $this->redirect(RuleSetResource::getUrl('view', ['record' => $set]));
                }),
            Actions\CreateAction::make()->label('New version'),
        ];
    }
}
