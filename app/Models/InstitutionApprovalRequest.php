<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstitutionApprovalRequest extends Model
{
    protected $table = 'institution_approval_requests';

    protected $fillable = [
        'institution_name',
        'institution_location',
        'user_id',
        'profile_type',
        'profile_id',
        'status',
        'reviewed_by',
        'notes',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewedByUser()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approve($institutionId, $reviewedByUserId)
    {
        $this->update(['status' => 'approved', 'reviewed_by' => $reviewedByUserId]);

        $profileClass = $this->profile_type === 'quizee' ? Quizee::class : QuizMaster::class;
        $profileClass::where('id', $this->profile_id)->update(['institution_id' => $institutionId]);
    }

    public function reject($reviewedByUserId, $notes = null)
    {
        $this->update(['status' => 'rejected', 'reviewed_by' => $reviewedByUserId, 'notes' => $notes]);
    }
}
