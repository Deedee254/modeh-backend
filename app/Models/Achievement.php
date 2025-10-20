<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Achievement extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'color',
        'points',
        'type',
        'criteria',
        'criteria_value',
        'is_active',
    ];

    protected $casts = [
        'criteria' => 'json',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_achievements')
            ->withTimestamps()
            ->withPivot('progress', 'completed_at', 'attempt_id');
    }
}