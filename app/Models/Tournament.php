<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Tournament extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'start_date',
        'end_date',
        'prize_pool',
        'max_participants',
        'entry_fee',
        'status', // 'upcoming', 'active', 'completed'
        'rules',
        'subject_id',
        'topic_id',
        'grade_id',
        'created_by',
        'sponsor_id',
        'sponsor_banner_url',
        'sponsor_message'
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'rules' => 'array',
        'entry_fee' => 'decimal:2'
    ];

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function topic()
    {
        return $this->belongsTo(Topic::class);
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function participants()
    {
        return $this->belongsToMany(User::class, 'tournament_participants')
            ->withPivot(['score', 'rank', 'completed_at'])
            ->withTimestamps();
    }

    public function questions()
    {
        return $this->belongsToMany(Question::class, 'tournament_questions')
            ->withPivot(['position'])
            ->orderBy('position');
    }

    public function battles()
    {
        return $this->hasMany(TournamentBattle::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sponsor()
    {
        return $this->belongsTo(Sponsor::class);
    }
}