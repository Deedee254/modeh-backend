<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdTarget extends Model
{
    use HasFactory;

    protected $fillable = [
        'ad_id',
        'target_type',
        'target_id',
    ];

    public function ad()
    {
        return $this->belongsTo(Ad::class);
    }
}
