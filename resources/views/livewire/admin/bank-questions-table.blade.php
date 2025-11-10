<div>
    <div class="filament-tables">
        {{-- Table container rendered by Filament InteractsWithTable trait --}}
        {{ $this->table }}
    </div>

    <div class="mt-4 flex gap-2">
        <button
            wire:click="attachSelected"
            type="button"
            class="filament-button filament-button-size-md filament-button-color-primary"
        >
            Attach selected
        </button>

        <button
            type="button"
            onclick="window.dispatchEvent(new CustomEvent('modeh:close-bank-modal'))"
            class="filament-button filament-button-size-md"
        >
            Close
        </button>
    </div>
</div>