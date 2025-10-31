<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use App\Repositories\TournamentRepository;
use Illuminate\Support\Carbon;

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

        // Only process active tournaments
        if ($tournament->status !== 'active') {
            logger()->info("HandleTournamentRoundCompletion: tournament {$this->tournamentId} is not active ({$tournament->status})");
            return;
        }

        // determine the highest round that exists
        $maxRound = (int) $tournament->battles()->max('round');
        if ($maxRound <= 0) return;

        // ensure all battles in this round are completed and have a winner
        $battles = $tournament->battles()->where('round', $maxRound)->get();
        if ($battles->isEmpty()) return;

        $allCompleted = $battles->every(function ($b) { return $b->status === 'completed' && !is_null($b->winner_id); });
        if (! $allCompleted) return;

        // if next round already exists, skip
        $nextExists = $tournament->battles()->where('round', $maxRound + 1)->exists();
        if ($nextExists) return;

        // collect winners
        $winners = $battles->pluck('winner_id')->filter()->map(function ($id) { return (int) $id; })->unique()->values()->toArray();

        if (count($winners) <= 1) {
            // declare tournament winner
            if (count($winners) === 1) {
                $winnerId = $winners[0];
                logger()->info("Tournament {$tournament->id} has a final winner: {$winnerId}");
                if (is_callable([$tournament, 'finalizeWithWinner'])) {
                    $tournament->finalizeWithWinner($winnerId);
                }
            } else {
                logger()->info("Tournament {$tournament->id} has no winners for round {$maxRound}");
            }
            return;
        }

        // schedule new round creations after configured delay
        $rules = is_array($tournament->rules) ? $tournament->rules : (is_string($tournament->rules) ? json_decode($tournament->rules, true) : []);
        $delayMinutes = isset($rules['round_delay_minutes']) ? intval($rules['round_delay_minutes']) : 5;

        $scheduledAt = Carbon::now()->addMinutes($delayMinutes);

        // create next round battles (assumes Tournament::createBattlesForRound exists)
        if (is_callable([$tournament, 'createBattlesForRound'])) {
            $created = $tournament->createBattlesForRound($winners, $maxRound + 1, $scheduledAt);
            logger()->info("HandleTournamentRoundCompletion: Created " . count($created) . " battles for tournament {$tournament->id} round " . ($maxRound + 1));

            // handle byes (unpaired winners) by auto-advancing them
            $pairedIds = [];
            foreach ($created as $c) {
                $pairedIds[] = $c->player1_id;
                $pairedIds[] = $c->player2_id;
            }
            $byes = array_diff($winners, $pairedIds);
            if (!empty($byes)) {
                foreach ($byes as $uid) {
                    $tournament->battles()->create([
                        'round' => $maxRound + 1,
                        'player1_id' => $uid,
                        'player2_id' => $uid,
                        'winner_id' => $uid,
                        'status' => 'completed',
                        'scheduled_at' => $scheduledAt,
                        'completed_at' => Carbon::now()
                    ]);
                }
                logger()->info('Auto-advanced ' . count($byes) . ' byes to next round for tournament ' . $tournament->id);
            }
        }
        $duration = microtime(true) - $start;
        logger()->info(sprintf('HandleTournamentRoundCompletion: finished tournament %d in %.3fs', $this->tournamentId, $duration));
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
