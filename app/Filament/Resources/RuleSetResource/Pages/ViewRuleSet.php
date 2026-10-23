<?php

namespace App\Filament\Resources\RuleSetResource\Pages;

use App\Filament\Resources\RuleSetResource;
use App\Models\AuditLog;
use App\Models\RuleSet;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewRuleSet extends ViewRecord
{
    protected static string $resource = RuleSetResource::class;

    public function getTitle(): string
    {
        return 'Rule set v'.$this->record->version.' — '.$this->record->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('publish')->label('Publish')->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn () => $this->record->status === 'draft')->requiresConfirmation()
                ->modalDescription('Published versions are immutable and can be pushed to instances.')
                ->action(function () {
                    $this->record->update(['status' => 'published', 'published_at' => now()]);
                    AuditLog::record('rules_published', null, "Rule set v{$this->record->version} published", ['counts' => $this->record->counts()]);
                }),
            Actions\Action::make('push')->label('Preview & push')->icon('heroicon-o-paper-airplane')
                ->visible(fn () => $this->record->status === 'published')
                ->url(fn () => PushRuleSet::getUrl(['record' => $this->record])),
            Actions\Action::make('download')->label('Download JSON')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->action(fn () => response()->streamDownload(fn () => print (json_encode($this->record->toRulesPayload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)), 'rules-v'.$this->record->version.'.json')),
            Actions\EditAction::make()->visible(fn () => $this->record->status === 'draft'),
        ];
    }
}
