<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * PricingSetting Model
 * 
 * Stores global default pricing for quizzes and battles.
 * Intended to be a singleton: only one record in the table.
 * 
 * @property int $id
 * @property float|null $default_quiz_one_off_price Global default one-off price for quizzes (if quiz has no price set)
 * @property float|null $default_battle_one_off_price Global default one-off price for battles (if battle has no price set)
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class PricingSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'default_quiz_one_off_price',
        'default_battle_one_off_price',
    ];

    protected $casts = [
        'default_quiz_one_off_price' => 'decimal:2',
        'default_battle_one_off_price' => 'decimal:2',
    ];

    /**
     * Get or create the singleton pricing setting.
     */
    public static function singleton()
    {
        return static::firstOrCreate([], []);
    }
}
