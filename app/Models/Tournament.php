<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Level;
use App\Models\Question;

/**
 * Class Tournament
 *
 * Tournament Timing:
 * - start_date: When tournament registration opens (participants join via POST /tournaments/{id}/join)
 * - qualifier_days: Duration of the qualifier phase.
 * - end_date: When qualification closes and winner can be finalized.
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

    protected $appends = ['registration_open', 'can_start'];

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

}
