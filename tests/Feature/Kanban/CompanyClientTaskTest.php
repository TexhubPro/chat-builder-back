<?php

use App\Models\Assistant;
use App\Models\Company;
use App\Models\CompanyCalendarEvent;
use App\Models\CompanyClient;
use App\Models\CompanyClientTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('task card syncs with linked calendar event and updates on event status change', function () {
    $user = User::factory()->create();

    $company = Company::query()->create([
        'user_id' => $user->id,
        'name' => 'Kanban Tasks Company',
        'slug' => 'kanban-tasks-company',
        'status' => Company::STATUS_ACTIVE,
    ]);

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Task Assistant',
    ]);

    $client = CompanyClient::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Client Task',
        'phone' => '+992900000222',
    ]);

    $event = CompanyCalendarEvent::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'assistant_id' => $assistant->id,
        'title' => 'Task Meeting',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
        'status' => CompanyCalendarEvent::STATUS_SCHEDULED,
    ]);

    $task = CompanyClientTask::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'assistant_id' => $assistant->id,
        'company_calendar_event_id' => $event->id,
        'description' => 'Prepare proposal for client',
    ])->fresh();

    expect($task->status)->toBe(CompanyClientTask::STATUS_TODO);
    expect($task->board_column)->toBe('todo');
    expect($task->scheduled_at?->equalTo($event->starts_at))->toBeTrue();

    $event->forceFill([
        'status' => CompanyCalendarEvent::STATUS_COMPLETED,
    ])->save();

    $task->refresh();

    expect($task->status)->toBe(CompanyClientTask::STATUS_DONE);
    expect($task->board_column)->toBe('done');
    expect($task->completed_at)->not->toBeNull();
});
