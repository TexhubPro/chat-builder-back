<?php

namespace App\Models;

use App\Services\CalendarKanbanSyncService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyClientTask extends Model
{
    use HasFactory;

    public const STATUS_TODO = 'todo';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_DONE = 'done';
    public const STATUS_CANCELED = 'canceled';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    protected $fillable = [
        'user_id',
        'company_id',
        'company_client_id',
        'assistant_id',
        'company_calendar_event_id',
        'description',
        'status',
        'board_column',
        'position',
        'priority',
        'sync_with_calendar',
        'scheduled_at',
        'due_at',
        'completed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'sync_with_calendar' => 'boolean',
            'scheduled_at' => 'datetime',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $task): void {
            app(CalendarKanbanSyncService::class)->applyCalendarStateToTask($task);
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

    public function calendarEvent(): BelongsTo
    {
        return $this->belongsTo(CompanyCalendarEvent::class, 'company_calendar_event_id');
    }
}
