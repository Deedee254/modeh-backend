<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Topic extends Model
{
    use HasFactory;

    protected $fillable = ['subject_id', 'created_by', 'name', 'description', 'is_approved', 'approval_requested_at', 'image'];

    protected $casts = [
        'is_approved' => 'boolean',
        'approval_requested_at' => 'datetime',
    ];

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function quizzes()
    {
        return $this->hasMany(Quiz::class);
    }
}
