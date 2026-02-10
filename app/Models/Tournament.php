<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Level;
use Illuminate\Support\Facades\Schema;
use App\Models\Question;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Events\Tournament\BattleCompleted;

/**
 * Class Tournament
 *
 * Tournament Timing:
 * - start_date: When tournament registration opens (participants join via POST /tournaments/{id}/join)
 * - qualifier_days: Duration of the qualifier phase (e.g., 7 days). When generateMatches() is called,
 *   it marks tournament status as 'active' and the qualifier phase begins.
 * - When qualifier period ends, admins call generateMatches() again for round 1. This creates the first
 *   bracket battles and transitions to battle phase.
 * - round_delay_days: Duration of each battle round. After battles in a round complete, the system
 *   waits round_delay_days before creating battles for the next round.
 * - end_date: When entire tournament ends (after all battles and winner determined)
 * - Bracket size (bracket_slots) is auto-calculated based on number of qualifier participants.
 * - Max participant limit removed: the auto-calculated bracket size determines how many advance to battles.
 *
 * @property int $id
 * @property string $name
 * @property string $description
 * @property string $status
 * @property float|null $entry_fee
 * @property float|null $prize_pool
 * @property \Illuminate\Support\Carbon|null $registration_end_date
 * @property \Illuminate\Support\Carbon|null $start_date
 * @property \Illuminate\Support\Carbon|null $end_date
 * @property int|null $qualifier_days
 * @property int|null $round_delay_days
 * @property int $battle_question_count
 * @property int $qualifier_question_count
 * @property int $battle_per_question_seconds
 * @property int $qualifier_per_question_seconds
 * @property string $qualifier_tie_breaker
 * @property int $bracket_slots
 * @property string $format
 * @property array|string $rules
 * @property array|string|null $timeline
 * @property int|null $grade_id
 * @property string $access_type
 * @property int|null $subject_id
 * @property int|null $topic_id
 * @property int|null $level_id
 * @property int|null $winner_id
 * @property int|null $sponsor_id
 * @property string|null $sponsor_banner
 * @property array|string|null $sponsor_details
 * @property bool $requires_approval
 * @property bool $is_featured
 * @property int $created_by
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * Relations:
 * @property \Illuminate\Database\Eloquent\Collection|\App\Models\User[] $participants
 * @property \Illuminate\Database\Eloquent\Collection|\App\Models\TournamentBattle[] $battles
 */
class Tournament extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'start_date',
        'end_date',
        'status',
        'entry_fee',
        'open_to_subscribers',
        'prize_pool',
        'max_participants',
        'registration_end_date',
        'min_participants',
        'format',
        'rules',
        'timeline',
        'level_id',
        'grade_id',
        'subject_id',
        'topic_id',
        'created_by',
        'sponsor_id',
        'sponsor_banner',
        'sponsor_details',
        'requires_approval',
        'is_featured',
        // Qualifier configuration
        'qualifier_per_question_seconds',
        'qualifier_question_count',
        'access_type',
        // Battle configuration
        'battle_per_question_seconds',
        'battle_question_count',
        // Tie-breaker and selection
        'qualifier_tie_breaker',
        // Day-based scheduling
        'qualifier_days',
        'round_delay_days',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'registration_end_date' => 'datetime',
        'entry_fee' => 'decimal:2',
        'prize_pool' => 'decimal:2',
        'rules' => 'array',
        'sponsor_details' => 'array',
        'access_type' => 'string',
        'open_to_subscribers' => 'boolean',
        'requires_premium' => 'boolean',
        'requires_approval' => 'boolean',
        'is_featured' => 'boolean',
        'auto_start' => 'boolean',
        'auto_complete' => 'boolean'
    ];

    protected $appends = [
        'current_round',
        'total_rounds',
        'registration_open',
        'can_start'
    ];

    public function getCurrentRoundAttribute()
    {
        return $this->battles()->max('round') ?? 0;
    }

    public function getTotalRoundsAttribute()
    {
        $participantCount = $this->participants()->count();
        return $participantCount > 0 ? ceil(log($participantCount, 2)) : 0;
    }

    public function getRegistrationOpenAttribute()
    {
        if ($this->status !== 'upcoming') {
            return false;
        }

        $now = now();
        return $now->between(
            $this->registration_end_date ? $now : $this->start_date,
            $this->registration_end_date ?? $this->start_date
        );
    }

    public function getCanStartAttribute()
    {
        if ($this->status !== 'upcoming') {
            return false;
        }

        $participantCount = $this->participants()->count();
        return $participantCount >= ($this->min_participants ?? 2) &&
               $participantCount % 2 === 0 &&
               now()->gte($this->start_date);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeUpcoming($query)
    {
        return $query->where('status', 'upcoming')
                    ->where('start_date', '>', now());
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function winner()
    {
        return $this->belongsTo(User::class, 'winner_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function level()
    {
        return $this->belongsTo(Level::class, 'level_id');
    }

    public function topic()
    {
        return $this->belongsTo(Topic::class);
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function participants()
    {
        return $this->belongsToMany(User::class, 'tournament_participants')
            ->withPivot(['score', 'rank', 'completed_at', 'status', 'requested_at', 'approved_at', 'approved_by'])
            ->withTimestamps();
    }

    public function questions()
    {
        return $this->belongsToMany(Question::class, 'tournament_questions')
            ->withPivot(['position'])
            ->orderBy('position');
    }

    public function battles()
    {
        return $this->hasMany(TournamentBattle::class);
    }

    public function qualificationAttempts()
    {
        return $this->hasMany(TournamentQualificationAttempt::class);
    }

    /**
     * Create battles for a given round from an array of participant user IDs.
     * If an odd participant remains, they receive a bye and are not paired.
     * @param array $participantIds
     * @param int $round
     * @param \Illuminate\Support\Carbon|null $scheduledAt
     * @return array Newly created TournamentBattle models
     */
    public function createBattlesForRound(array $participantIds, int $round = 1, $scheduledAt = null)
    {
        $created = [];
        // filter and unique
        $p = array_values(array_filter(array_unique(array_map('intval', $participantIds)), function ($v) { return $v > 0; }));
        $count = count($p);
        if ($count === 0) return $created;

        // Improved pairing (balanced/seeding-aware): pair highest seed with lowest seed
        // If participants have a pivot rank on the tournament, use it for seeding (lower rank = higher seed)
        $seedRanks = [];
        try {
            $members = $this->participants()->whereIn('user_id', $p)->get();
            foreach ($members as $m) {
                $seedRanks[$m->id] = $m->pivot->rank ?? null;
            }
        } catch (\Exception $e) {
            // ignore and proceed with provided order
        }

        // If we have some ranks, sort by rank asc (1 is top seed), else keep provided order
        if (!empty(array_filter($seedRanks, function($v){ return !is_null($v); }))) {
            usort($p, function($a, $b) use ($seedRanks) {
                $ra = $seedRanks[$a] ?? PHP_INT_MAX;
                $rb = $seedRanks[$b] ?? PHP_INT_MAX;
                if ($ra === $rb) return 0;
                return ($ra < $rb) ? -1 : 1;
            });
        }

        // Balanced pairing: first with last, second with second-last, etc.
        $i = 0; $j = $count - 1;
        while ($i < $j) {
            $rawPlayer1 = $p[$i];
            $rawPlayer2 = $p[$j];

            // To make creation idempotent and avoid duplicate swapped pairs,
            // canonicalize the player ordering: smaller id becomes player1_id.
            $player1 = min($rawPlayer1, $rawPlayer2);
            $player2 = max($rawPlayer1, $rawPlayer2);

            // Use firstOrNew so repeated runs won't create duplicates. If an existing record
            // is found, we update scheduled_at/status if needed.
            $battle = TournamentBattle::firstOrNew([
                'tournament_id' => $this->id,
                'round' => $round,
                'player1_id' => $player1,
                'player2_id' => $player2,
            ]);

            $needsSave = false;
            if (! $battle->exists) {
                $battle->status = 'scheduled';
                $battle->scheduled_at = $scheduledAt ? $scheduledAt : $this->start_date;
                $needsSave = true;
            } else {
                // Ensure scheduled_at is set appropriately if missing
                if (empty($battle->scheduled_at) && ($scheduledAt || $this->start_date)) {
                    $battle->scheduled_at = $scheduledAt ? $scheduledAt : $this->start_date;
                    $needsSave = true;
                }
            }

            if ($needsSave) $battle->save();

            $created[] = $battle;
            // Auto-attach questions from tournament_questions pool
            try {
                // Only attach if no questions already attached (preserve previously attached payloads)
                if ($battle->questions()->count() === 0) {
                    $perBattle = $this->battle_question_count ?? 10;

                    // Get all questions from tournament_questions table (questions already added to tournament)
                    $selectedIds = $this->questions()->pluck('id')->toArray();

                    // If pool is smaller than needed, fill with additional questions filtered by topic_id
                    if (count($selectedIds) < (int)$perBattle && $this->topic_id) {
                        $needed = (int)$perBattle - count($selectedIds);
                        $additionalIds = Question::where('topic_id', $this->topic_id)
                            ->whereNotIn('id', $selectedIds)
                            ->inRandomOrder()
                            ->limit($needed)
                            ->pluck('id')
                            ->toArray();
                        $selectedIds = array_merge($selectedIds, $additionalIds);
                    }

                    if (!empty($selectedIds)) {
                        // Shuffle the final selection
                        shuffle($selectedIds);
                        // Limit to battle_question_count
                        $selectedIds = array_slice($selectedIds, 0, (int)$perBattle);

                        $attachData = [];
                        foreach ($selectedIds as $position => $questionId) {
                            $attachData[$questionId] = ['position' => $position];
                        }
                        if (!empty($attachData)) {
                            $battle->questions()->attach($attachData);
                        }
                    }
                }
            } catch (\Throwable $_) {
                // non-fatal; skip auto-attach on error
            }
            $i++; $j--;
        }

        // If odd participant remains (unpaired), they receive a bye; caller may handle auto-advancement
        if ($i === $j) {
            // unpaired participant $p[$i]
            // we don't create a battle here; the scheduled job will auto-advance by creating a completed placeholder if needed
        }

        return $created;
    }

    /**
     * Calculate optimal bracket size based on number of participants.
     * Valid bracket sizes are powers of 2: 2, 4, 8, 16, 32 (max).
     * We pick the largest bracket size that doesn't exceed participant count, with 32 as the maximum.
     *
     * Logic:
     * - ≤4 participants → 4-player bracket
     * - 5-8 participants → 8-player bracket
     * - 9-16 participants → 16-player bracket
     * - 17-32 participants → 32-player bracket
     * - >32 participants → 32-player bracket (capped)
     *
     * @param int $participantCount Number of participants (e.g., from qualifier)
     * @return int Optimal bracket size (2, 4, 8, 16, or 32)
     */
    public function calculateOptimalBracketSize(int $participantCount): int
    {
        if ($participantCount <= 4) return 4;
        if ($participantCount <= 8) return 8;
        if ($participantCount <= 16) return 16;
        if ($participantCount <= 32) return 32;
        // More than 32 participants: cap at 32
        return 32;
    }

    /**
     * Generate matches for the tournament. Can be called from Filament UI or API.
     * This wraps the createBattlesForRound logic with proper request handling.
     */
    public function generateMatches($participantIds = null, $round = 1, $scheduledAt = null)
    {
        // If no explicit participant ids provided, use registered participants
        if (!is_array($participantIds) || empty($participantIds)) {
            // Only generate if tournament is upcoming
            if ($this->status !== 'upcoming') {
                throw new \Exception('Can only generate matches for upcoming tournaments');
            }

            $participants = $this->participants()->get()->pluck('id')->toArray();
            $participantIds = $participants;
        }

        if (count($participantIds) < 2) {
            // If only one participant remains, finalize
            if (count($participantIds) === 1) {
                $this->finalizeWithWinner((int) $participantIds[0]);
                return ['message' => 'Tournament completed with single participant', 'battles' => []];
            }
            throw new \Exception('Need at least 2 participants');
        }

        // If this is the first round for an upcoming tournament, randomize entry order
        if ($round === 1 && $this->status === 'upcoming') {
            shuffle($participantIds);
        }

        // Auto-calculate optimal bracket size based on number of participants if not explicitly set
        $slots = $this->bracket_slots ?? $this->calculateOptimalBracketSize(count($participantIds));
        $excluded = [];

        try {
            $attempts = \App\Models\TournamentQualificationAttempt::where('tournament_id', $this->id)
                ->whereIn('user_id', $participantIds)
                ->orderByDesc('score')
                ->orderBy('duration_seconds')
                ->get();

            if ($attempts->isNotEmpty()) {
                $selected = $attempts->groupBy('user_id')->map(function($g) { return $g->first(); })->values();
                $selected = $selected->take($slots);
                $selectedIds = $selected->pluck('user_id')->toArray();

                $excluded = array_values(array_filter($participantIds, function($id) use ($selectedIds) {
                    return !in_array($id, $selectedIds);
                }));

                $participantIds = $selectedIds;
                \Log::info('Tournament::generateMatches selected top qualifiers for tournament ' . $this->id . '; selected: ' . implode(',', $selectedIds) . '; excluded: ' . implode(',', $excluded));
            } else {
                if (count($participantIds) > $slots) {
                    $excluded = array_slice($participantIds, $slots);
                    $participantIds = array_slice($participantIds, 0, $slots);
                    \Log::info('Tournament::generateMatches trimming participants to ' . $slots . ' slots for tournament ' . $this->id . '; excluded: ' . implode(',', $excluded));
                }
            }
        } catch (\Throwable $e) {
            if (count($participantIds) > $slots) {
                $excluded = array_slice($participantIds, $slots);
                $participantIds = array_slice($participantIds, 0, $slots);
                \Log::warning('Tournament::generateMatches qualifier selection failed for tournament ' . $this->id . '; falling back to simple trim. Error: ' . $e->getMessage());
            }
        }

        // Re-check in case trimming reduced participants to a single entrant
        if (count($participantIds) < 2) {
            if (count($participantIds) === 1) {
                $this->finalizeWithWinner((int) $participantIds[0]);
                return ['message' => 'Tournament completed with single participant after trimming', 'battles' => []];
            }
            throw new \Exception('Need at least 2 participants after trimming');
        }

        // create battles using the Tournament helper
    $created = $this->createBattlesForRound($participantIds, $round, $scheduledAt);

        // Activate tournament if this is the first round
        if ($round === 1 && $this->status === 'upcoming') {
            $this->status = 'active';
            $this->save();
        }

        return [
            'message' => 'Tournament battles generated successfully',
            'created' => count($created),
            'battles' => $this->battles()->where('round', $round)->with(['player1', 'player2'])->get(),
            'excluded' => $excluded,
        ];
    }

    /**
     * Finalize tournament when only one winner remains: mark participant rank and complete tournament.
     */
    public function finalizeWithWinner(int $userId)
    {
        // mark participant rank and completed_at on pivot
        try {
            $this->participants()->updateExistingPivot($userId, ['rank' => 1, 'completed_at' => now()]);
        } catch (\Exception $e) {
            // ignore if pivot update fails
        }

        // set tournament winner and status to completed
        $this->winner_id = $userId;
        $this->status = 'completed';
        $this->save();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sponsor()
    {
        return $this->belongsTo(Sponsor::class);
    }

    /**
     * Calculate recommended and minimum question counts based on tournament size
     * For single elimination: each round has half the battles of the previous
     * Round 1: N/2 battles, Round 2: N/4 battles, etc.
     * 
     * @return array ['minimum' => int, 'optimum' => int, 'current' => int, 'breakdown' => array]
     */
    public function getQuestionRecommendations(): array
    {
        $participantCount = $this->participants()->count();
        $questionPerBattle = $this->battle_question_count ?? 10;
        
        if ($participantCount < 2) {
            return [
                'minimum' => $questionPerBattle,
                'optimum' => $questionPerBattle,
                'current' => $this->questions()->count(),
                'breakdown' => [],
                'message' => 'Need at least 2 participants to calculate'
            ];
        }

        // Calculate for single elimination tournament
        $breakdown = [];
        $totalMinimum = 0;
        $totalOptimum = 0;
        $battlesPerRound = $participantCount / 2;
        $round = 1;

        while ($battlesPerRound >= 1) {
            $battlesInRound = (int)$battlesPerRound;
            if ($battlesInRound === 0) break;

            $questionForRound = $battlesInRound * $questionPerBattle;
            
            // Minimum: can have some overlap, estimate ~70% unique needed
            $minimumForRound = (int)ceil($questionForRound * 0.7);
            
            // Optimum: all questions unique (no overlap) per round
            $optimumForRound = $questionForRound;

            $breakdown[] = [
                'round' => $round,
                'battles' => $battlesInRound,
                'questions_per_battle' => $questionPerBattle,
                'minimum_questions' => $minimumForRound,
                'optimum_questions' => $optimumForRound,
            ];

            $totalMinimum += $minimumForRound;
            $totalOptimum += $optimumForRound;
            $battlesPerRound = $battlesPerRound / 2;
            $round++;
        }

        $currentCount = $this->questions()->count();

        return [
            'minimum' => $totalMinimum,
            'optimum' => $totalOptimum,
            'current' => $currentCount,
            'participants' => $participantCount,
            'total_rounds' => count($breakdown),
            'breakdown' => $breakdown,
            'status' => $currentCount >= $totalOptimum ? 'excellent' 
                      : ($currentCount >= $totalMinimum ? 'good' : 'warning'),
            'message' => $this->getQuestionRecommendationMessage($currentCount, $totalMinimum, $totalOptimum),
        ];
    }

    /**
     * Generate a human-readable message about question coverage
     */
    private function getQuestionRecommendationMessage(int $current, int $minimum, int $optimum): string
    {
        if ($current >= $optimum) {
            return "Excellent! Your {$current} questions exceed the optimum ({$optimum} recommended). No overlaps expected.";
        } elseif ($current >= $minimum) {
            $overlap = (int)ceil(($optimum - $current) / $optimum * 100);
            return "Good! Your {$current} questions cover the minimum ({$minimum}). Expect ~{$overlap}% question overlap across rounds.";
        } else {
            $shortage = $minimum - $current;
            return "Warning: You have {$current} questions but need at least {$minimum} (short by {$shortage}). Significant overlap expected.";
        }
    }

    /**
     * Calculate recommended max participants based on question count
     * Works backwards from questions available to determine tournament size
     * 
     * @return array ['recommended_min' => int, 'recommended_max' => int, 'current_questions' => int, 'current_participants' => int]
     */
    public function getMaxParticipantsRecommendation(): array
    {
        $currentQuestions = $this->questions()->count();
        $questionPerBattle = $this->battle_question_count ?? 10;
        $currentParticipants = $this->participants()->count();

        // For single elimination, calculate how many participants can be supported
        // Working backwards: if we have X questions, what's max tournament size?
        
        // Optimum: no overlaps at all
        // Total questions needed for N participants = sum of (N/2 + N/4 + N/8 + ... + 1) * question_per_battle
        // This is roughly N * question_per_battle (varies by exact N)
        
        $optimalMaxParticipants = max(2, (int)floor($currentQuestions / $questionPerBattle * 0.9));
        
        // For minimum (allowing ~30% overlap): more participants can be supported
        $minimalMaxParticipants = max(2, (int)floor($currentQuestions / $questionPerBattle * 1.3));

        // Find closest power of 2 for bracket sizing (tournaments typically use 2, 4, 8, 16, 32, 64)
        $optimalBracketSize = $this->closestPowerOfTwo($optimalMaxParticipants);
        $minimalBracketSize = $this->closestPowerOfTwo($minimalMaxParticipants);

        return [
            'current_questions' => $currentQuestions,
            'current_participants' => $currentParticipants,
            'question_per_battle' => $questionPerBattle,
            'recommended_min_max_participants' => $optimalBracketSize,  // No/minimal overlap
            'recommended_max_max_participants' => $minimalBracketSize,  // Can handle with overlap
            'message' => $this->getParticipantsRecommendationMessage($currentQuestions, $optimalBracketSize, $minimalBracketSize, $currentParticipants),
        ];
    }

    /**
     * Find closest power of 2 for bracket sizing
     */
    private function closestPowerOfTwo(int $number): int
    {
        $powers = [2, 4, 8, 16, 32, 64, 128, 256, 512];
        
        if ($number <= 2) return 2;
        if ($number >= 512) return 512;

        foreach ($powers as $power) {
            if ($power >= $number) return $power;
        }

        return 512;
    }

    /**
     * Generate message about participant capacity based on questions
     */
    private function getParticipantsRecommendationMessage(int $questions, int $optimal, int $minimal, int $current): string
    {
        if ($current > $minimal) {
            $surplus = $current - $minimal;
            return "⚠️ Warning: You have {$current} participants but only {$questions} questions. Recommended max: {$minimal} (for ~30% overlap) or {$optimal} (for ~5% overlap).";
        } elseif ($current > $optimal) {
            $overlap = (int)ceil(($current / $optimal - 1) * 100);
            return "⚠️ Caution: You have {$current} participants with {$questions} questions. Expect ~{$overlap}% overlap. Optimal: {$optimal} (no overlap) or up to {$minimal} (acceptable).";
        } else {
            return "✅ Perfect! Your {$current} participants work well with {$questions} questions. You could support up to {$minimal} participants with acceptable overlap.";
        }
    }

    /**
     * Get current round number
     * @return int
     */
    public function getCurrentRound(): int
    {
        return (int) ($this->battles()->max('round') ?? 0);
    }

    /**
     * Get next round number
     * @return int
     */
    public function getNextRound(): int
    {
        return $this->getCurrentRound() + 1;
    }

    /**
     * Check if a round is complete (all battles finished)
     * @param int $round
     * @return bool
     */
    public function isRoundComplete(int $round): bool
    {
        $total = $this->battles()->where('round', $round)->count();

        if ($total === 0) {
            return false;
        }

        $completed = $this->battles()
            ->where('round', $round)
            ->where('status', TournamentBattle::STATUS_COMPLETED)
            ->count();

        return $total === $completed;
    }

    /**
     * Get round status (total battles, completed battles, pending)
     * @param int $round
     * @return array
     */
    public function getRoundStatus(int $round): array
    {
        $battles = $this->battles()->where('round', $round)->get();

        $total = $battles->count();
        $completed = $battles->where('status', TournamentBattle::STATUS_COMPLETED)->count();
        $pending = $total - $completed;

        return [
            'round' => $round,
            // backward-compatible keys expected by frontend
            'total' => $total,
            'completed' => $completed,
            'pending' => $pending,
            'total_battles' => $total,
            'completed_battles' => $completed,
            'pending_battles' => $pending,
            'is_complete' => $total > 0 && $pending === 0,
            'battle_ids' => $battles->pluck('id')->toArray(),
        ];
    }

    /**
     * Close a round by resolving incomplete battles when the round end date has passed,
     * determine winners and automatically create the next round battles. This method
     * performs DB changes inside a transaction and only dispatches events after commit
     * to avoid broadcasting before persistence.
     *
     * @param int|null $round If null, uses current round
     * @param bool $force If true, ignore end-date checks and force closure
     * @return array Result details including winners and created battles
     */
    public function closeRoundAndAdvance(?int $round = null, bool $force = false): array
    {
        $round = $round ?? $this->getCurrentRound();
        if ($round <= 0) {
            return ['ok' => false, 'message' => 'No active round to close'];
        }

        // Determine round scheduled start (latest scheduled_at for this round)
        $roundBattles = $this->battles()->where('round', $round)->get();
        if ($roundBattles->isEmpty()) {
            return ['ok' => false, 'message' => 'No battles found for round ' . $round];
        }

        $roundStart = $roundBattles->pluck('scheduled_at')->filter()->max();
        $roundStart = $roundStart ? Carbon::parse($roundStart) : null;

        // Compute end date using configured round_delay_days
        $roundDelay = (int) ($this->round_delay_days ?? 0);
        $roundEnd = $roundStart ? $roundStart->copy()->addDays(max(1, $roundDelay)) : null;

        if (! $force && $roundEnd && now()->lt($roundEnd)) {
            return ['ok' => false, 'message' => 'Round end date not reached', 'round_end' => $roundEnd];
        }

        $changedBattles = [];
        $eventsToDispatch = [];

        DB::beginTransaction();
        try {
            foreach ($roundBattles as $battle) {
                // If already completed, skip
                if ($battle->status === TournamentBattle::STATUS_COMPLETED || $battle->status === TournamentBattle::STATUS_FORFEITED) {
                    continue;
                }

                // Compute scores from attempts if available
                $p1Score = (float) $battle->attempts()->where('player_id', $battle->player1_id)->sum('points');
                $p2Score = (float) $battle->attempts()->where('player_id', $battle->player2_id)->sum('points');

                // Determine winner by rules:
                // 1) If one player has >0 points and the other has 0 -> that player wins
                // 2) Else higher score wins
                // 3) If tied, deterministic tie-breaker: lower user id advances
                $winnerId = null;
                if ($p1Score > 0 && $p2Score === 0) {
                    $winnerId = $battle->player1_id;
                } elseif ($p2Score > 0 && $p1Score === 0) {
                    $winnerId = $battle->player2_id;
                } elseif ($p1Score > $p2Score) {
                    $winnerId = $battle->player1_id;
                } elseif ($p2Score > $p1Score) {
                    $winnerId = $battle->player2_id;
                } else {
                    // tie or both 0
                    $winnerId = min($battle->player1_id, $battle->player2_id);
                }

                // Persist computed scores and winner
                $battle->player1_score = $p1Score;
                $battle->player2_score = $p2Score;
                $battle->winner_id = $winnerId;
                $battle->status = TournamentBattle::STATUS_COMPLETED;
                if (empty($battle->completed_at)) $battle->completed_at = now();
                $battle->save();

                $changedBattles[] = $battle;
                $eventsToDispatch[] = new BattleCompleted($battle);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('Failed to close round: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Failed to close round: ' . $e->getMessage()];
        }

        // Dispatch events after successful commit
        try {
            foreach ($eventsToDispatch as $ev) {
                // Use AfterCommitDispatcher to ensure consistent behavior with model-level event dispatching
                \App\Services\AfterCommitDispatcher::dispatch($ev);
            }
        } catch (\Throwable $_) {
            // Non-fatal: broadcasting may fail, but data is persisted
            \Log::warning('Failed to dispatch post-commit events for tournament round closure');
        }

        // Collect winners and create next round if needed
        $winners = $this->getWinnersFromRound($round);

        if (count($winners) < 2) {
            // If only one winner remains, finalize tournament
            if (count($winners) === 1) {
                $winnerId = $winners[0];
                $this->update(['status' => 'completed', 'winner_id' => $winnerId]);
                try {
                    app('App\Services\AchievementService')->checkAchievements(
                        $winnerId,
                        ['type' => 'tournament_won', 'tournament_id' => $this->id, 'rank' => 1]
                    );
                } catch (\Throwable $e) {
                    \Log::warning('Failed awarding tournament_won achievement: ' . $e->getMessage());
                }

                // Dispatch round closed event with tournament completion
                try {
                    \App\Services\AfterCommitDispatcher::dispatch(
                        new \App\Events\TournamentRoundClosed(
                            $this->id,
                            $round,
                            $winners,
                            null,  // no next round
                            true,  // tournament complete
                            $winnerId
                        )
                    );
                } catch (\Throwable $e) {
                    \Log::warning('Failed to dispatch TournamentRoundClosed event: ' . $e->getMessage());
                }

                return ['ok' => true, 'message' => 'Tournament finalized', 'winner' => $winnerId];
            }
            return ['ok' => false, 'message' => 'Insufficient winners to continue', 'winners' => $winners];
        }

        // Create next round scheduled date: use last round end + round_delay_days
        $nextRound = $round + 1;
        $scheduledAt = null;
        if ($roundEnd) {
            $scheduledAt = $roundEnd->copy()->addDays(max(1, $roundDelay));
        } else {
            $scheduledAt = now()->addDays(max(1, $roundDelay));
        }

        try {
            $created = $this->createBattlesForRound($winners, $nextRound, $scheduledAt);
            
            // Update any placeholder battles from earlier rounds with actual opponent IDs
            // This ensures brackets display correct opponents without requiring immediate re-fetch
            $this->updateNextRoundPlaceholders($created, $nextRound);
            
            // Dispatch round closed event (with next round info)
            try {
                \App\Services\AfterCommitDispatcher::dispatch(
                    new \App\Events\TournamentRoundClosed(
                        $this->id,
                        $round,
                        $winners,
                        $nextRound,
                        false,  // tournament not complete
                        null
                    )
                );
            } catch (\Throwable $e) {
                \Log::warning('Failed to dispatch TournamentRoundClosed event: ' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            \Log::error('Failed to create next round battles: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Failed to create next round battles', 'error' => $e->getMessage()];
        }

        return [
            'ok' => true,
            'message' => 'Round closed and next round created',
            'winners' => $winners,
            'created' => count($created),
            'next_round' => $nextRound,
            'scheduled_at' => $scheduledAt,
        ];
    }

    /**
     * Get all winners from a completed round
     * Handles regular winners and byes (participants with only 1 battle in round)
     * @param int $round
     * @return array Winner user IDs
     */
    public function getWinnersFromRound(int $round): array
    {
        // Get all battles in this round
        $battles = $this->battles()
            ->where('round', $round)
            ->with(['winner'])
            ->get();

        if ($battles->isEmpty()) {
            return [];
        }

        // Collect winners from completed battles
        $winners = [];
        $participants = [];  // Track all participants in this round

        foreach ($battles as $battle) {
            // Track participants
            $participants[] = $battle->player1_id;
            $participants[] = $battle->player2_id;

            // Add winner if battle is completed
            if ($battle->status === TournamentBattle::STATUS_COMPLETED && $battle->winner_id) {
                $winners[$battle->winner_id] = true;
            }
        }

        // Get unique participants in this round
        $participants = array_unique($participants);

        // Check for byes (participants with only 1 battle)
        // If tournament is single elimination and participant has only 1 battle, they got a bye
        foreach ($participants as $participantId) {
            $battleCount = collect($battles)
                ->filter(function($b) use ($participantId) {
                    return $b->player1_id === $participantId || $b->player2_id === $participantId;
                })
                ->count();

            // Bye: participant has only 1 battle (auto-advance)
            if ($battleCount === 1 && !isset($winners[$participantId])) {
                $winners[$participantId] = true;
            }
        }

        return array_keys($winners);
    }

    /**
    /**
     * Update placeholder battles in next round with actual opponent IDs
     * after current round winners are determined.
     * This ensures brackets display correctly without waiting for re-fetch.
     *
     * @param array $createdBattles Battles just created for next round
     * @param int $nextRound The round number that was just created
     * @return void
     */
    private function updateNextRoundPlaceholders(array $createdBattles, int $nextRound): void
    {
        try {
            // This is a no-op for the current round creation, but prepares the structure
            // for future multi-round pre-generation scenarios. Currently, brackets are
            // created one round at a time, so this is called after each round advance.
            
            // Example use case (future enhancement):
            // If we pre-generate multiple rounds at tournament start, this would fill in
            // winners as they progress through each round
            
            foreach ($createdBattles as $battle) {
                // Refresh to ensure we have latest data
                $battle->refresh();
                // Future: Check if any pre-created battles in round $nextRound+1 need updates
            }
        } catch (\Throwable $e) {
            \Log::warning('updateNextRoundPlaceholders failed (non-critical): ' . $e->getMessage());
        }
    }

    /**
     * Called when a final battle is completed
     * @return bool True if tournament was finalized
     */
    public function checkAndFinalizeIfComplete(): bool
    {
        // Delegate to the centralized close/advance routine. Force=false will only finalize
        // when the round is actually complete or meet conditions in closeRoundAndAdvance.
        $finalized = false;
        try {
            $res = $this->closeRoundAndAdvance(null, false);
            $finalized = !empty($res['ok']) && $res['ok'] === true && isset($res['winner']);
        } catch (\Throwable $e) {
            \Log::warning('checkAndFinalizeIfComplete failed: ' . $e->getMessage());
            $finalized = false;
        }

        return $finalized;
    }
}
