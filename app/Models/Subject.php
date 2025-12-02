<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Subject
 *
 * @property int $id
 * @property int|null $grade_id
 * @property int|null $created_by User ID who created the subject
 * @property string $name
 * @property string|null $description
 * @property bool $is_approved
 * @property bool $auto_approve
 * @property \DateTime|null $approval_requested_at
 * @property string|null $icon
 * @property bool $is_active
 * @property \DateTime|null $created_at
 * @property \DateTime|null $updated_at
 */
class Subject extends Model
{
    use HasFactory;

    // Ensure created_by and other attributes can be mass assigned
    protected $fillable = ['grade_id', 'created_by', 'name', 'description', 'is_approved', 'auto_approve', 'approval_requested_at', 'icon', 'is_active'];

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

    /**
     * Owner / creator relationship helper
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
