<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assistant extends Model
{
    use HasFactory;

    public const TONE_POLITE = 'polite';
    public const TONE_CONCISE = 'concise';
    public const TONE_FRIENDLY = 'friendly';
    public const TONE_FORMAL = 'formal';
    public const TONE_CUSTOM = 'custom';

    protected $fillable = [
        'user_id',
        'company_id',
        'name',
        'openai_assistant_id',
        'openai_vector_store_id',
        'instructions',
        'restrictions',
        'conversation_tone',
        'is_active',
        'enable_file_search',
        'enable_file_analysis',
        'enable_voice',
        'enable_web_search',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'enable_file_search' => 'boolean',
            'enable_file_analysis' => 'boolean',
            'enable_voice' => 'boolean',
            'enable_web_search' => 'boolean',
            'settings' => 'array',
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

    public function instructionFiles(): HasMany
    {
        return $this->hasMany(AssistantInstructionFile::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(AssistantChannel::class);
    }

    public function chats(): HasMany
    {
        return $this->hasMany(Chat::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(AssistantService::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(AssistantProduct::class);
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
