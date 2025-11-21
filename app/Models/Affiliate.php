<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $referral_code
 * @property float $commission_rate
 * @property float $total_earnings
 * @property string $status
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read \App\Models\User $user
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\AffiliateReferral[] $referrals
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\AffiliatePayout[] $payouts
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\AffiliateLinkClick[] $linkClicks
 */
class Affiliate extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'referral_code',
        'commission_rate',
        'total_earnings',
        'status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function referrals()
    {
        return $this->hasMany(AffiliateReferral::class);
    }

    public function payouts()
    {
        return $this->hasMany(AffiliatePayout::class);
    }

    public function linkClicks()
    {
        return $this->hasMany(AffiliateLinkClick::class);
    }
}
