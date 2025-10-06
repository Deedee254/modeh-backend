<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMetric extends Model
{
    protected $table = 'chat_metrics';
    protected $fillable = ['key', 'value', 'last_updated_at'];
    public $timestamps = true;
}
