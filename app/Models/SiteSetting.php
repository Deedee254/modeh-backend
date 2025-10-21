<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    protected $table = 'site_settings';
    protected $fillable = ['auto_approve_topics','auto_approve_quizzes','auto_approve_questions'];

    // return the first row (singleton settings table)
    public static function current()
    {
        return static::first();
    }
}
