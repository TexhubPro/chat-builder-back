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
    expect($response['company']['settings']['business']['timezone'])->not->toBe('');
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
                'working_hours' => 'Mon-Sat 09:00-19:00',
            ],
            'appointment' => [
                'slot_minutes' => 45,
                'buffer_minutes' => 10,
                'max_days_ahead' => 60,
                'auto_confirm' => false,
                'require_phone' => true,
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
    expect($response['company']['settings']['appointment']['require_phone'])->toBeTrue();
    expect($response['company']['settings']['business']['timezone'])->toBe('Asia/Dushanbe');
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

