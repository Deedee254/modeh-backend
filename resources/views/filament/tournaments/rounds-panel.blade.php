@php
/**
 * Blade partial for Filament: shows current round and scheduled matches for a tournament.
 * Expects $tournament (App\Models\Tournament) to be passed.
 */
use Carbon\Carbon;

$t = $tournament;
$rounds = $t->battles()->select('round')->distinct()->orderBy('round')->pluck('round')->toArray();
$currentRound = null;
foreach ($rounds as $r) {
    $battles = $t->battles()->where('round', $r)->get();
    $total = $battles->count();
    $completed = $battles->whereIn('status', [\App\Models\TournamentBattle::STATUS_COMPLETED, \App\Models\TournamentBattle::STATUS_FORFEITED])->count();
    if ($completed < $total) {
        $currentRound = $r;
        break;
    }
}
if (!$currentRound) {
    $currentRound = count($rounds) ? end($rounds) : 1;
}

$matches = $t->battles()->where('round', $currentRound)->with(['player1', 'player2'])->orderBy('scheduled_at')->get();

// Round end date heuristic: latest scheduled_at in this round plus tournament->round_delay_days if present
$roundEndDate = null;
if ($matches->isNotEmpty()) {
    $latest = $matches->filter(fn($m) => $m->scheduled_at)->max('scheduled_at');
    if ($latest) {
        $delay = intval($t->round_delay_days ?? 0);
        $roundEndDate = Carbon::parse($latest)->addDays(max(0, $delay));
    }
}

function fmt($dt) {
    if (!$dt) return '-';
    try { return Carbon::parse($dt)->format('M d, Y H:i'); } catch (\Throwable $_) { return (string)$dt; }
}
@endphp

<div>
    <div class="mb-3">
        <div class="text-sm text-gray-600">Current Round</div>
        <div class="text-lg font-semibold">Round {{ $currentRound }}</div>
        <div class="text-xs text-gray-500">Ends: {{ $roundEndDate ? fmt($roundEndDate) : 'TBD' }}</div>
    </div>

    <div class="mb-2">
        <div class="text-sm font-medium">Scheduled Matches ({{ $matches->count() }})</div>
    </div>

    @if($matches->isEmpty())
        <div class="text-sm text-gray-600">No matches scheduled for this round.</div>
    @else
        <ul class="space-y-2 text-sm">
            @foreach($matches as $m)
                <li class="flex justify-between items-center">
                    <div>
                        <div class="font-medium">{{ $m->player1?->name ?? 'TBD' }} vs {{ $m->player2?->name ?? 'TBD' }}</div>
                        <div class="text-xs text-gray-500">Match #{{ $m->id }} • {{ $m->status ?? ($m->completed_at ? 'completed' : ($m->scheduled_at ? 'scheduled' : 'pending')) }}</div>
                    </div>
                    <div class="text-xs text-gray-500">{{ $m->scheduled_at ? fmt($m->scheduled_at) : '-' }}</div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
