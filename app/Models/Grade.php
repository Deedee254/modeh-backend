<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Grade extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'level_id', 'type'];

    public function subjects()
    {
        return $this->hasMany(Subject::class);
    }

    public function level()
    {
        return $this->belongsTo(Level::class);
    }
}
