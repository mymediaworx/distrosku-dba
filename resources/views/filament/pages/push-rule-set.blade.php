<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">1. Choose instances</x-slot>
        <x-slot name="description">Rows are upserted by code and never deleted. Pushed rows become read-only on the ERP; overridable rows keep local edits.</x-slot>
        <form wire:submit="preview" class="space-y-4">
            {{ $this->form }}
            <x-filament::button type="submit" icon="heroicon-o-eye" wire:target="preview">Preview changes</x-filament::button>
        </form>
    </x-filament::section>

    @if ($previews)
        <x-filament::section>
            <x-slot name="heading">2. Review the diff</x-slot>
            <div class="grid gap-4 lg:grid-cols-2" data-test="push-preview">
                @foreach ($previews as $id => $p)
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                        <div class="flex items-center justify-between gap-2">
                            <div class="font-semibold">{{ $p['name'] }}</div>
                            @if ($p['ok'])
                                <span class="text-xs text-gray-500">installed: {{ $p['result']['installed_version'] ? 'v'.$p['result']['installed_version'] : 'none' }} → v{{ $record->version }}</span>
                            @else
                                <x-filament::badge color="danger">unreachable</x-filament::badge>
                            @endif
                        </div>
                        @if ($p['ok'])
                            @php($t = $p['result']['totals'])
                            <div class="mt-2 flex flex-wrap gap-2 text-xs">
                                <x-filament::badge color="success">{{ $t['create'] }} create</x-filament::badge>
                                <x-filament::badge color="warning">{{ $t['update'] }} update</x-filament::badge>
                                <x-filament::badge color="gray">{{ $t['same'] }} unchanged</x-filament::badge>
                                <x-filament::badge color="info">{{ $t['kept_local'] }} kept local</x-filament::badge>
                            </div>
                            @foreach ($p['result']['sets'] as $set => $rows)
                                @php($changes = collect($rows)->whereIn('action', ['create', 'update', 'kept_local']))
                                @if ($changes->isNotEmpty())
                                    <div class="mt-3 text-xs font-medium text-gray-500">{{ \App\Models\RuleSet::SETS[$set] ?? $set }}</div>
                                    <ul class="mt-1 space-y-0.5 text-xs">
                                        @foreach ($changes->take(40) as $r)
                                            <li class="font-mono break-all"><span @class(['text-emerald-600' => $r['action'] === 'create', 'text-amber-600' => $r['action'] === 'update', 'text-sky-600' => $r['action'] === 'kept_local'])>{{ $r['action'] }}</span> {{ $r['code'] }}@if ($r['fields']) <span class="text-gray-500">({{ implode(', ', $r['fields']) }})</span>@endif</li>
                                        @endforeach
                                    </ul>
                                @endif
                            @endforeach
                        @else
                            <p class="mt-2 text-sm text-danger-600">{{ $p['error'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
            <div class="mt-4">
                <x-filament::button color="primary" icon="heroicon-o-paper-airplane" wire:click="push" wire:confirm="Push rule set v{{ $record->version }} to the reachable instances above?" wire:target="push"
                    :disabled="! collect($previews)->where('ok', true)->count()">Push to {{ collect($previews)->where('ok', true)->count() }} instance(s)</x-filament::button>
            </div>
        </x-filament::section>
    @endif

    @if ($applied)
        <x-filament::section>
            <x-slot name="heading">3. Result</x-slot>
            <ul class="space-y-1 text-sm" data-test="push-result">
                @foreach ($applied as $a)
                    <li>{{ $a['ok'] ? '✓' : '✗' }} <b>{{ $a['name'] }}</b>: {{ $a['ok'] ? json_encode($a['result']['totals']) : $a['error'] }}</li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
