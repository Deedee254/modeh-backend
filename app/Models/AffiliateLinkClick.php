<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AffiliateLinkClick extends Model
{
    protected $fillable = [
        'user_id',
        'affiliate_code',
        'source_url',
        'target_url',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'user_agent',
        'ip_address',
        'converted_at'
    ];

    protected $casts = [
        'converted_at' => 'datetime'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeConverted($query)
    {
        return $query->whereNotNull('converted_at');
    }

    public function scopeNotConverted($query)
    {
        return $query->whereNull('converted_at');
    }
}