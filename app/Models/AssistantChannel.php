<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssistantChannel extends Model
{
    use HasFactory;

    public const CHANNEL_INSTAGRAM = 'instagram';
    public const CHANNEL_TELEGRAM = 'telegram';
    public const CHANNEL_WHATSAPP = 'whatsapp';
    public const CHANNEL_WIDGET = 'widget';
    public const CHANNEL_WEBCHAT = 'webchat';
    public const CHANNEL_OTHER = 'other';

    protected $fillable = [
        'user_id',
        'company_id',
        'assistant_id',
        'channel',
        'name',
        'external_account_id',
        'is_active',
        'credentials',
        'settings',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'credentials' => 'array',
            'settings' => 'array',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(Assistant::class);
    }

    public function chats(): HasMany
    {
        return $this->hasMany(Chat::class);
    }
}
