<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BattleSubmission extends Model
{
    use HasFactory;

    protected $table = 'battle_submissions';

    protected $fillable = [
        'battle_id',
        'user_id',
        'question_id',
        'selected',
        'time_taken',
        'correct_flag',
    ];

    protected $casts = [
        'selected' => 'array',
        'time_taken' => 'float',
        'correct_flag' => 'boolean',
    ];

    public function battle()
    {
        return $this->belongsTo(Battle::class);
    }

    public function question()
    {
        return $this->belongsTo(Question::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
