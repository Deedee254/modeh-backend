<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class QuizAttempt extends Model
{
    use HasFactory;

    protected $table = 'quiz_attempts';
    protected $fillable = ['user_id', 'quiz_id', 'answers', 'score'];
    protected $attributes = [
        'points_earned' => null,
    ];

    protected $casts = [
        'answers' => 'array',
        'score' => 'float',
        'points_earned' => 'float',
    ];
}
