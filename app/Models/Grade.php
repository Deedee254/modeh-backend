<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
