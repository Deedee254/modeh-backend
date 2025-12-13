<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Grade model - represents grade levels within a learning level
 * 
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property int $level_id
 * @property string|null $type
 * @property string|null $display_name
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \App\Models\Level $level
 */
class Grade extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'level_id', 'type', 'display_name', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function getDisplayNameAttribute($value)
    {
        if (filled($value)) {
            return $value;
        }

        if (filled($this->attributes['name'] ?? null)) {
            return $this->attributes['name'];
        }

        $id = $this->attributes['id'] ?? null;

        if ($id !== null) {
            return 'Grade ' . $id;
        }

        return '';
    }

    public function subjects()
    {
        return $this->hasMany(Subject::class);
    }

    public function level()
    {
        return $this->belongsTo(Level::class);
    }
}
