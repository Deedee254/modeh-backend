<?php

namespace App\Models;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $status
 * @property \DateTimeInterface|null $started_at
 * @property \DateTimeInterface|null $ends_at
 * @property int|null $package_id
 * @property int $user_id
 * @property \DateTimeInterface $created_at
 * @property \DateTimeInterface $updated_at
 * @property-read \App\Models\User $user
 * @property-read \App\Models\Package|null $package
 */
class Subscription extends Model
{
    use HasFactory;

    protected $fillable = ['user_id','owner_type','owner_id','package_id','status','gateway','gateway_meta','started_at','ends_at'];

    protected $casts = [
        'gateway_meta' => 'array',
        'started_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function package()
    {
        return $this->belongsTo(Package::class);
    }
    /**
     * Backwards-compatible: keep user() relation for existing code.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Polymorphic owner: either a User or an Institution (future other owners possible)
     */
    public function owner()
    {
        return $this->morphTo();
    }
    
    public function activate(
        ?DateTimeInterface $startsAt = null,
        ?DateTimeInterface $endsAt = null
    ): self
    {
        $this->status = 'active';
        $this->started_at = $startsAt ?? now();
        $this->ends_at = $endsAt ?? now()->addDays(optional($this->package)->duration_days ?? 30);
        $this->save();

        return $this;
    }
}
