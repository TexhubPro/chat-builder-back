<?php

use App\Models\Company;
use App\Models\CompanyCalendarEvent;
use App\Models\CompanyClient;
use App\Models\CompanyClientOrder;
use App\Models\CompanyClientQuestion;
use App\Models\CompanyClientTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function clientBaseApiContext(): array
{
    $user = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $company = Company::query()->create([
        'user_id' => $user->id,
        'name' => 'Client Base Company',
        'slug' => 'client-base-company-'.$user->id,
        'status' => Company::STATUS_ACTIVE,
    ]);

    $token = $user->createToken('frontend')->plainTextToken;

    return [$user, $company, $token];
}

test('client base api lists company clients with stats and activity', function () {
    [$user, $company, $token] = clientBaseApiContext();

    $client = CompanyClient::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Anisa Karim',
        'phone' => '+992900123456',
        'email' => 'anisa@example.com',
        'status' => CompanyClient::STATUS_ACTIVE,
        'metadata' => [
            'avatar' => 'https://example.com/avatar.jpg',
        ],
    ]);

    CompanyClientOrder::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'service_name' => 'Consultation',
        'quantity' => 1,
        'unit_price' => 120,
        'total_price' => 120,
        'currency' => 'TJS',
        'ordered_at' => now()->subDays(2),
        'status' => CompanyClientOrder::STATUS_NEW,
        'metadata' => [
            'address' => 'Dushanbe',
        ],
    ]);

    CompanyClientTask::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'description' => 'Call client back',
        'status' => CompanyClientTask::STATUS_TODO,
    ]);

    CompanyClientQuestion::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'description' => 'What time are you open?',
        'status' => CompanyClientQuestion::STATUS_OPEN,
    ]);

    CompanyCalendarEvent::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'title' => 'Appointment with Anisa',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addMinutes(30),
        'timezone' => 'Asia/Dushanbe',
        'status' => CompanyCalendarEvent::STATUS_SCHEDULED,
    ]);

    $response = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/client-base')
        ->assertOk()
        ->json();

    expect($response['clients'])->toHaveCount(1);
    expect($response['clients'][0]['id'])->toBe($client->id);
    expect($response['clients'][0]['name'])->toBe('Anisa Karim');
    expect($response['clients'][0]['stats']['orders_count'])->toBe(1);
    expect($response['clients'][0]['stats']['appointments_count'])->toBe(1);
    expect($response['clients'][0]['stats']['tasks_count'])->toBe(1);
    expect($response['clients'][0]['stats']['questions_count'])->toBe(1);
    expect((float) $response['clients'][0]['stats']['total_spent'])->toBe(120.0);
    expect($response['clients'][0]['stats']['currency'])->toBe('TJS');
    expect($response['clients'][0]['activity']['last_contact_at'])->not->toBeNull();
    expect($response['counts']['all'])->toBe(1);
    expect($response['counts']['active'])->toBe(1);
});

test('client base api returns detailed client history', function () {
    [$user, $company, $token] = clientBaseApiContext();

    $client = CompanyClient::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Shod Mehr',
        'phone' => '+992901111111',
        'email' => 'shod@example.com',
        'status' => CompanyClient::STATUS_ACTIVE,
    ]);

    $order = CompanyClientOrder::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'service_name' => 'Haircut',
        'quantity' => 1,
        'unit_price' => 90,
        'total_price' => 90,
        'currency' => 'TJS',
        'ordered_at' => now()->subHours(5),
        'status' => CompanyClientOrder::STATUS_IN_PROGRESS,
        'notes' => 'Client prefers evening',
        'metadata' => [
            'address' => 'Rudaki street',
            'phone' => '+992901111111',
        ],
    ]);

    $appointment = CompanyCalendarEvent::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'title' => 'Haircut appointment',
        'starts_at' => now()->addHours(3),
        'ends_at' => now()->addHours(4),
        'timezone' => 'Asia/Dushanbe',
        'status' => CompanyCalendarEvent::STATUS_CONFIRMED,
    ]);

    $task = CompanyClientTask::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'description' => 'Confirm appointment by phone',
        'status' => CompanyClientTask::STATUS_IN_PROGRESS,
        'priority' => CompanyClientTask::PRIORITY_HIGH,
    ]);

    $question = CompanyClientQuestion::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'description' => 'Can I bring a friend?',
        'status' => CompanyClientQuestion::STATUS_OPEN,
    ]);

    $response = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/client-base/'.$client->id)
        ->assertOk()
        ->json();

    expect($response['client']['id'])->toBe($client->id);
    expect($response['history']['orders'])->toHaveCount(1);
    expect($response['history']['appointments'])->toHaveCount(1);
    expect($response['history']['tasks'])->toHaveCount(1);
    expect($response['history']['questions'])->toHaveCount(1);
    expect($response['history']['orders'][0]['id'])->toBe($order->id);
    expect($response['history']['appointments'][0]['id'])->toBe($appointment->id);
    expect($response['history']['tasks'][0]['id'])->toBe($task->id);
    expect($response['history']['questions'][0]['id'])->toBe($question->id);
    expect($response['history']['timeline'])->not->toBeEmpty();
});

test('client base api marks client as archived when only completed activity exists', function () {
    [$user, $company, $token] = clientBaseApiContext();

    $client = CompanyClient::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Archived Client',
        'phone' => '+992909998877',
        'email' => 'archived@example.com',
        'status' => CompanyClient::STATUS_ACTIVE,
    ]);

    CompanyClientOrder::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'service_name' => 'Completed order',
        'quantity' => 1,
        'unit_price' => 50,
        'total_price' => 50,
        'currency' => 'TJS',
        'ordered_at' => now()->subDay(),
        'status' => CompanyClientOrder::STATUS_COMPLETED,
    ]);

    $archivedResponse = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/client-base?status=archived')
        ->assertOk()
        ->json();

    expect($archivedResponse['clients'])->toHaveCount(1);
    expect($archivedResponse['clients'][0]['id'])->toBe($client->id);
    expect($archivedResponse['clients'][0]['status'])->toBe(CompanyClient::STATUS_ARCHIVED);
    expect($archivedResponse['counts']['archived'])->toBe(1);
    expect($archivedResponse['counts']['active'])->toBe(0);

    $detailsResponse = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/client-base/'.$client->id)
        ->assertOk()
        ->json();

    expect($detailsResponse['client']['status'])->toBe(CompanyClient::STATUS_ARCHIVED);
});
