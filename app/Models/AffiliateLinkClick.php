<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AffiliateLinkClick extends Model
{
    use HasFactory;

    protected $fillable = [
        'affiliate_id',
        'ip_address',
        'user_agent',
        'clicked_at',
    ];

    public function affiliate()
    {
        return $this->belongsTo(Affiliate::class);
    }
}
