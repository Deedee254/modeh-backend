<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Level model - represents learning levels (e.g., beginner, intermediate, advanced)
 * 
 * @property int $id
 * @property string $name
 * @property string|null $slug
 * @property int|null $order
 * @property string|null $description
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<\App\Models\Grade> $grades
 */
class Level extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'order', 'description'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->slug) && !empty($model->name)) {
                $model->slug = \App\Services\SlugService::makeUniqueSlug($model->name, static::class);
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('name') && !empty($model->name)) {
                $model->slug = \App\Services\SlugService::makeUniqueSlug($model->name, static::class, $model->id);
            }
        });
    }

    public function grades()
    {
        return $this->hasMany(Grade::class);
    }
}
