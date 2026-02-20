<?php

use App\Mail\EmailVerificationCodeMail;
use App\Mail\TemporaryPasswordMail;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('registration sends verification code and keeps user unverified', function () {
    Mail::fake();

    $response = $this->postJson('/api/auth/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'phone' => '+1 (555) 000-1111',
        'password' => 'StrongP@ssw0rd!',
        'avatar' => 'https://example.com/avatar.png',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('requires_email_verification', true)
        ->assertJsonPath('email', 'test@example.com')
        ->assertJsonMissingPath('token');

    $user = User::query()->where('email', 'test@example.com')->first();

    expect($user)->not->toBeNull();
    expect($user?->email_verified_at)->toBeNull();

    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'phone' => '+15550001111',
        'role' => 'customer',
        'status' => 1,
    ]);

    $this->assertDatabaseHas('email_verification_codes', [
        'user_id' => $user?->id,
    ]);

    Mail::assertSent(EmailVerificationCodeMail::class, function (EmailVerificationCodeMail $mail) {
        return $mail->hasTo('test@example.com') && preg_match('/^\d{6}$/', $mail->code) === 1;
    });
});

test('user can verify email code and receive token', function () {
    Mail::fake();

    $this->postJson('/api/auth/register', [
        'name' => 'Test User',
        'email' => 'verify@example.com',
        'phone' => '+1 (555) 000-2222',
        'password' => 'StrongP@ssw0rd!',
    ])->assertCreated();

    $mail = Mail::sent(EmailVerificationCodeMail::class)->first();

    $response = $this->postJson('/api/auth/verify-email-code', [
        'email' => 'verify@example.com',
        'code' => $mail->code,
        'device_name' => 'web-app',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.email', 'verify@example.com')
        ->assertJsonStructure([
            'message',
            'token_type',
            'token',
            'token_expires_at',
            'user' => [
                'id',
                'name',
                'email',
                'phone',
                'avatar',
                'role',
                'status',
            ],
        ]);

    $user = User::query()->where('email', 'verify@example.com')->first();

    expect($user?->email_verified_at)->not->toBeNull();

    $this->assertDatabaseMissing('email_verification_codes', [
        'user_id' => $user?->id,
    ]);
});

test('login is blocked until email is verified', function () {
    Mail::fake();

    $this->postJson('/api/auth/register', [
        'name' => 'Pending User',
        'email' => 'pending@example.com',
        'phone' => '+1 (555) 000-3333',
        'password' => 'StrongP@ssw0rd!',
    ])->assertCreated();

    $response = $this->postJson('/api/auth/login', [
        'login' => 'pending@example.com',
        'password' => 'StrongP@ssw0rd!',
    ]);

    $response
        ->assertStatus(403)
        ->assertJsonPath('message', 'Email is not verified.')
        ->assertJsonPath('requires_email_verification', true);
});

test('moderation mode keeps new user inactive and returns moderation state after email verification', function () {
    config()->set('moderation.enabled', true);
    Mail::fake();

    $this->postJson('/api/auth/register', [
        'name' => 'Moderated User',
        'email' => 'moderated@example.com',
        'phone' => '+1 (555) 000-3344',
        'password' => 'StrongP@ssw0rd!',
    ])->assertCreated();

    $this->assertDatabaseHas('users', [
        'email' => 'moderated@example.com',
        'status' => 0,
    ]);

    $mail = Mail::sent(EmailVerificationCodeMail::class)->first();

    $verifyResponse = $this->postJson('/api/auth/verify-email-code', [
        'email' => 'moderated@example.com',
        'code' => $mail->code,
        'device_name' => 'web-app',
    ]);

    $verifyResponse
        ->assertOk()
        ->assertJsonPath('requires_moderation', true)
        ->assertJsonPath('user.email', 'moderated@example.com')
        ->assertJsonPath('user.status', false)
        ->assertJsonStructure([
            'message',
            'requires_moderation',
            'token',
            'token_type',
            'token_expires_at',
            'user',
        ]);
});

test('moderation mode returns moderation payload for verified inactive user login', function () {
    config()->set('moderation.enabled', true);

    $user = User::factory()->create([
        'email' => 'moderation-login@example.com',
        'password' => Hash::make('StrongP@ssw0rd!'),
        'status' => false,
        'email_verified_at' => now(),
    ]);

    $loginResponse = $this->postJson('/api/auth/login', [
        'login' => 'moderation-login@example.com',
        'password' => 'StrongP@ssw0rd!',
    ]);

    $loginResponse
        ->assertStatus(403)
        ->assertJsonPath('requires_moderation', true)
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.status', false)
        ->assertJsonStructure([
            'message',
            'requires_moderation',
            'token',
            'token_type',
            'token_expires_at',
            'user',
        ]);
});

test('moderation status endpoint supports refresh and logout for pending user', function () {
    config()->set('moderation.enabled', true);

    $user = User::factory()->create([
        'email' => 'moderation-status@example.com',
        'status' => false,
        'email_verified_at' => now(),
    ]);

    $issuedToken = $user->createToken('frontend');

    $this
        ->withHeader('Authorization', "Bearer {$issuedToken->plainTextToken}")
        ->getJson('/api/auth/moderation-status')
        ->assertOk()
        ->assertJsonPath('requires_moderation', true)
        ->assertJsonPath('user.id', $user->id);

    $user->forceFill(['status' => true])->save();

    $this
        ->withHeader('Authorization', "Bearer {$issuedToken->plainTextToken}")
        ->getJson('/api/auth/moderation-status')
        ->assertOk()
        ->assertJsonPath('requires_moderation', false)
        ->assertJsonPath('user.status', true);

    $this
        ->withHeader('Authorization', "Bearer {$issuedToken->plainTextToken}")
        ->postJson('/api/auth/logout')
        ->assertOk();

    $this->assertDatabaseMissing('personal_access_tokens', [
        'id' => $issuedToken->accessToken->id,
    ]);
});

test('pending moderation user can submit moderation application', function () {
    config()->set('moderation.enabled', true);

    $user = User::factory()->create([
        'email' => 'moderation-application@example.com',
        'status' => false,
        'email_verified_at' => now(),
    ]);

    $issuedToken = $user->createToken('frontend');

    $response = $this
        ->withHeader('Authorization', "Bearer {$issuedToken->plainTextToken}")
        ->postJson('/api/auth/moderation-application', [
            'company_name' => 'Mahmudi Shod Company',
            'industry' => 'Beauty',
            'short_description' => 'Сеть салонов и сервисов красоты.',
            'primary_goal' => 'Автоматизировать обработку заявок и чатов.',
            'liddo_use_case' => 'lead_generation',
            'contact_email' => 'info@mahmudi.tj',
            'contact_phone' => '+992 900 000 111',
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('message', 'Moderation application submitted successfully.')
        ->assertJsonPath('company.name', 'Mahmudi Shod Company')
        ->assertJsonPath('company.industry', 'Beauty')
        ->assertJsonPath('application.liddo_use_case', 'lead_generation');

    $company = Company::query()
        ->where('user_id', $user->id)
        ->latest('id')
        ->first();

    expect($company)->not->toBeNull();
    expect($company?->name)->toBe('Mahmudi Shod Company');
    expect($company?->contact_email)->toBe('info@mahmudi.tj');

    $settings = is_array($company?->settings) ? $company->settings : [];
    $moderationMeta = is_array($settings['moderation_application'] ?? null)
        ? $settings['moderation_application']
        : [];

    expect($moderationMeta['liddo_use_case'] ?? null)->toBe('lead_generation');
    expect($moderationMeta['submitted_at'] ?? null)->not->toBeNull();
});

test('verification code resend respects cooldown', function () {
    Mail::fake();

    $this->postJson('/api/auth/register', [
        'name' => 'Cooldown User',
        'email' => 'cooldown@example.com',
        'phone' => '+1 (555) 000-4444',
        'password' => 'StrongP@ssw0rd!',
    ])->assertCreated();

    $this->postJson('/api/auth/resend-verification-code', [
        'email' => 'cooldown@example.com',
    ])->assertStatus(429);

    $this->travel(config('verification.email_code.resend_cooldown_seconds', 60) + 1)->seconds();

    $this->postJson('/api/auth/resend-verification-code', [
        'email' => 'cooldown@example.com',
    ])->assertOk();

    Mail::assertSent(EmailVerificationCodeMail::class, 2);
});

test('forgot password resets password and sends temporary password', function () {
    Mail::fake();

    $user = User::factory()->create([
        'email' => 'forgot-password@example.com',
        'password' => Hash::make('OldStrongP@ss1'),
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $issuedToken = $user->createToken('frontend');

    $response = $this->postJson('/api/auth/forgot-password', [
        'email' => 'forgot-password@example.com',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('message', 'If the account exists, a temporary password has been sent.');

    Mail::assertSent(TemporaryPasswordMail::class, function (TemporaryPasswordMail $mail) use ($user) {
        return $mail->hasTo($user->email) && strlen($mail->temporaryPassword) >= 10;
    });

    $mail = Mail::sent(TemporaryPasswordMail::class)->first();

    $user->refresh();

    expect(Hash::check('OldStrongP@ss1', $user->password))->toBeFalse();
    expect($mail)->not->toBeNull();
    expect(Hash::check($mail->temporaryPassword, $user->password))->toBeTrue();

    $this->assertDatabaseMissing('personal_access_tokens', [
        'id' => $issuedToken->accessToken->id,
    ]);
});

test('forgot password returns generic response for unknown email', function () {
    Mail::fake();

    $this->postJson('/api/auth/forgot-password', [
        'email' => 'missing@example.com',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'If the account exists, a temporary password has been sent.');

    Mail::assertNothingSent();
});

test('forgot password attempts are limited to four per hour', function () {
    Mail::fake();

    $user = User::factory()->create([
        'email' => 'forgot-limit@example.com',
        'password' => Hash::make('OldStrongP@ss1'),
        'status' => true,
        'email_verified_at' => now(),
    ]);

    for ($attempt = 1; $attempt <= 4; $attempt++) {
        $this->postJson('/api/auth/forgot-password', [
            'email' => $user->email,
        ])->assertOk();
    }

    $this->postJson('/api/auth/forgot-password', [
        'email' => $user->email,
    ])->assertStatus(429);
});

test('registration is limited to four attempts per hour', function () {
    Mail::fake();

    for ($index = 1; $index <= 4; $index++) {
        $response = $this->postJson('/api/auth/register', [
            'name' => "User {$index}",
            'email' => "register-limit-{$index}@example.com",
            'phone' => '+1555111'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
            'password' => 'StrongP@ssw0rd!',
        ]);

        $response->assertCreated();
    }

    $this->postJson('/api/auth/register', [
        'name' => 'User 5',
        'email' => 'register-limit-5@example.com',
        'phone' => '+15551110005',
        'password' => 'StrongP@ssw0rd!',
    ])->assertStatus(429);
});

test('verification code attempts are limited to four per hour', function () {
    Mail::fake();

    $this->postJson('/api/auth/register', [
        'name' => 'Limit Verify User',
        'email' => 'verify-limit@example.com',
        'phone' => '+1 (555) 000-5555',
        'password' => 'StrongP@ssw0rd!',
    ])->assertCreated();

    for ($attempt = 1; $attempt <= 4; $attempt++) {
        $this->postJson('/api/auth/verify-email-code', [
            'email' => 'verify-limit@example.com',
            'code' => '000000',
        ])->assertStatus(422);
    }

    $this->postJson('/api/auth/verify-email-code', [
        'email' => 'verify-limit@example.com',
        'code' => '000000',
    ])->assertStatus(429);
});

test('verification code resend is limited to four requests per hour', function () {
    Mail::fake();

    $this->postJson('/api/auth/register', [
        'name' => 'Resend Limit User',
        'email' => 'resend-limit@example.com',
        'phone' => '+1 (555) 000-6666',
        'password' => 'StrongP@ssw0rd!',
    ])->assertCreated();

    $cooldown = config('verification.email_code.resend_cooldown_seconds', 60) + 1;

    for ($attempt = 1; $attempt <= 4; $attempt++) {
        $this->travel($cooldown)->seconds();

        $this->postJson('/api/auth/resend-verification-code', [
            'email' => 'resend-limit@example.com',
        ])->assertOk();
    }

    $this->travel($cooldown)->seconds();

    $this->postJson('/api/auth/resend-verification-code', [
        'email' => 'resend-limit@example.com',
    ])->assertStatus(429);
});

test('inactive user cannot login', function () {
    User::factory()->create([
        'email' => 'inactive@example.com',
        'password' => Hash::make('StrongP@ssw0rd!'),
        'status' => false,
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => 'inactive@example.com',
        'password' => 'StrongP@ssw0rd!',
    ]);

    $response
        ->assertStatus(403)
        ->assertJsonPath('message', 'User is inactive.');
});

test('login attempts are limited to four per hour', function () {
    User::factory()->create([
        'email' => 'login-limit@example.com',
        'password' => Hash::make('StrongP@ssw0rd!'),
        'status' => true,
        'email_verified_at' => now(),
    ]);

    for ($attempt = 1; $attempt <= 4; $attempt++) {
        $this->postJson('/api/auth/login', [
            'login' => 'login-limit@example.com',
            'password' => 'WrongP@ssw0rd!',
        ])->assertStatus(422);
    }

    $this->postJson('/api/auth/login', [
        'login' => 'login-limit@example.com',
        'password' => 'WrongP@ssw0rd!',
    ])->assertStatus(429);
});

test('active user can login and read profile', function () {
    $user = User::factory()->create([
        'email' => 'active@example.com',
        'password' => Hash::make('StrongP@ssw0rd!'),
        'status' => true,
    ]);

    $loginResponse = $this->postJson('/api/auth/login', [
        'login' => 'active@example.com',
        'password' => 'StrongP@ssw0rd!',
    ]);

    $token = $loginResponse->json('token');

    $loginResponse
        ->assertOk()
        ->assertJsonPath('user.id', $user->id);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.email', 'active@example.com');
});

test('active user can login by phone', function () {
    $user = User::factory()->create([
        'phone' => '+15550002222',
        'password' => Hash::make('StrongP@ssw0rd!'),
        'status' => true,
    ]);

    $loginResponse = $this->postJson('/api/auth/login', [
        'phone' => '+1 (555) 000-2222',
        'password' => 'StrongP@ssw0rd!',
    ]);

    $token = $loginResponse->json('token');

    $loginResponse
        ->assertOk()
        ->assertJsonPath('user.id', $user->id);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.phone', '+15550002222');
});

test('active user can update profile name and avatar', function () {
    Storage::fake('public');

    $user = User::factory()->create([
        'name' => 'Old Name',
        'email' => 'profile-update@example.com',
        'phone' => '+15550007777',
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $issuedToken = $user->createToken('frontend');

    $response = $this
        ->withHeaders([
            'Authorization' => "Bearer {$issuedToken->plainTextToken}",
            'Accept' => 'application/json',
        ])
        ->post('/api/auth/profile', [
            'name' => 'New Name',
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 120, 120),
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('message', 'Profile updated successfully.')
        ->assertJsonPath('user.name', 'New Name')
        ->assertJsonPath('user.email', 'profile-update@example.com')
        ->assertJsonPath('user.phone', '+15550007777');

    $avatarUrl = $response->json('user.avatar');
    expect($avatarUrl)->toBeString();
    expect($avatarUrl)->toContain('/storage/avatars/');

    $avatarPath = parse_url((string) $avatarUrl, PHP_URL_PATH);
    expect($avatarPath)->toBeString();

    $relativePath = trim(str_replace('/storage/', '', (string) $avatarPath), '/');
    Storage::disk('public')->assertExists($relativePath);
});

test('profile update rejects email and phone changes', function () {
    $user = User::factory()->create([
        'email' => 'profile-immutable@example.com',
        'phone' => '+15550008888',
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $issuedToken = $user->createToken('frontend');

    $this
        ->withHeader('Authorization', "Bearer {$issuedToken->plainTextToken}")
        ->postJson('/api/auth/profile', [
            'name' => 'Immutable User',
            'email' => 'new-email@example.com',
            'phone' => '+15550009999',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'phone']);
});

test('active user can change password from profile', function () {
    $user = User::factory()->create([
        'email' => 'profile-password@example.com',
        'password' => Hash::make('OldStrongP@ss1'),
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $issuedToken = $user->createToken('frontend');

    $this
        ->withHeader('Authorization', "Bearer {$issuedToken->plainTextToken}")
        ->postJson('/api/auth/profile', [
            'name' => $user->name,
            'current_password' => 'OldStrongP@ss1',
            'new_password' => 'NewStrongP@ss2',
            'new_password_confirmation' => 'NewStrongP@ss2',
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Profile updated successfully.');

    $user->refresh();

    expect(Hash::check('NewStrongP@ss2', $user->password))->toBeTrue();
    expect(Hash::check('OldStrongP@ss1', $user->password))->toBeFalse();
});

test('profile password change fails with invalid current password', function () {
    $user = User::factory()->create([
        'email' => 'profile-password-fail@example.com',
        'password' => Hash::make('OldStrongP@ss1'),
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $issuedToken = $user->createToken('frontend');

    $this
        ->withHeader('Authorization', "Bearer {$issuedToken->plainTextToken}")
        ->postJson('/api/auth/profile', [
            'name' => $user->name,
            'current_password' => 'WrongPassword!12',
            'new_password' => 'NewStrongP@ss2',
            'new_password_confirmation' => 'NewStrongP@ss2',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['current_password']);
});

test('inactive token is revoked on protected route access', function () {
    $user = User::factory()->create([
        'status' => false,
    ]);

    $issuedToken = $user->createToken('frontend');

    $this
        ->withHeader('Authorization', "Bearer {$issuedToken->plainTextToken}")
        ->getJson('/api/auth/me')
        ->assertStatus(403)
        ->assertJsonPath('message', 'User is inactive.');

    $this->assertDatabaseMissing('personal_access_tokens', [
        'id' => $issuedToken->accessToken->id,
    ]);
});

test('unverified email token is revoked on protected route access', function () {
    $user = User::factory()->create([
        'email_verified_at' => null,
    ]);

    $issuedToken = $user->createToken('frontend');

    $this
        ->withHeader('Authorization', "Bearer {$issuedToken->plainTextToken}")
        ->getJson('/api/auth/me')
        ->assertStatus(403)
        ->assertJsonPath('message', 'Email is not verified.');

    $this->assertDatabaseMissing('personal_access_tokens', [
        'id' => $issuedToken->accessToken->id,
    ]);
});

test('unsupported social provider is rejected', function () {
    $this
        ->getJson('/api/auth/oauth/telegram/redirect')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['provider']);
});

test('expired token is revoked on protected route access', function () {
    $user = User::factory()->create([
        'status' => true,
    ]);

    $issuedToken = $user->createToken('frontend', ['*'], now()->subMinute());

    $this
        ->withHeader('Authorization', "Bearer {$issuedToken->plainTextToken}")
        ->getJson('/api/auth/me')
        ->assertStatus(401)
        ->assertJsonPath('message', 'Token has expired.');

    $this->assertDatabaseMissing('personal_access_tokens', [
        'id' => $issuedToken->accessToken->id,
    ]);
});
