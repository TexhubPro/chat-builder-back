<?php

namespace App\Models;

use App\Services\CalendarKanbanSyncService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyCalendarEvent extends Model
{
    use HasFactory;

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_NO_SHOW = 'no_show';

    protected $fillable = [
        'user_id',
        'company_id',
        'company_client_id',
        'assistant_id',
        'assistant_service_id',
        'title',
        'description',
        'starts_at',
        'ends_at',
        'timezone',
        'status',
        'location',
        'meeting_link',
        'reminders',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'reminders' => 'array',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (self $event): void {
            app(CalendarKanbanSyncService::class)->syncFromCalendarEvent($event);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(CompanyClient::class, 'company_client_id');
    }

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(Assistant::class);
    }

    public function assistantService(): BelongsTo
    {
        return $this->belongsTo(AssistantService::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(CompanyClientTask::class, 'company_calendar_event_id');
    }
}
