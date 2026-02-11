<?php

namespace TexHub\Meta\Models;

use Illuminate\Database\Eloquent\Model;

class InstagramMessage extends Model
{
    protected $table = 'instagram_messages';

    protected $fillable = [
        'chat_id',
        'direction',
        'sender_id',
        'recipient_id',
        'message_type',
        'text',
        'media_url',
        'media_type',
        'payload',
        'sent_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'sent_at' => 'datetime',
    ];
}
