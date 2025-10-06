<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMetricsSetting extends Model
{
    protected $table = 'chat_metrics_settings';
    protected $fillable = ['retention_days'];
    public $timestamps = true;
}
