@php
    use Filament\Support\Facades\FilamentView;
@endphp

<x-filament-panels::page>
    <h2 class="filament-heading">Messages from {{ $senderId ? (\App\Models\User::find($senderId)?->name ?? 'Sender') : 'Sender' }}</h2>

    <div class="mt-4">
        @livewire('admin.sender-messages-table', ['senderId' => $senderId])
    </div>
</x-filament-panels::page>
