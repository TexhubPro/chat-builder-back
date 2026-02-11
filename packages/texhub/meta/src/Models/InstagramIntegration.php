<?php

namespace TexHub\Meta\Models;

use Illuminate\Database\Eloquent\Model;

class InstagramIntegration extends Model
{
    protected $table = 'instagram_integrations';

    protected $fillable = [
        'user_id',
        'instagram_user_id',
        'username',
        'receiver_id',
        'access_token',
        'token_expires_at',
        'profile_picture_url',
        'avatar_path',
        'is_active',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];
}
