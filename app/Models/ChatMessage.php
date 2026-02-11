<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    use HasFactory;

    public const TYPE_TEXT = 'text';
    public const TYPE_IMAGE = 'image';
    public const TYPE_VIDEO = 'video';
    public const TYPE_VOICE = 'voice';
    public const TYPE_AUDIO = 'audio';
    public const TYPE_LINK = 'link';
    public const TYPE_FILE = 'file';

    public const SENDER_CUSTOMER = 'customer';
    public const SENDER_ASSISTANT = 'assistant';
    public const SENDER_AGENT = 'agent';
    public const SENDER_SYSTEM = 'system';

    public const DIRECTION_INBOUND = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';

    protected $fillable = [
        'user_id',
        'company_id',
        'chat_id',
        'assistant_id',
        'sender_type',
        'direction',
        'status',
        'channel_message_id',
        'message_type',
        'text',
        'media_url',
        'media_mime_type',
        'media_size',
        'link_url',
        'attachments',
        'payload',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'media_size' => 'integer',
            'attachments' => 'array',
            'payload' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
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

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(Assistant::class);
    }
}
