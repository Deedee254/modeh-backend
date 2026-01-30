<?php

namespace App\Models;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $owner_type
 * @property int $owner_id
 * @property int|null $package_id
 * @property string $status (pending|active|cancelled|expired)
 * @property string $gateway (mpesa|stripe|free|etc)
 * @property array $gateway_meta
 * @property \DateTimeInterface|null $started_at
 * @property \DateTimeInterface|null $ends_at
 * @property \DateTimeInterface $created_at
 * @property \DateTimeInterface $updated_at
 * @property \DateTimeInterface|null $renews_at
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

    /**
     * Assignment relations: which users (quizees) have been assigned seats from this subscription
     */
    public function assignments()
    {
        return $this->hasMany(\App\Models\SubscriptionAssignment::class);
    }

    /**
     * Invoices related to this subscription
     */
    public function invoices()
    {
        return $this->morphMany(\App\Models\Invoice::class, 'invoiceable');
    }

    /**
     * Return number of seats available (seats - active assignments)
     */
    public function availableSeats()
    {
        $seats = optional($this->package)->seats;
        if (is_null($seats)) return null; // unlimited
        $assigned = $this->assignments()->whereNull('revoked_at')->count();
        return max(0, (int)$seats - (int)$assigned);
    }

    /**
     * Assign a seat to a user. Returns the SubscriptionAssignment on success or null on failure.
     */
    public function assignUser(int $userId, ?int $assignedBy = null)
    {
        // If package has no seat limit, allow assignment
        $seats = optional($this->package)->seats;
        if (!is_null($seats)) {
            $available = $this->availableSeats();
            if ($available <= 0) {
                return null; // no seats
            }
        }

        // create or update a unique assignment
        $assignment = \App\Models\SubscriptionAssignment::firstOrCreate([
            'subscription_id' => $this->id,
            'user_id' => $userId,
        ], [
            'assigned_by' => $assignedBy,
            'assigned_at' => now(),
            'revoked_at' => null,
        ]);

        // If it existed but was revoked, revive it
        if ($assignment->revoked_at) {
            $assignment->revoked_at = null;
            $assignment->assigned_by = $assignedBy;
            $assignment->assigned_at = now();
            $assignment->save();
        }

        return $assignment;
    }
}
