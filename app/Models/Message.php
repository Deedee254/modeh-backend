<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = [
        'sender_id', 'recipient_id', 'content', // new DM fields
        'is_read',
        'group_id',
        'type' // message type (direct, support, group)
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read' => 'boolean',
        'type' => 'string'
    ];

    /**
     * Keep some legacy attributes available when the model is serialized so
     * older frontend code (and some tests) still receive the expected keys.
     */
    // No legacy attribute appends: frontend now expects the exact new field names.

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function group()
    {
        return $this->belongsTo(\App\Models\Group::class, 'group_id');
    }
    // Legacy compatibility removed. Use the explicit new fields: sender_id, recipient_id, content, is_read.
}
