<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class QuizAttempt extends Model
{
    use HasFactory;

    protected $table = 'quiz_attempts';
    protected $fillable = ['user_id', 'quiz_id', 'subscription_id', 'subscription_type', 'answers', 'score', 'points_earned', 'total_time_seconds', 'per_question_time'];
    protected $attributes = [
        'points_earned' => null,
    ];

    protected $casts = [
        'answers' => 'array',
        'score' => 'float',
        'points_earned' => 'float',
        'per_question_time' => 'array',
        'total_time_seconds' => 'integer',
    ];

    protected $dates = ['started_at'];

    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }
}
