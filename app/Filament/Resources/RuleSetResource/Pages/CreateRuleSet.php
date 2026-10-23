<?php

namespace App\Filament\Resources\RuleSetResource\Pages;

use App\Filament\Resources\RuleSetResource;
use App\Models\AuditLog;
use App\Models\RuleSet;
use Filament\Resources\Pages\CreateRecord;

class CreateRuleSet extends CreateRecord
{
    protected static string $resource = RuleSetResource::class;

    protected function fillForm(): void
    {
        $base = RuleSet::orderByDesc('version')->first();
        $this->form->fill([
            'name' => $base ? 'Based on v'.$base->version : 'Rule set',
            'sets' => collect(RuleSet::SETS)->map(fn ($l, $k) => json_encode($base?->payload[$k] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->all(),
        ]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $sets = RuleSet::normalise(['format' => RuleSet::FORMAT, 'sets' => collect($data['sets'])->map(fn ($j) => json_decode((string) $j, true) ?? [])->all()]);

        return ['version' => RuleSet::nextVersion(), 'name' => $data['name'], 'notes' => $data['notes'] ?? null, 'payload' => $sets, 'status' => 'draft', 'created_by' => auth()->user()?->email];
    }

    protected function afterCreate(): void
    {
        AuditLog::record('rules_created', null, "Rule set v{$this->record->version} drafted");
    }

    protected function getRedirectUrl(): string
    {
        return RuleSetResource::getUrl('view', ['record' => $this->record]);
    }
}
