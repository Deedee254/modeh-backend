<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMetricBucket extends Model
{
    protected $table = 'chat_metric_buckets';
    protected $fillable = ['metric_key', 'bucket', 'value', 'last_updated_at'];
    public $timestamps = true;
}
