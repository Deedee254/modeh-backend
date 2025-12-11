<div class="flex items-center">
    <input
        type="checkbox"
        wire:click="toggleRowSelection({{ $record->id }})"
        @if(isset($selected) && in_array($record->id, (array) $selected)) checked @endif
        class="filament-checkbox"
        />
</div>
