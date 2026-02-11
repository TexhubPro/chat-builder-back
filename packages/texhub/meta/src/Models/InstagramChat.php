<?php

namespace TexHub\Meta\Models;

use Illuminate\Database\Eloquent\Model;

class InstagramChat extends Model
{
    protected $table = 'instagram_chats';

    protected $fillable = [
        'user_id',
        'instagram_integration_id',
        'instagram_user_id',
        'receiver_id',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];
}
