<x-filament::page>
    <x-filament::header>
        <x-slot name="title">Leaderboard — {{ $this->getRecord()->name }}</x-slot>
        <x-slot name="subtitle">Status: {{ ucfirst($this->getRecord()->status) }} — Participants: {{ $this->getTableQuery()->count() }}</x-slot>
    </x-filament::header>

    <x-filament::card>
        {{ $this->table }}
    </x-filament::card>
</x-filament::page>
