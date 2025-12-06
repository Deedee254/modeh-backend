<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TournamentQualificationAttempt extends Model
{
    use HasFactory;

    protected $table = 'tournament_qualification_attempts';

    protected $fillable = [
        'tournament_id',
        'user_id',
        'score',
        'answers',
        'duration_seconds',
    ];

    protected $casts = [
        'answers' => 'array',
        'score' => 'float',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
