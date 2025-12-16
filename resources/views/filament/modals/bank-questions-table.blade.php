<div>
    {{-- Mount the Livewire table, pass tournamentId if available --}}
    @php
        $topicId = $filters['topic_id'] ?? null;
    @endphp
    
    {{-- Display Question Recommendations --}}
    @if(isset($recommendations))
        <div class="mb-4 p-4 rounded-lg border-2 
            @if($recommendations['status'] === 'excellent') border-green-500 bg-green-50
            @elseif($recommendations['status'] === 'good') border-blue-500 bg-blue-50
            @else border-yellow-500 bg-yellow-50
            @endif">
            <div class="font-semibold text-sm mb-2">
                @if($recommendations['status'] === 'excellent')
                    ✅ Excellent Question Coverage
                @elseif($recommendations['status'] === 'good')
                    ℹ️ Good Question Coverage
                @else
                    ⚠️ Low Question Coverage
                @endif
            </div>
            <p class="text-sm mb-2">{{ $recommendations['message'] }}</p>
            <div class="text-xs space-y-1">
                <div><strong>Current:</strong> {{ $recommendations['current'] }} questions | <strong>Minimum:</strong> {{ $recommendations['minimum'] }} | <strong>Optimum:</strong> {{ $recommendations['optimum'] }}</div>
                <div><strong>Participants:</strong> {{ $recommendations['participants'] }} | <strong>Rounds:</strong> {{ $recommendations['total_rounds'] }}</div>
                @if(isset($participantsRecommendation))
                    <div class="mt-2 pt-2 border-t">
                        <strong>Max Participants Recommendation:</strong> {{ $participantsRecommendation['recommended_min_max_participants'] }} (no overlap) - {{ $participantsRecommendation['recommended_max_max_participants'] }} (acceptable overlap)
                    </div>
                @endif
            </div>
        </div>

        {{-- Show Round Breakdown --}}
        @if(!empty($recommendations['breakdown']))
            <details class="mb-4 text-xs">
                <summary class="cursor-pointer font-semibold p-2 bg-gray-100 rounded hover:bg-gray-200">
                    📊 Detailed Round Breakdown ({{ count($recommendations['breakdown']) }} rounds)
                </summary>
                <div class="mt-2 p-2 bg-gray-50 rounded overflow-x-auto">
                    <table class="text-xs w-full border-collapse">
                        <thead>
                            <tr class="border-b">
                                <th class="text-left p-1">Round</th>
                                <th class="text-right p-1">Battles</th>
                                <th class="text-right p-1">Q/Battle</th>
                                <th class="text-right p-1">Min Total</th>
                                <th class="text-right p-1">Opt Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recommendations['breakdown'] as $round)
                                <tr class="border-b hover:bg-gray-100">
                                    <td class="p-1">{{ $round['round'] }}</td>
                                    <td class="text-right p-1">{{ $round['battles'] }}</td>
                                    <td class="text-right p-1">{{ $round['questions_per_battle'] }}</td>
                                    <td class="text-right p-1">{{ $round['minimum_questions'] }}</td>
                                    <td class="text-right p-1">{{ $round['optimum_questions'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        @endif
    @endif
    
    <div id="bank-questions-table-wrapper">
        <livewire:admin.bank-questions-table 
            :tournament-id="$tournamentId ?? null"
            :target-field="'questions'"
            :initial-filters="$filters ?? []"
        />
    </div>

    <script>
</div>