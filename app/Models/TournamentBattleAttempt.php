<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $battle_id
 * @property int $player_id
 * @property int $question_id
 * @property string|null $answer
 * @property float|null $points
 *
 * Relations:
 * @property TournamentBattle|null $battle
 * @property User|null $player
 * @property Question|null $question
 */
class TournamentBattleAttempt extends Model
{
    protected $table = 'tournament_battle_attempts';

    protected $fillable = [
        'battle_id',
        'player_id',
        'question_id',
        'answer',
        'points'
    ];

    protected $casts = [
        'points' => 'float',
    ];

    public function battle()
    {
        return $this->belongsTo(TournamentBattle::class, 'battle_id');
    }

    public function player()
    {
        return $this->belongsTo(User::class, 'player_id');
    }

    public function question()
    {
        return $this->belongsTo(Question::class, 'question_id');
    }
}
