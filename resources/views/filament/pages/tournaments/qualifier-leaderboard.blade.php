@php
/**
 * Qualifier Leaderboard View
 * Displays qualification scores and attempts for tournament qualifiers
 */
@endphp

<x-filament::page>
    <div class="space-y-4">
        <div class="flex flex-col gap-2">
            <h1 class="text-2xl font-semibold">Qualifier Leaderboard</h1>
            <p class="text-sm text-gray-600">{{ $record->name }} — Status: {{ ucfirst($record->status) }}</p>
        </div>

        {{ $this->table }}
    </div>
</x-filament::page>
