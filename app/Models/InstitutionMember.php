<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InstitutionMember extends Model
{
    use HasFactory;

    protected $table = 'institution_user';

    protected $fillable = [
        'user_id',
        'institution_id',
        'role',
        'status',
        'invited_by',
        'invitation_token',
        'invitation_expires_at',
        'invitation_status',
        'invited_email',
        'last_activity_at',
        'joined_at',
    ];

    protected $casts = [
        'invitation_expires_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'joined_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function invitedByUser()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
