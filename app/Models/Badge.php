<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Badge extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'icon',
        'type', // difficulty, mode, meta
        'criteria', // json or string
        'points_reward',
    ];

    protected $casts = [
        'criteria' => 'json',
    ];

    public function quizees()
    {
        return $this->belongsToMany(Quizee::class, 'user_badges')->withPivot('earned_at', 'attempt_id')->withTimestamps();
    }
}