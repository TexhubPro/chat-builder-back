<?php

namespace App\Services;

use App\Models\CompanyCalendarEvent;
use App\Models\CompanyClientTask;
use Carbon\Carbon;

class CalendarKanbanSyncService
{
    public function applyCalendarStateToTask(CompanyClientTask $task): void
    {
        if ($task->sync_with_calendar === false || !$task->company_calendar_event_id) {
            return;
        }

        $event = $task->relationLoaded('calendarEvent')
            ? $task->calendarEvent
            : CompanyCalendarEvent::query()->find($task->company_calendar_event_id);

        if (!$event) {
            return;
        }

        $task->scheduled_at = $event->starts_at;
        $task->due_at = $event->ends_at ?? $event->starts_at;
        $task->status = $this->mapCalendarToTaskStatus((string) $event->status);
        $task->board_column = $this->mapTaskStatusToBoardColumn((string) $task->status);

        if (in_array($task->status, [CompanyClientTask::STATUS_DONE, CompanyClientTask::STATUS_CANCELED], true)) {
            $task->completed_at = $task->completed_at ?? Carbon::now();
        } else {
            $task->completed_at = null;
        }
    }

    public function syncFromCalendarEvent(CompanyCalendarEvent $event): void
    {
        CompanyClientTask::query()
            ->where('company_calendar_event_id', $event->id)
            ->where('sync_with_calendar', true)
            ->get()
            ->each(function (CompanyClientTask $task) use ($event): void {
                $task->setRelation('calendarEvent', $event);
                $this->applyCalendarStateToTask($task);
                $task->saveQuietly();
            });
    }

    private function mapCalendarToTaskStatus(string $calendarStatus): string
    {
        return match ($calendarStatus) {
            CompanyCalendarEvent::STATUS_CONFIRMED => CompanyClientTask::STATUS_IN_PROGRESS,
            CompanyCalendarEvent::STATUS_COMPLETED => CompanyClientTask::STATUS_DONE,
            CompanyCalendarEvent::STATUS_CANCELED, CompanyCalendarEvent::STATUS_NO_SHOW => CompanyClientTask::STATUS_CANCELED,
            default => CompanyClientTask::STATUS_TODO,
        };
    }

    private function mapTaskStatusToBoardColumn(string $taskStatus): string
    {
        return match ($taskStatus) {
            CompanyClientTask::STATUS_IN_PROGRESS => 'in_progress',
            CompanyClientTask::STATUS_DONE => 'done',
            CompanyClientTask::STATUS_CANCELED => 'canceled',
            default => 'todo',
        };
    }
}
