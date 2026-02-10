<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use App\Repositories\TournamentRepository;
use Illuminate\Support\Carbon;
use App\Models\TournamentQualificationAttempt;
use Illuminate\Support\Facades\DB;
use App\Events\TournamentRoundCreated;

/**
 * Job: HandleTournamentRoundCompletion
 *
 * This job is responsible for detecting when a tournament round has fully completed,
 * collecting winners and creating the next round's battles (or finalizing the tournament).
 * It also supports auto-finalizing the qualification phase when an upcoming tournament's
 * `end_date` has passed.
 *
 * @property int $tournamentId The tournament id this job will process
 * @property int $tries Maximum retry attempts for the job
 */

class HandleTournamentRoundCompletion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tournamentId;
    /**
     * Maximum number of attempts before failing permanently.
     * @var int
     */
    public $tries = 5;

    /**
     * Backoff periods (in seconds) between retries.
     * Laravel will use this array for exponential-like backoff.
     * @return array
     */
    public function backoff(): array
    {
        return [60, 120, 300, 900];
    }

    /**
     * Create a new job instance.
     */
    public function __construct(int $tournamentId)
    {
        $this->tournamentId = $tournamentId;
        // small attempt limit for safety
        $this->tries = 3;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        $start = microtime(true);
        $repo = app(TournamentRepository::class);
        $tournament = $repo->find($this->tournamentId);
        
        if (! $tournament) {
            logger()->info("HandleTournamentRoundCompletion: tournament {$this->tournamentId} not found");
            return;
        }

        // Handle qualification phase auto-finalization
        if ($tournament->status === 'upcoming') {
            $this->handleQualificationPhase($tournament);
            return;
        }

        // Handle active tournament round completion
        if ($tournament->status !== 'active') {
            logger()->info("HandleTournamentRoundCompletion: tournament {$this->tournamentId} is not active ({$tournament->status})");
            return;
        }

        $this->handleActiveRound($tournament);
        $duration = microtime(true) - $start;
        logger()->info(sprintf('HandleTournamentRoundCompletion: finished tournament %d in %.3fs', $this->tournamentId, $duration));
    }

    /**
     * Handle qualification phase auto-finalization
     */
    private function handleQualificationPhase(\App\Models\Tournament $tournament): void
    {
        try {
            if (! $tournament->end_date || ! Carbon::now()->greaterThan(Carbon::parse($tournament->end_date))) {
                return;
            }

            logger()->info("HandleTournamentRoundCompletion: auto-finalizing qualification for tournament {$tournament->id}");

            // Fetch approved participants who attempted qualification
            $approvedIds = $tournament->participants()->wherePivot('status', 'approved')->get()->pluck('id')->toArray();

            $attempts = TournamentQualificationAttempt::where('tournament_id', $tournament->id)
                ->whereIn('user_id', $approvedIds)
                ->orderByDesc('score')
                ->orderBy('duration_seconds')
                ->get();

            if ($attempts->isEmpty()) {
                logger()->info("HandleTournamentRoundCompletion: no qualification attempts found for tournament {$tournament->id}");
                return;
            }

            $participantIds = $this->selectTopQualifiers($tournament, $attempts);

            if (count($participantIds) === 0) {
                logger()->info("HandleTournamentRoundCompletion: no eligible participants selected for tournament {$tournament->id}");
                return;
            }

            if (count($participantIds) === 1) {
                $tournament->finalizeWithWinner((int) $participantIds[0]);
                logger()->info("HandleTournamentRoundCompletion: finalized tournament {$tournament->id} with single participant {$participantIds[0]}");
                return;
            }

            // Generate first round matches immediately
            try {
                $tournament->generateMatches($participantIds, 1, Carbon::now());
                logger()->info("HandleTournamentRoundCompletion: auto-generated first round for tournament {$tournament->id}");
            } catch (\Throwable $e) {
                logger()->error('HandleTournamentRoundCompletion: failed to auto-generate matches for tournament ' . $tournament->id . ': ' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            logger()->error('HandleTournamentRoundCompletion: error in qualification phase for tournament ' . $tournament->id . ': ' . $e->getMessage());
        }
    }

    /**
     * Select top qualifiers based on score and duration
     */
    private function selectTopQualifiers(\App\Models\Tournament $tournament, $attempts): array
    {
        $slots = $tournament->bracket_slots ?? 8;
        $selected = $attempts->groupBy('user_id')->map(function($g) { return $g->first(); })->values();
        $selected = $selected->take($slots);
        return $selected->pluck('user_id')->toArray();
    }

    /**
     * Handle active tournament round completion
     */
    private function handleActiveRound(\App\Models\Tournament $tournament): void
    {
        // Determine the highest round that exists
        $maxRound = (int) $tournament->battles()->max('round');
        if ($maxRound <= 0) return;

        // Ensure all battles in this round are completed and have a winner
        $battles = $tournament->battles()->where('round', $maxRound)->get();
        if ($battles->isEmpty()) return;

        $allCompleted = $battles->every(function ($b) { return $b->status === 'completed' && !is_null($b->winner_id); });
        if (! $allCompleted) return;

        // If next round already exists, skip
        $nextExists = $tournament->battles()->where('round', $maxRound + 1)->exists();
        if ($nextExists) return;

        // Collect winners
        $winners = $battles->pluck('winner_id')->filter()->map(function ($id) { return (int) $id; })->unique()->values()->toArray();

        if (count($winners) <= 1) {
            $this->finalizeTournamentIfNeeded($tournament, $winners, $maxRound);
            return;
        }

        // Create next round
        $this->createNextRound($tournament, $winners, $maxRound);
    }

    /**
     * Finalize tournament if only one or zero winners remain
     */
    private function finalizeTournamentIfNeeded(\App\Models\Tournament $tournament, array $winners, int $maxRound): void
    {
        if (count($winners) === 1) {
            $winnerId = $winners[0];
            logger()->info("Tournament {$tournament->id} has a final winner: {$winnerId}");
            if (is_callable([$tournament, 'finalizeWithWinner'])) {
                $tournament->finalizeWithWinner($winnerId);
            }
        } else {
            logger()->info("Tournament {$tournament->id} has no winners for round {$maxRound}");
        }
    }

    /**
     * Create next round battles
     */
    private function createNextRound(\App\Models\Tournament $tournament, array $winners, int $maxRound): void
    {
        $delayMinutes = $this->calculateRoundDelay($tournament);
        $scheduledAt = Carbon::now()->addMinutes($delayMinutes);

        if (! is_callable([$tournament, 'createBattlesForRound'])) {
            return;
        }

        try {
            $created = DB::transaction(function () use ($tournament, $winners, $maxRound, $scheduledAt) {
                $created = $tournament->createBattlesForRound($winners, $maxRound + 1, $scheduledAt);
                $this->handleByes($tournament, $created, $winners, $maxRound, $scheduledAt);
                return $created;
            });

            logger()->info("HandleTournamentRoundCompletion: Created " . count($created) . " battles for tournament {$tournament->id} round " . ($maxRound + 1));
            $this->dispatchRoundCreatedEvent($tournament, $maxRound + 1, $created);
            $this->logByeAdvances($tournament, $created, $winners);
        } catch (\Throwable $e) {
            logger()->error('HandleTournamentRoundCompletion: failed to create next round for tournament ' . $tournament->id . ': ' . $e->getMessage());
        }
    }

    /**
     * Calculate round delay in minutes
     */
    private function calculateRoundDelay(\App\Models\Tournament $tournament): int
    {
        if (!is_null($tournament->round_delay_days) && $tournament->round_delay_days !== '') {
            return intval($tournament->round_delay_days) * 24 * 60;
        }

        $rules = [];
        if (is_array($tournament->rules)) {
            $rules = $tournament->rules;
        } elseif (is_string($tournament->rules)) {
            $decoded = json_decode((string) $tournament->rules, true);
            if (is_array($decoded)) $rules = $decoded;
        }

        return isset($rules['round_delay_minutes']) ? intval($rules['round_delay_minutes']) : 5;
    }

    /**
     * Handle byes (unpaired winners)
     */
    private function handleByes(\App\Models\Tournament $tournament, array $created, array $winners, int $maxRound, $scheduledAt): void
    {
        $pairedIds = [];
        foreach ($created as $c) {
            $pairedIds[] = $c->player1_id;
            $pairedIds[] = $c->player2_id;
        }

        $byes = array_diff($winners, $pairedIds);
        if (empty($byes)) {
            return;
        }

        foreach ($byes as $uid) {
            $tournament->battles()->create([
                'round' => $maxRound + 1,
                'player1_id' => $uid,
                'player2_id' => 0,
                'winner_id' => $uid,
                'status' => 'bye',
                'scheduled_at' => $scheduledAt,
                'completed_at' => Carbon::now()
            ]);
        }
    }

    /**
     * Dispatch TournamentRoundCreated event
     */
    private function dispatchRoundCreatedEvent(\App\Models\Tournament $tournament, int $round, array $created): void
    {
        try {
            event(new TournamentRoundCreated($tournament->id, $round, $created));
        } catch (\Throwable $e) {
            logger()->error('HandleTournamentRoundCompletion: failed to dispatch TournamentRoundCreated event: ' . $e->getMessage());
        }
    }

    /**
     * Log auto-advanced byes
     */
    private function logByeAdvances(\App\Models\Tournament $tournament, array $created, array $winners): void
    {
        $pairedIds = [];
        foreach ($created as $c) {
            $pairedIds[] = $c->player1_id;
            $pairedIds[] = $c->player2_id;
        }

        $byes = array_diff($winners, $pairedIds);
        if (!empty($byes)) {
            logger()->info('Auto-advanced ' . count($byes) . ' byes to next round for tournament ' . $tournament->id);
        }
    }

    /**
     * Handle a job failure after all retry attempts.
     */
    public function failed(\Throwable $exception)
    {
        logger()->error('HandleTournamentRoundCompletion: job failed for tournament ' . $this->tournamentId . ': ' . $exception->getMessage(), [
            'tournament_id' => $this->tournamentId,
            'exception' => (string) $exception,
        ]);
    }
}
