<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Tournament extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'start_date',
        'end_date',
        'prize_pool',
        'max_participants',
        'entry_fee',
        'status', // 'upcoming', 'active', 'completed'
        'rules',
        'subject_id',
        'topic_id',
        'grade_id',
        'created_by',
        'sponsor_id',
        'sponsor_banner_url',
        'status', // 'upcoming', 'active', 'completed'
        'winner_id',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'rules' => 'array',

        'entry_fee' => 'decimal:2',
    ];

    public function winner()
    {
        return $this->belongsTo(User::class, 'winner_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
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
            ->withPivot(['score', 'rank', 'completed_at'])
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