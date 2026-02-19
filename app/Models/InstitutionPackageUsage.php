<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * InstitutionPackageUsage Model
 * 
 * Tracks usage of institution packages for seat limits and quiz attempt quotas
 * 
 * @property int $id
 * @property int $institution_id
 * @property int|null $subscription_id The institution's active subscription
 * @property int $user_id The member using the service
 * @property string $usage_type (seat|quiz_attempt)
 * @property int $count Number of uses
 * @property \Carbon\Carbon $usage_date Date of usage
 * @property array|null $metadata Additional context
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * 
 * @property-read \App\Models\Institution $institution
 * @property-read \App\Models\Subscription|null $subscription
 * @property-read \App\Models\User $user
 */
class InstitutionPackageUsage extends Model
{
    use HasFactory;

    protected $table = 'institution_package_usage';

    protected $fillable = [
        'institution_id',
        'subscription_id',
        'user_id',
        'usage_type',
        'count',
        'usage_date',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'usage_date' => 'date',
        'count' => 'integer',
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
