<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Question;

class Battle extends Model
{
    use HasFactory;

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    protected $fillable = [
        'initiator_id',
        'opponent_id',
        'status',
        'winner_id',
        'initiator_points',
        'opponent_points',
        'rounds_completed',
        'completed_at',
        'name',
        'one_off_price',
        'settings',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'settings' => 'array',
        'one_off_price' => 'decimal:2',
    ];

    public function initiator()
    {
        return $this->belongsTo(quizee::class, 'initiator_id');
    }

    public function opponent()
    {
        return $this->belongsTo(quizee::class, 'opponent_id');
    }

    public function winner()
    {
        return $this->belongsTo(quizee::class, 'winner_id');
    }

    public function questions()
    {
        return $this->belongsToMany(Question::class, 'battle_questions')->withPivot('position')->withTimestamps();
    }

    /**
     * Use uuid for route model binding when present.
     */
    /**
     * Per-question submissions for this battle.
     */
    public function submissions()
    {
        return $this->hasMany(BattleSubmission::class);
    }
    public function getRouteKeyName()
    {
        return 'uuid';
    }
}