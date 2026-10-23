<?php

namespace App\Filament\Resources\RuleSetResource\Pages;

use App\Filament\Resources\RuleSetResource;
use App\Models\Instance;
use App\Services\RulePusher;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/** Preview the diff on each selected instance (dry run on the ERP), then push. */
class PushRuleSet extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;
    use InteractsWithRecord;

    protected static string $resource = RuleSetResource::class;

    protected static string $view = 'filament.pages.push-rule-set';

    public ?array $data = [];

    /** instance id => preview/apply result (or error) */
    public array $previews = [];

    public array $applied = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless($this->record->status === 'published', 403, 'Publish the rule set before pushing it.');
        $this->form->fill(['instances' => Instance::where('status', '!=', 'down')->where(fn ($q) => $q->whereNull('rules_version')->orWhere('rules_version', '<', $this->record->version))->pluck('id')->all()]);
    }

    public function getTitle(): string
    {
        return 'Push rule set v'.$this->record->version;
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\CheckboxList::make('instances')->label('Instances')->required()->columns(['default' => 1, 'md' => 2, 'xl' => 3])->bulkToggleable()
                ->options(Instance::orderBy('name')->get()->mapWithKeys(fn (Instance $i) => [$i->id => $i->name.' — '.($i->rules_version ? 'v'.$i->rules_version : 'no rules').' · '.$i->status]))
                ->descriptions(Instance::orderBy('name')->pluck('url', 'id')->all()),
        ]);
    }

    public function preview(RulePusher $pusher): void
    {
        $this->applied = [];
        $this->previews = [];
        foreach (Instance::whereIn('id', $this->form->getState()['instances'])->get() as $i) {
            $p = $pusher->preview($this->record, $i);
            $this->previews[$i->id] = ['name' => $i->name, 'ok' => $p->ok, 'error' => $p->error, 'result' => $p->result];
        }
    }

    public function push(RulePusher $pusher): void
    {
        $ids = array_keys(array_filter($this->previews, fn ($p) => $p['ok']));
        $ok = 0;
        foreach (Instance::whereIn('id', $ids)->get() as $i) {
            $p = $pusher->push($this->record, $i);
            $this->applied[$i->id] = ['name' => $i->name, 'ok' => $p->ok, 'error' => $p->error, 'result' => $p->result];
            $ok += $p->ok ? 1 : 0;
        }
        Notification::make()->{$ok === count($ids) ? 'success' : 'warning'}()->title("Rule set v{$this->record->version} pushed to {$ok} of ".count($ids).' instance(s)')->send();
    }

    protected function getHeaderActions(): array
    {
        return [Actions\Action::make('back')->label('Back to rule set')->color('gray')->url(RuleSetResource::getUrl('view', ['record' => $this->record]))];
    }
}
