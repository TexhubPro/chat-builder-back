<?php

use App\Models\Company;
use App\Models\CompanyCalendarEvent;
use App\Models\CompanyClient;
use App\Models\CompanyClientOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function calendarTokenFor(User $user): string
{
    return $user->createToken('frontend')->plainTextToken;
}

function calendarCompanySettings(): array
{
    return [
        'account_type' => 'with_appointments',
        'business' => [
            'timezone' => 'Asia/Dushanbe',
            'schedule' => [
                'monday' => ['is_day_off' => false, 'start_time' => '09:00', 'end_time' => '18:00'],
                'tuesday' => ['is_day_off' => false, 'start_time' => '09:00', 'end_time' => '18:00'],
                'wednesday' => ['is_day_off' => false, 'start_time' => '09:00', 'end_time' => '18:00'],
                'thursday' => ['is_day_off' => false, 'start_time' => '09:00', 'end_time' => '18:00'],
                'friday' => ['is_day_off' => false, 'start_time' => '09:00', 'end_time' => '18:00'],
                'saturday' => ['is_day_off' => true, 'start_time' => null, 'end_time' => null],
                'sunday' => ['is_day_off' => true, 'start_time' => null, 'end_time' => null],
            ],
        ],
        'appointment' => [
            'enabled' => true,
            'slot_minutes' => 30,
            'buffer_minutes' => 0,
            'max_days_ahead' => 30,
            'auto_confirm' => true,
        ],
        'delivery' => [
            'enabled' => false,
        ],
    ];
}

function calendarContext(): array
{
    $user = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $company = Company::query()->create([
        'user_id' => $user->id,
        'name' => 'Calendar Test Company',
        'slug' => 'calendar-test-company-'.$user->id,
        'status' => Company::STATUS_ACTIVE,
        'settings' => calendarCompanySettings(),
    ]);

    $token = calendarTokenFor($user);

    return [$user, $company, $token];
}

test('calendar api creates manual booking and blocks overlapping slot', function () {
    [, $company, $token] = calendarContext();

    $response = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/calendar/events', [
            'title' => 'Consultation',
            'date' => '2026-02-20',
            'time' => '10:00',
            'duration_minutes' => 60,
            'timezone' => 'Asia/Dushanbe',
            'client_name' => 'Ali Client',
            'client_phone' => '+992 900 100 200',
        ])
        ->assertCreated()
        ->assertJsonPath('event.title', 'Consultation')
        ->assertJsonPath('event.client.name', 'Ali Client')
        ->json();

    $eventId = (int) data_get($response, 'event.id');

    $this->assertDatabaseHas('company_calendar_events', [
        'id' => $eventId,
        'company_id' => $company->id,
        'status' => CompanyCalendarEvent::STATUS_SCHEDULED,
    ]);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/calendar/events', [
            'title' => 'Second Consultation',
            'date' => '2026-02-20',
            'time' => '10:30',
            'duration_minutes' => 30,
            'timezone' => 'Asia/Dushanbe',
            'client_name' => 'Second Client',
            'client_phone' => '+992 900 300 400',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Selected date and time is already occupied.');
});

test('calendar api deletes event and detaches linked order appointment', function () {
    [$user, $company, $token] = calendarContext();

    $client = CompanyClient::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Event Client',
        'phone' => '+992900111222',
        'status' => CompanyClient::STATUS_ACTIVE,
    ]);

    $event = CompanyCalendarEvent::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'title' => 'Booked Slot',
        'starts_at' => now()->addDay()->utc(),
        'ends_at' => now()->addDay()->addMinutes(30)->utc(),
        'timezone' => 'Asia/Dushanbe',
        'status' => CompanyCalendarEvent::STATUS_SCHEDULED,
        'metadata' => [
            'source' => 'client_requests_board',
        ],
    ]);

    $order = CompanyClientOrder::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'service_name' => 'Consultation',
        'unit_price' => 0,
        'total_price' => 0,
        'currency' => 'TJS',
        'status' => CompanyClientOrder::STATUS_APPOINTMENTS,
        'metadata' => [
            'appointment' => [
                'calendar_event_id' => $event->id,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'ends_at' => $event->ends_at?->toIso8601String(),
                'timezone' => $event->timezone,
            ],
        ],
    ]);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson('/api/calendar/events/'.$event->id)
        ->assertOk();

    $this->assertDatabaseMissing('company_calendar_events', [
        'id' => $event->id,
    ]);

    $order->refresh();
    expect(data_get($order->metadata, 'appointment'))->toBeNull();
    expect($order->status)->toBe(CompanyClientOrder::STATUS_IN_PROGRESS);
});
