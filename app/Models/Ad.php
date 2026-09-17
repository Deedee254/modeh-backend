<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ad extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'media_type',
        'media_url',
        'destination_url',
        'cta_text',
        'duration_seconds',
        'skip_after_seconds',
        'target_type',
        'is_active',
        'status',
        'impressions_count',
        'clicks_count',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'duration_seconds' => 'integer',
        'skip_after_seconds' => 'integer',
        'impressions_count' => 'integer',
        'clicks_count' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function targets()
    {
        return $this->hasMany(AdTarget::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->where('status', 'active');
    }

    /**
     * Increment impressions count atomically
     */
    public function recordImpression(): void
    {
        $this->increment('impressions_count');
    }

    /**
     * Increment clicks count atomically
     */
    public function recordClick(): void
    {
        $this->increment('clicks_count');
    }
}
