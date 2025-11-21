<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property bool $auto_approve_topics Whether to auto-approve topics
 * @property bool $auto_approve_quizzes Whether to auto-approve quizzes
 * @property bool $auto_approve_questions Whether to auto-approve questions
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
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
