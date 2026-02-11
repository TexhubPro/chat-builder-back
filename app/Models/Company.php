<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Company extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'short_description',
        'industry',
        'primary_goal',
        'contact_email',
        'contact_phone',
        'website',
        'status',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assistants(): HasMany
    {
        return $this->hasMany(Assistant::class);
    }

    public function assistantChannels(): HasMany
    {
        return $this->hasMany(AssistantChannel::class);
    }

    public function chats(): HasMany
    {
        return $this->hasMany(Chat::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(CompanySubscription::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function assistantServices(): HasMany
    {
        return $this->hasMany(AssistantService::class);
    }

    public function assistantProducts(): HasMany
    {
        return $this->hasMany(AssistantProduct::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(CompanyClient::class);
    }

    public function clientOrders(): HasMany
    {
        return $this->hasMany(CompanyClientOrder::class);
    }

    public function calendarEvents(): HasMany
    {
        return $this->hasMany(CompanyCalendarEvent::class);
    }

    public function clientQuestions(): HasMany
    {
        return $this->hasMany(CompanyClientQuestion::class);
    }

    public function clientTasks(): HasMany
    {
        return $this->hasMany(CompanyClientTask::class);
    }
}
