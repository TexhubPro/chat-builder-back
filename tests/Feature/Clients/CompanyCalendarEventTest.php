<?php

use App\Models\Assistant;
use App\Models\AssistantService;
use App\Models\Company;
use App\Models\CompanyCalendarEvent;
use App\Models\CompanyClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('company calendar event can be scheduled for a client', function () {
    $user = User::factory()->create();

    $company = Company::query()->create([
        'user_id' => $user->id,
        'name' => 'Calendar Company',
        'slug' => 'calendar-company',
        'status' => Company::STATUS_ACTIVE,
    ]);

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Calendar Assistant',
    ]);

    $service = AssistantService::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'assistant_id' => $assistant->id,
        'name' => 'Consultation',
        'price' => 120,
        'currency' => 'TJS',
    ]);

    $client = CompanyClient::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Calendar Client',
        'phone' => '+992900000003',
    ]);

    $startsAt = now()->addDay()->startOfHour();
    $endsAt = $startsAt->copy()->addHour();

    $event = CompanyCalendarEvent::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'assistant_id' => $assistant->id,
        'assistant_service_id' => $service->id,
        'title' => 'Consultation booking',
        'description' => 'Initial appointment',
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'timezone' => 'Asia/Dushanbe',
        'status' => CompanyCalendarEvent::STATUS_SCHEDULED,
        'location' => 'Office 101',
        'meeting_link' => 'https://meet.example.com/booking-1',
        'reminders' => [
            ['minutes_before' => 60],
            ['minutes_before' => 15],
        ],
    ]);

    $this->assertDatabaseHas('company_calendar_events', [
        'id' => $event->id,
        'company_client_id' => $client->id,
        'assistant_service_id' => $service->id,
        'title' => 'Consultation booking',
        'timezone' => 'Asia/Dushanbe',
    ]);

    expect($client->calendarEvents()->count())->toBe(1);
    expect($company->calendarEvents()->count())->toBe(1);
    expect($assistant->calendarEvents()->count())->toBe(1);
});
