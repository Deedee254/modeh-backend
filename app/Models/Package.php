<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Package extends Model
{
    use HasFactory;
    protected $fillable = ['title','description','short_description','slug','price','currency','features','is_active','duration_days','seats','cover_image','is_default'];

    protected $casts = [
        'features' => 'array',
        'price' => 'decimal:2',
        'is_active' => 'boolean',
        'duration_days' => 'integer',
        'seats' => 'integer',
        'is_default' => 'boolean',
    ];

    protected $appends = ['name', 'price_display', 'more_link'];

    // Provide a friendly name property for frontend compatibility
    public function getNameAttribute()
    {
        return $this->attributes['title'] ?? null;
    }

    public function getPriceDisplayAttribute()
    {
        // Example price display: KES 300.00 or Free
    if (is_null($this->price) || $this->price == 0) return 'Free';
    return ($this->currency ? $this->currency . ' ' : '') . number_format((float)$this->price, 2);
    }

    public function getMoreLinkAttribute()
    {
        if ($this->slug) return '/packages/' . $this->slug;
        return null;
    }
}
