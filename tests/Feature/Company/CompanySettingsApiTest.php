<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function companySettingsTokenFor(User $user): string
{
    return $user->createToken('frontend')->plainTextToken;
}

test('company settings api returns default company profile and settings', function () {
    $user = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $token = companySettingsTokenFor($user);

    $response = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/company/settings')
        ->assertOk()
        ->json();

    expect($response['company'])->not->toBeNull();
    expect($response['company']['name'])->toContain($user->name);
    expect($response['company']['settings']['account_type'])->toBe('without_appointments');
    expect($response['company']['settings']['appointment']['enabled'])->toBeFalse();
    expect($response['company']['settings']['appointment']['slot_minutes'])->toBe(30);
    expect($response['company']['settings']['delivery']['enabled'])->toBeFalse();
    expect($response['company']['settings']['delivery']['require_delivery_address'])->toBeTrue();
    expect($response['company']['settings']['delivery']['require_delivery_datetime'])->toBeTrue();
    expect($response['company']['settings']['delivery']['default_eta_minutes'])->toBe(120);
    expect((float) $response['company']['settings']['delivery']['fee'])->toBe(0.0);
    expect($response['company']['settings']['delivery']['free_from_amount'])->toBeNull();
    expect($response['company']['settings']['delivery']['available_from'])->toBe('09:00');
    expect($response['company']['settings']['delivery']['available_to'])->toBe('21:00');
    expect($response['company']['settings']['business']['timezone'])->not->toBe('');
    expect($response['company']['settings']['business']['schedule']['monday']['is_day_off'])->toBeFalse();
    expect($response['company']['settings']['business']['schedule']['monday']['start_time'])->toBe('09:00');
    expect($response['company']['settings']['business']['schedule']['monday']['end_time'])->toBe('18:00');
    expect($response['company']['settings']['business']['schedule']['sunday']['is_day_off'])->toBeTrue();
    expect($response['company']['settings']['business']['schedule']['sunday']['start_time'])->toBeNull();
    expect($response['company']['settings']['business']['schedule']['sunday']['end_time'])->toBeNull();
    expect($response['company']['settings']['crm']['order_required_fields'])->toBe([
        'phone',
        'service_name',
        'address',
    ]);
    expect($response['company']['settings']['crm']['appointment_required_fields'])->toBe([
        'phone',
        'service_name',
        'address',
        'appointment_date',
        'appointment_time',
        'appointment_duration_minutes',
    ]);
    expect($response['company']['settings']['ai']['response_languages'])->toBe(['ru']);
});

test('company settings api updates company data and appointment settings', function () {
    $user = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $token = companySettingsTokenFor($user);

    $payload = [
        'name' => 'Safe Trade LLC',
        'short_description' => 'Сеть салонов и сервисов.',
        'industry' => 'Beauty',
        'primary_goal' => 'Автоматизировать заявки и записи.',
        'contact_email' => 'hello@safetrade.tj',
        'contact_phone' => '+992 900 000 111',
        'website' => 'https://safetrade.tj',
        'settings' => [
            'account_type' => 'with_appointments',
            'business' => [
                'address' => 'Dushanbe, Rudaki 12',
                'timezone' => 'Asia/Dushanbe',
                'schedule' => [
                    'monday' => ['is_day_off' => false, 'start_time' => '08:30', 'end_time' => '18:30'],
                    'tuesday' => ['is_day_off' => false, 'start_time' => '09:00', 'end_time' => '18:00'],
                    'wednesday' => ['is_day_off' => false, 'start_time' => '09:00', 'end_time' => '18:00'],
                    'thursday' => ['is_day_off' => false, 'start_time' => '09:00', 'end_time' => '18:00'],
                    'friday' => ['is_day_off' => false, 'start_time' => '09:00', 'end_time' => '18:00'],
                    'saturday' => ['is_day_off' => false, 'start_time' => '10:00', 'end_time' => '16:00'],
                    'sunday' => ['is_day_off' => true],
                ],
            ],
            'appointment' => [
                'slot_minutes' => 45,
                'buffer_minutes' => 10,
                'max_days_ahead' => 60,
                'auto_confirm' => false,
            ],
            'delivery' => [
                'enabled' => true,
                'require_delivery_address' => true,
                'require_delivery_datetime' => false,
                'default_eta_minutes' => 90,
                'fee' => 12.5,
                'free_from_amount' => 300,
                'available_from' => '10:00',
                'available_to' => '22:00',
                'notes' => 'Доставка только по городу.',
            ],
            'crm' => [
                'order_required_fields' => ['phone', 'service_name'],
                'appointment_required_fields' => [
                    'phone',
                    'appointment_date',
                    'appointment_time',
                ],
            ],
            'ai' => [
                'response_languages' => ['ru', 'en', 'tg'],
            ],
        ],
    ];

    $response = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/company/settings', $payload)
        ->assertOk()
        ->assertJsonPath('message', 'Company settings updated successfully.')
        ->json();

    expect($response['company']['name'])->toBe('Safe Trade LLC');
    expect($response['company']['settings']['account_type'])->toBe('with_appointments');
    expect($response['company']['settings']['appointment']['enabled'])->toBeTrue();
    expect($response['company']['settings']['appointment']['slot_minutes'])->toBe(45);
    expect($response['company']['settings']['appointment']['buffer_minutes'])->toBe(10);
    expect($response['company']['settings']['appointment']['max_days_ahead'])->toBe(60);
    expect($response['company']['settings']['appointment']['auto_confirm'])->toBeFalse();
    expect($response['company']['settings']['delivery']['enabled'])->toBeTrue();
    expect($response['company']['settings']['delivery']['require_delivery_address'])->toBeTrue();
    expect($response['company']['settings']['delivery']['require_delivery_datetime'])->toBeFalse();
    expect($response['company']['settings']['delivery']['default_eta_minutes'])->toBe(90);
    expect((float) $response['company']['settings']['delivery']['fee'])->toBe(12.5);
    expect((float) $response['company']['settings']['delivery']['free_from_amount'])->toBe(300.0);
    expect($response['company']['settings']['delivery']['available_from'])->toBe('10:00');
    expect($response['company']['settings']['delivery']['available_to'])->toBe('22:00');
    expect($response['company']['settings']['delivery']['notes'])->toBe('Доставка только по городу.');
    expect($response['company']['settings']['business']['timezone'])->toBe('Asia/Dushanbe');
    expect($response['company']['settings']['business']['schedule']['monday']['is_day_off'])->toBeFalse();
    expect($response['company']['settings']['business']['schedule']['monday']['start_time'])->toBe('08:30');
    expect($response['company']['settings']['business']['schedule']['monday']['end_time'])->toBe('18:30');
    expect($response['company']['settings']['business']['schedule']['saturday']['is_day_off'])->toBeFalse();
    expect($response['company']['settings']['business']['schedule']['saturday']['start_time'])->toBe('10:00');
    expect($response['company']['settings']['business']['schedule']['saturday']['end_time'])->toBe('16:00');
    expect($response['company']['settings']['business']['schedule']['sunday']['is_day_off'])->toBeTrue();
    expect($response['company']['settings']['business']['schedule']['sunday']['start_time'])->toBeNull();
    expect($response['company']['settings']['business']['schedule']['sunday']['end_time'])->toBeNull();
    expect($response['company']['settings']['crm']['order_required_fields'])->toBe([
        'phone',
        'service_name',
    ]);
    expect($response['company']['settings']['crm']['appointment_required_fields'])->toBe([
        'phone',
        'appointment_date',
        'appointment_time',
    ]);
    expect($response['company']['settings']['ai']['response_languages'])->toBe([
        'ru',
        'en',
        'tg',
    ]);
});

test('company settings api validates account type', function () {
    $user = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $token = companySettingsTokenFor($user);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/company/settings', [
            'name' => 'Safe Trade LLC',
            'settings' => [
                'account_type' => 'invalid-type',
            ],
        ])
        ->assertStatus(422);
});
