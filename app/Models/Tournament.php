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
     * Calculate recommended and minimum question counts based on tournament qualifier settings.
     * Since the tournament is qualifier-only (static quiz flow), we simply need enough questions
     * in the pool to satisfy the configured quiz length.
     * 
     * @return array ['minimum' => int, 'optimum' => int, 'current' => int, 'breakdown' => array]
     */
    public function getQuestionRecommendations(): array
    {
        $qualifierCount = $this->qualifier_question_count ?? 10;
        $currentCount = $this->questions()->count();
        $participantCount = $this->participants()->count();

        $minimum = $qualifierCount;
        $optimum = $qualifierCount * 2; // Recommended to support healthy variety in shuffled attempts

        return [
            'minimum' => $minimum,
            'optimum' => $optimum,
            'current' => $currentCount,
            'participants' => $participantCount,
            'total_rounds' => 1,
            'breakdown' => [
                [
                    'round' => 1,
                    'battles' => 1,
                    'questions_per_battle' => $qualifierCount,
                    'minimum_questions' => $minimum,
                    'optimum_questions' => $optimum,
                ]
            ],
            'status' => $currentCount >= $optimum ? 'excellent' 
                      : ($currentCount >= $minimum ? 'good' : 'warning'),
            'message' => $currentCount >= $optimum 
                ? "Excellent! Your {$currentCount} questions exceed the optimum recommended ({$optimum} questions) for healthy variety and shuffling."
                : ($currentCount >= $minimum 
                    ? "Good! Your {$currentCount} questions cover the required quiz length ({$minimum}). Organizers are encouraged to add up to {$optimum} questions for better variety."
                    : "Warning: You have only {$currentCount} questions but the tournament requires {$minimum} questions per attempt (short by " . ($minimum - $currentCount) . "). Users will not be able to complete attempts!"),
        ];
    }

    /**
     * Calculate recommended max participants based on question count.
     * Since the tournament is qualifier-only, all participants take the same quiz, 
     * so we can support unlimited participants regardless of question pool size.
     * 
     * @return array
     */
    public function getMaxParticipantsRecommendation(): array
    {
        $currentQuestions = $this->questions()->count();
        $qualifierCount = $this->qualifier_question_count ?? 10;
        $currentParticipants = $this->participants()->count();

        $isSufficient = $currentQuestions >= $qualifierCount;

        return [
            'current_questions' => $currentQuestions,
            'current_participants' => $currentParticipants,
            'question_per_battle' => $qualifierCount,
            'recommended_min_max_participants' => $this->max_participants ?? 1000,
            'recommended_max_max_participants' => $this->max_participants ?? 1000,
            'message' => $isSufficient 
                ? "✅ Perfect! Your question pool of {$currentQuestions} questions is sufficient to support any number of participants (current: {$currentParticipants})."
                : "⚠️ Warning: The question pool has only {$currentQuestions} questions, which is less than the required {$qualifierCount} questions per attempt. Please add more questions.",
        ];
    }

}
