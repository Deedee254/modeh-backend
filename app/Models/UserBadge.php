<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserBadge extends Model
{
    use HasFactory;

    protected $table = 'user_badges';

    protected $fillable = [
        'user_id',
        'badge_id',
        'earned_at',
        'attempt_id',
    ];

    protected $casts = [
        'earned_at' => 'datetime',
    ];

    public function quizee()
    {
        return $this->belongsTo(Quizee::class);
    }

    public function badge()
    {
        return $this->belongsTo(Badge::class);
    }
}