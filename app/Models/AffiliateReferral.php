<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $affiliate_id
 * @property int $user_id
 * @property string $type
 * @property float $earnings
 * @property string $status
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read \App\Models\Affiliate $affiliate
 * @property-read \App\Models\User $user
 */
class AffiliateReferral extends Model
{
    use HasFactory;

    protected $fillable = [
        'affiliate_id',
        'user_id',
        'type',
        'earnings',
        'status',
    ];

    protected $casts = [
        'earnings' => 'float',
    ];

    public function affiliate()
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
