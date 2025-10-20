<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuizeeLevel extends Model
{
    protected $fillable = [
        'name',
        'min_points',
        'max_points',
        'icon',
        'description',
        'color_scheme',
        'order'
    ];

    protected $casts = [
        'min_points' => 'integer',
        'max_points' => 'integer',
        'order' => 'integer'
    ];

    public static function getLevel(int $points): ?self
    {
        return static::where('min_points', '<=', $points)
            ->where(function ($query) use ($points) {
                $query->where('max_points', '>=', $points)
                    ->orWhereNull('max_points');
            })
            ->orderBy('order')
            ->first();
    }

    public static function getNextLevel(int $points): ?self
    {
        return static::where('min_points', '>', $points)
            ->orderBy('min_points')
            ->first();
    }
}