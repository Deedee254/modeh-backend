<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    use HasFactory;

    protected $fillable = ['grade_id', 'created_by', 'name', 'description', 'is_approved', 'auto_approve', 'icon', 'is_active'];

    protected $casts = [
        'is_approved' => 'boolean',
        'auto_approve' => 'boolean',
        'approval_requested_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function topics()
    {
        return $this->hasMany(Topic::class);
    }
}
