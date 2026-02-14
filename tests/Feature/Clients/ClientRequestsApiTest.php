<?php

use App\Models\Chat;
use App\Models\Company;
use App\Models\CompanyCalendarEvent;
use App\Models\CompanyClient;
use App\Models\CompanyClientOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function clientRequestsApiContext(bool $withAppointments = true): array
{
    $user = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $company = Company::query()->create([
        'user_id' => $user->id,
        'name' => 'Requests Company',
        'slug' => 'requests-company-'.$user->id,
        'status' => Company::STATUS_ACTIVE,
        'settings' => [
            'account_type' => $withAppointments ? 'with_appointments' : 'without_appointments',
            'business' => [
                'timezone' => 'Asia/Dushanbe',
            ],
            'appointment' => [
                'enabled' => $withAppointments,
            ],
        ],
    ]);

    $token = $user->createToken('frontend')->plainTextToken;

    return [$user, $company, $token];
}

test('client requests api returns kanban-ready items with optional appointments board', function () {
    [$user, $company, $token] = clientRequestsApiContext();

    $chat = Chat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'channel' => 'telegram',
        'channel_chat_id' => 'tg-requests-1',
        'channel_user_id' => 'tg-user-1',
        'name' => 'Telegram Lead',
        'status' => Chat::STATUS_OPEN,
    ]);

    $clientA = CompanyClient::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Lead A',
        'phone' => '+992900000101',
        'email' => 'lead-a@example.test',
    ]);

    $clientB = CompanyClient::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Lead B',
        'phone' => '+992900000102',
        'email' => 'lead-b@example.test',
    ]);

    CompanyClientOrder::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $clientA->id,
        'service_name' => 'Consultation',
        'status' => CompanyClientOrder::STATUS_NEW,
        'ordered_at' => now()->subHour(),
        'metadata' => [
            'chat_id' => $chat->id,
            'phone' => $clientA->phone,
            'address' => 'Dushanbe',
            'appointment' => [
                'calendar_event_id' => 45,
                'starts_at' => now()->addDay()->toIso8601String(),
                'ends_at' => now()->addDay()->addHour()->toIso8601String(),
                'timezone' => 'Asia/Dushanbe',
                'duration_minutes' => 60,
            ],
        ],
    ]);

    CompanyClientOrder::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $clientB->id,
        'service_name' => 'Audit',
        'status' => CompanyClientOrder::STATUS_IN_PROGRESS,
        'ordered_at' => now()->subMinutes(30),
        'metadata' => [
            'phone' => $clientB->phone,
            'address' => 'Khujand',
        ],
    ]);

    $response = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/client-requests')
        ->assertOk()
        ->json();

    expect($response['appointments_enabled'])->toBeTrue();
    expect($response['requests'])->toHaveCount(2);

    $appointmentCard = collect($response['requests'])
        ->first(fn (array $item): bool => ($item['service_name'] ?? '') === 'Consultation');
    $processingCard = collect($response['requests'])
        ->first(fn (array $item): bool => ($item['service_name'] ?? '') === 'Audit');

    expect($appointmentCard)->not->toBeNull();
    expect($processingCard)->not->toBeNull();
    expect($appointmentCard['board'])->toBe('appointments');
    expect($appointmentCard['source_channel'])->toBe('telegram');
    expect($processingCard['board'])->toBe('in_progress');
});

test('client requests api updates request fields status and appointment booking', function () {
    [$user, $company, $token] = clientRequestsApiContext();

    $client = CompanyClient::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Lead C',
        'phone' => '+992900000201',
        'email' => 'lead-c@example.test',
    ]);

    $order = CompanyClientOrder::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'service_name' => 'Initial service',
        'status' => CompanyClientOrder::STATUS_NEW,
        'ordered_at' => now()->subHour(),
        'metadata' => [
            'phone' => $client->phone,
            'address' => 'Old address',
        ],
    ]);

    $response = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/client-requests/'.$order->id, [
            'status' => CompanyClientOrder::STATUS_IN_PROGRESS,
            'client_name' => 'Lead C Updated',
            'phone' => '+992900000299',
            'service_name' => 'Premium service',
            'address' => 'New address',
            'amount' => 180.75,
            'note' => 'Call before visit',
            'book_appointment' => true,
            'appointment_date' => '2026-02-20',
            'appointment_time' => '16:15',
            'appointment_duration_minutes' => 90,
        ])
        ->assertOk()
        ->json();

    expect($response['request']['status'])->toBe(CompanyClientOrder::STATUS_IN_PROGRESS);
    expect($response['request']['service_name'])->toBe('Premium service');
    expect((float) $response['request']['amount'])->toBe(180.75);
    expect($response['request']['board'])->toBe('appointments');
    expect($response['request']['appointment'])->not->toBeNull();

    $order->refresh();
    $client->refresh();

    expect($order->service_name)->toBe('Premium service');
    expect((string) $order->status)->toBe(CompanyClientOrder::STATUS_IN_PROGRESS);
    expect((float) $order->total_price)->toBe(180.75);
    expect((string) $order->notes)->toBe('Call before visit');
    expect((string) data_get($order->metadata, 'address'))->toBe('New address');
    expect($client->name)->toBe('Lead C Updated');
    expect($client->phone)->toBe('+992900000299');

    $eventId = (int) data_get($order->metadata, 'appointment.calendar_event_id', 0);
    expect($eventId)->toBeGreaterThan(0);
    $event = CompanyCalendarEvent::query()->find($eventId);
    expect($event)->not->toBeNull();
});

test('client requests api rejects appointment updates when appointments are disabled', function () {
    [$user, $company, $token] = clientRequestsApiContext(false);

    $client = CompanyClient::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Lead D',
        'phone' => '+992900000301',
    ]);

    $order = CompanyClientOrder::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'service_name' => 'Basic service',
        'status' => CompanyClientOrder::STATUS_NEW,
        'ordered_at' => now()->subHour(),
        'metadata' => [
            'phone' => $client->phone,
            'address' => 'Address',
        ],
    ]);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/client-requests/'.$order->id, [
            'book_appointment' => true,
            'appointment_date' => '2026-02-22',
            'appointment_time' => '10:00',
            'appointment_duration_minutes' => 60,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Appointments are disabled for this company.');
});

test('client requests api deletes request and linked appointment event', function () {
    [$user, $company, $token] = clientRequestsApiContext();

    $client = CompanyClient::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Lead E',
        'phone' => '+992900000401',
    ]);

    $event = CompanyCalendarEvent::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'title' => 'Appointment',
        'starts_at' => now()->addHour(),
        'ends_at' => now()->addHours(2),
        'timezone' => 'Asia/Dushanbe',
        'status' => CompanyCalendarEvent::STATUS_SCHEDULED,
    ]);

    $order = CompanyClientOrder::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'service_name' => 'Delete me',
        'status' => CompanyClientOrder::STATUS_NEW,
        'metadata' => [
            'appointment' => [
                'calendar_event_id' => $event->id,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'ends_at' => $event->ends_at?->toIso8601String(),
                'timezone' => $event->timezone,
                'duration_minutes' => 60,
            ],
        ],
    ]);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson('/api/client-requests/'.$order->id)
        ->assertOk()
        ->assertJsonPath('message', 'Client request deleted successfully.');

    $this->assertDatabaseMissing('company_client_orders', [
        'id' => $order->id,
    ]);
    $this->assertDatabaseMissing('company_calendar_events', [
        'id' => $event->id,
    ]);
});

test('client requests api archives completed request and hides it from list', function () {
    [$user, $company, $token] = clientRequestsApiContext();

    $client = CompanyClient::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Lead Archive',
        'phone' => '+992900000501',
    ]);

    $order = CompanyClientOrder::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'service_name' => 'Archive service',
        'status' => CompanyClientOrder::STATUS_COMPLETED,
        'metadata' => [
            'phone' => $client->phone,
            'address' => 'Dushanbe',
        ],
    ]);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/client-requests/'.$order->id, [
            'archived' => true,
        ])
        ->assertOk();

    $order->refresh();
    expect((bool) data_get($order->metadata, 'archived', false))->toBeTrue();

    $response = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/client-requests')
        ->assertOk()
        ->json();

    expect(collect($response['requests'])->contains(fn (array $item): bool => (int) $item['id'] === $order->id))
        ->toBeFalse();
});
