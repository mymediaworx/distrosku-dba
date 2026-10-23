<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-3">
        <x-filament::section>
            <div class="text-sm text-gray-500">Latest release</div>
            <div class="text-2xl font-semibold">{{ $latest ? 'v'.$latest : '—' }}</div>
            <div class="text-xs text-gray-500">source: {{ $source }}</div>
        </x-filament::section>
        <x-filament::section class="md:col-span-2">
            <div class="text-sm text-gray-500">Recent tags</div>
            <div class="mt-1 flex flex-wrap gap-1">
                @forelse ($tags as $t)<x-filament::badge color="gray">{{ $t }}</x-filament::badge>@empty<span class="text-sm text-gray-500">Set DBA_GITHUB_REPO (and DBA_GITHUB_TOKEN for a private repo) to list GitHub tags.</span>@endforelse
            </div>
        </x-filament::section>
    </div>
    {{ $this->table }}
</x-filament-panels::page>
