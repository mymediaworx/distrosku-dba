<?php

namespace App\Filament\Resources\RuleSetResource\Pages;

use App\Filament\Resources\RuleSetResource;
use App\Models\RuleSet;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRuleSet extends EditRecord
{
    protected static string $resource = RuleSetResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        abort_unless($this->record->status === 'draft', 403, 'Published rule sets are immutable; create a new version.');
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['sets'] = collect(RuleSet::SETS)->map(fn ($l, $k) => json_encode($this->record->payload[$k] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['payload'] = RuleSet::normalise(['format' => RuleSet::FORMAT, 'sets' => collect($data['sets'])->map(fn ($j) => json_decode((string) $j, true) ?? [])->all()]);
        unset($data['sets']);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    protected function getRedirectUrl(): string
    {
        return RuleSetResource::getUrl('view', ['record' => $this->record]);
    }
}
