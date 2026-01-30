<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Package Model
 * 
 * Represents subscription packages available for purchase
 * 
 * @property int $id
 * @property string $title
 * @property string $description
 * @property string $short_description
 * @property string $slug
 * @property float $price
 * @property string $currency
 * @property array $features
 * @property bool $is_active
 * @property int $duration_days
 * @property int $seats
 * @property string $cover_image
 * @property bool $is_default
 * @property string $audience
 * @property string $name (appended)
 * @property string $price_display (appended)
 * @property string $more_link (appended)
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class Package extends Model
{
    use HasFactory;
    protected $fillable = ['title','description','short_description','slug','price','currency','features','is_active','duration_days','seats','cover_image','is_default','audience'];

    protected $casts = [
        'features' => 'array',
        'price' => 'decimal:2',
        'is_active' => 'boolean',
        'duration_days' => 'integer',
        'seats' => 'integer',
        'is_default' => 'boolean',
    ];

    protected $attributes = [
        'audience' => 'quizee',
    ];

    /**
     * Scope packages for a specific audience (quizee | institution)
     */
    public function scopeForAudience($query, $audience = 'quizee')
    {
        return $query->where('audience', $audience);
    }

    public function scopeForQuizee($query)
    {
        return $query->where('audience', 'quizee');
    }

    public function scopeForInstitution($query)
    {
        return $query->where('audience', 'institution');
    }

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
