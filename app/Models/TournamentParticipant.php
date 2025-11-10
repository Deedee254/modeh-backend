<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TournamentParticipant extends Model
{
    protected $table = 'tournament_participants';

    // If your pivot uses incrementing id and timestamps, keep defaults.
    // Otherwise, adjust $incrementing and $timestamps accordingly.

    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function tournament()
    {
        return $this->belongsTo(Tournament::class, 'tournament_id');
    }
}
