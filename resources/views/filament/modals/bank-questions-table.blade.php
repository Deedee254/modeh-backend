<div>
    {{-- Mount the Livewire table, pass tournamentId if available --}}
    @php
        $topicId = $filters['topic_id'] ?? null;
    @endphp
    
    <div id="bank-questions-table-wrapper">
        <livewire:admin.bank-questions-table 
            :tournament-id="$tournamentId ?? null"
            :target-field="'questions'"
            :initial-filters="$filters ?? []"
        />
    </div>

    <script>
</div>