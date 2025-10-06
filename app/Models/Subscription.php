<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = ['user_id','package_id','status','gateway','gateway_meta','started_at','ends_at'];

    protected $casts = [
        'gateway_meta' => 'array',
        'started_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function activate(
        \DateTimeInterface $startsAt = null,
        \DateTimeInterface $endsAt = null
    ) {
        $this->status = 'active';
        $this->started_at = $startsAt ?? now();
        $this->ends_at = $endsAt ?? ($this->started_at ? $this->started_at->addDays(optional($this->package)->duration_days ?? 30) : now()->addDays(optional($this->package)->duration_days ?? 30));
        $this->save();
        return $this;
    }
}
