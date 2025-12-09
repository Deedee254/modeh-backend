<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Events\Tournament\BattleStarted;
use App\Events\Tournament\BattleCompleted;
use App\Events\Tournament\BattleForfeited;
use App\Events\Tournament\BattleCancelled;
use App\Services\AfterCommitDispatcher;

/**
 * Class TournamentBattle
 *
 * @property int $id
 * @property int $tournament_id
 * @property int $round
 * @property int $player1_id
 * @property int $player2_id
 * @property int|null $winner_id
 * @property string $status
 * @property float|null $player1_score
 * @property float|null $player2_score
 * @property \Illuminate\Support\Carbon|null $scheduled_at
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $timeout_at
 * @property int|null $battle_duration
 * @property bool $is_active
 * @property bool $can_start
 * @property int|null $time_remaining
 * @property bool $has_timed_out
 *
 * Relations:
 * @property \App\Models\User $player1
 * @property \App\Models\User $player2
 * @property \Illuminate\Database\Eloquent\Collection|\App\Models\Question[] $questions
 */
class TournamentBattle extends Model
{
    protected $fillable = [
        'tournament_id',
        'round',
        'player1_id',
        'player2_id', 
        'winner_id',
        'player1_score',
        'player2_score',
        'scheduled_at',
        'completed_at',
        'started_at',
        'status',
        'forfeit_reason',
        'is_draw',
        'timeout_at',
        'battle_duration'
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'completed_at' => 'datetime',
        'started_at' => 'datetime',
        'timeout_at' => 'datetime',
        'player1_score' => 'float',
        'player2_score' => 'float',
        'is_draw' => 'boolean',
        'battle_duration' => 'integer'
    ];

    protected $appends = [
        'is_active',
        'can_start',
        'time_remaining',
        'has_timed_out'
    ];

    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FORFEITED = 'forfeited';
    const STATUS_CANCELLED = 'cancelled';

    const VALID_STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_FORFEITED,
        self::STATUS_CANCELLED
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function player1()
    {
        return $this->belongsTo(User::class, 'player1_id');
    }

    public function player2()
    {
        return $this->belongsTo(User::class, 'player2_id'); 
    }

    public function winner()
    {
        return $this->belongsTo(User::class, 'winner_id');
    }

    public function getIsActiveAttribute()
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function getCanStartAttribute()
    {
        if ($this->status !== self::STATUS_SCHEDULED) {
            return false;
        }

        return now()->gte($this->scheduled_at);
    }

    public function getTimeRemainingAttribute()
    {
        if (!$this->is_active || !$this->timeout_at) {
            return null;
        }

        $remaining = $this->timeout_at->diffInSeconds(now(), false);
        return $remaining > 0 ? $remaining : 0;
    }

    public function getHasTimedOutAttribute()
    {
        return $this->is_active && 
               $this->timeout_at && 
               now()->gte($this->timeout_at);
    }

    public function start()
    {
        if (!$this->can_start) {
            throw new \Exception('Battle cannot be started');
        }

        $this->status = self::STATUS_IN_PROGRESS;
        $this->started_at = now();
        $this->timeout_at = now()->addMinutes(30); // Configure timeout duration
        $this->save();

        // Ensure events are dispatched only after DB commit
        AfterCommitDispatcher::dispatch(new BattleStarted($this));
    }

    public function complete($winnerId = null, $isDraw = false)
    {
        $this->status = self::STATUS_COMPLETED;
        $this->completed_at = now();
        $this->is_draw = $isDraw;
        $this->winner_id = $winnerId;
        $this->battle_duration = $this->started_at->diffInSeconds($this->completed_at);
        $this->save();

        // Dispatch after commit to avoid sending events before DB is saved
        AfterCommitDispatcher::dispatch(new BattleCompleted($this));
    }

    public function forfeit($playerId, $reason = null)
    {
        $this->status = self::STATUS_FORFEITED;
        $this->completed_at = now();
        $this->forfeit_reason = $reason;
        $this->winner_id = $this->player1_id === $playerId ? $this->player2_id : $this->player1_id;
        $this->battle_duration = $this->started_at->diffInSeconds($this->completed_at);
        $this->save();

        AfterCommitDispatcher::dispatch(new BattleForfeited($this));
    }

    public function cancel()
    {
        $this->status = self::STATUS_CANCELLED;
        $this->completed_at = now();
        $this->save();

        AfterCommitDispatcher::dispatch(new BattleCancelled($this));
    }

    public function questions()
    {
        return $this->belongsToMany(Question::class, 'tournament_battle_questions')
            ->withPivot(['position'])
            ->orderBy('position');
    }

    /**
     * Per-question attempts for this battle (saved per player)
     */
    public function attempts()
    {
        return $this->hasMany(TournamentBattleAttempt::class, 'battle_id');
    }
}