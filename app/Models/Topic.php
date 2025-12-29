<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Topic extends Model
{
    use HasFactory;

    protected $fillable = ['subject_id', 'created_by', 'name', 'slug', 'description', 'is_approved', 'approval_requested_at', 'image'];

    protected $casts = [
        'is_approved' => 'boolean',
        'approval_requested_at' => 'datetime',
    ];

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

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function quizzes()
    {
        return $this->hasMany(Quiz::class);
    }

    public function representativeQuiz()
    {
        return $this->hasOne(Quiz::class)->whereNotNull('cover_image')->latestOfMany();
    }
}
