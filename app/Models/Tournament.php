<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Level;

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
        'winner_id',
        'sponsor_id',
        'sponsor_banner',
        'sponsor_details',
        'requires_approval',
        'is_featured',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'registration_end_date' => 'datetime',
        'entry_fee' => 'decimal:2',
        'prize_pool' => 'decimal:2',
        'rules' => 'array',
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
            $player1 = $p[$i];
            $player2 = $p[$j];

            $battle = TournamentBattle::create([
                'tournament_id' => $this->id,
                'round' => $round,
                'player1_id' => $player1,
                'player2_id' => $player2,
                'status' => 'scheduled',
                'scheduled_at' => $scheduledAt ? $scheduledAt : $this->start_date,
            ]);
            $created[] = $battle;
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
            'battles' => $this->battles()->where('round', $round)->with(['player1', 'player2'])->get()
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
}