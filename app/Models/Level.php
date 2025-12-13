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

    public function grades()
    {
        return $this->hasMany(Grade::class);
    }
}
