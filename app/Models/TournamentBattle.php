<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
        'status' // 'scheduled', 'in_progress', 'completed'
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'completed_at' => 'datetime'
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

    public function questions()
    {
        return $this->belongsToMany(Question::class, 'tournament_battle_questions')
            ->withPivot(['position'])
            ->orderBy('position');
    }
}