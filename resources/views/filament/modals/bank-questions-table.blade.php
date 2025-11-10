<div>
    {{-- Mount the Livewire table, pass tournamentId if available --}}
    @php
        $componentProps = ['targetField' => 'questions'];
        if (isset($tournamentId)) $componentProps['tournamentId'] = $tournamentId;
        if (isset($filters)) $componentProps['initialFilters'] = $filters;
    @endphp
    <livewire:admin.bank-questions-table :wire:key="json_encode($componentProps)" v-bind="$componentProps" />

    <script>
        // When Livewire dispatches bank-attached, refresh the Filament relation manager table and close the modal
        window.addEventListener('modeh:bank-attached', function (e) {
            // Try a couple of refresh strategies to support Filament v4 setups
            if (window.Livewire) {
                try {
                    // best-effort: targeted emit to the RelationManager component
                    window.Livewire.emitTo('filament.resources.tournament-resource.relation-managers.questions-relation-manager', 'refresh');
                } catch (err) {
                    // ignore
                }

                try {
                    // global fallback: many Filament components listen to this
                    window.Livewire.emit('refresh');
                } catch (err) {
                    // ignore
                }
            }

            // Prefer Filament v4 modal API when available
            try {
                if (window.Filament && window.Filament.modals && typeof window.Filament.modals.close === 'function') {
                    window.Filament.modals.close();
                }
            } catch (err) {
                // ignore
            }

            // Legacy: dispatch closeModal for other integrations
            window.dispatchEvent(new CustomEvent('closeModal'));
        });

        // When selection happens in create-mode, populate the questions multi-select input
        window.addEventListener('modeh:bank-selected', function (e) {
            const field = e.detail.field;
            const ids = e.detail.ids || [];

            // Find the Livewire component instance for the form
            const formComponent = window.Livewire.find(
                document.querySelector('[wire\\:id]').getAttribute('wire:id')
            );

            if (formComponent) {
                const currentIds = formComponent.get('data.questions') || [];
                const newIds = [...new Set([...currentIds, ...ids])];
                formComponent.set('data.questions', newIds);
            } else {
                // Fallback for older versions or different structures
                const select = document.querySelector('select[name="' + field + '[]"]');
                if (select) {
                    const existingOptions = Array.from(select.options).map(o => o.value);
                    ids.forEach(id => {
                        if (!existingOptions.includes(String(id))) {
                            select.options.add(new Option(id, id, true, true));
                        }
                    });
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                }
            } else {
                // Fallback: try input
                const input = document.querySelector('input[name="' + field + '[]"]');
                if (input) {
                    input.value = ids.join(',');
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                }
            }

            // Close modal using Filament API if available, else dispatch legacy event
            try {
                if (window.Filament && window.Filament.modals && typeof window.Filament.modals.close === 'function') {
                    window.Filament.modals.close();
                } else {
                    window.dispatchEvent(new CustomEvent('closeModal'));
                }
            } catch (err) {
                window.dispatchEvent(new CustomEvent('closeModal'));
            }
        });

        window.addEventListener('modeh:close-bank-modal', function () {
            try {
                if (window.Filament && window.Filament.modals && typeof window.Filament.modals.close === 'function') {
                    window.Filament.modals.close();
                } else {
                    window.dispatchEvent(new CustomEvent('closeModal'));
                }
            } catch (err) {
                window.dispatchEvent(new CustomEvent('closeModal'));
            }
        });
    </script>
</div>