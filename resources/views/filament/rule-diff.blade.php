@php($diff = $getState() ?? [])
<div class="grid gap-3 md:grid-cols-2">
@foreach ($diff as $set => $d)
    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
        <div class="mb-1 text-sm font-semibold">{{ \App\Models\RuleSet::SETS[$set] }}</div>
        <div class="flex flex-wrap gap-2 text-xs">
            <span class="rounded bg-emerald-50 px-2 py-0.5 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">{{ count($d['added']) }} new</span>
            <span class="rounded bg-amber-50 px-2 py-0.5 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">{{ count($d['changed']) }} changed</span>
            <span class="rounded bg-gray-100 px-2 py-0.5 text-gray-600 dark:bg-white/5 dark:text-gray-400">{{ $d['unchanged'] }} same</span>
            @if (count($d['dropped']))<span class="rounded bg-rose-50 px-2 py-0.5 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400" title="Not deleted on instances">{{ count($d['dropped']) }} no longer listed</span>@endif
        </div>
        @foreach (['added' => 'New', 'changed' => 'Changed', 'dropped' => 'No longer listed (kept on instances)'] as $k => $label)
            @if (count($d[$k]))
                <div class="mt-2 text-xs text-gray-500">{{ $label }}</div>
                <div class="font-mono text-xs break-all">{{ implode(', ', array_slice($d[$k], 0, 30)) }}@if(count($d[$k]) > 30) … +{{ count($d[$k]) - 30 }}@endif</div>
            @endif
        @endforeach
    </div>
@endforeach
</div>
