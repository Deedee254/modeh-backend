<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $question_id
 * @property int $user_id
 * @property string $reason
 * @property string $status
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * 
 * @property-read \App\Models\Question $question
 * @property-read \App\Models\User $user
 */
class QuestionFlag extends Model
{
    use HasFactory;

    protected $fillable = [
        'question_id',
        'user_id',
        'reason',
        'status',
    ];

    public function question()
    {
        return $this->belongsTo(Question::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
