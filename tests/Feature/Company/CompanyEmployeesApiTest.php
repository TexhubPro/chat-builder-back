<?php

use App\Mail\TemporaryPasswordMail;
use App\Models\User;
use App\Services\CompanySubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function employeesTokenFor(User $user): string
{
    return $user->createToken('frontend')->plainTextToken;
}

test('company owner can create employee and send temporary password by email', function () {
    Mail::fake();

    $owner = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);
    $company = app(CompanySubscriptionService::class)
        ->provisionDefaultWorkspaceForUser($owner->id, $owner->name);
    $token = employeesTokenFor($owner);

    $response = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/company/employees', [
            'name' => 'Test Employee',
            'email' => 'employee@example.com',
            'phone' => '+992 900 123 456',
            'page_access' => ['dashboard', 'client-chats', 'client-base'],
            'status' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('employee.email', 'employee@example.com')
        ->assertJsonPath('employee.role', User::ROLE_EMPLOYEE)
        ->assertJsonPath('employee.company_id', $company->id)
        ->assertJsonPath('employee.page_access.0', 'dashboard')
        ->assertJsonPath('employee.page_access.1', 'client-chats');

    $employeeId = (int) $response->json('employee.id');
    $employee = User::query()->find($employeeId);

    expect($employee)->not->toBeNull();
    expect($employee?->company_id)->toBe($company->id);
    expect($employee?->created_by_user_id)->toBe($owner->id);
    expect($employee?->status)->toBeTrue();
    expect($employee?->email_verified_at)->not->toBeNull();
    expect(Hash::check('Password123!', (string) $employee?->password))->toBeFalse();

    Mail::assertSent(TemporaryPasswordMail::class, function (TemporaryPasswordMail $mail): bool {
        return $mail->hasTo('employee@example.com') && strlen($mail->temporaryPassword) >= 10;
    });
});

test('company owner can update and delete employee', function () {
    $owner = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);
    $company = app(CompanySubscriptionService::class)
        ->provisionDefaultWorkspaceForUser($owner->id, $owner->name);
    $employee = User::factory()->create([
        'name' => 'Old Employee',
        'email' => 'old-employee@example.com',
        'phone' => '+992900000111',
        'role' => User::ROLE_EMPLOYEE,
        'company_id' => $company->id,
        'created_by_user_id' => $owner->id,
        'page_access' => ['dashboard', 'client-chats'],
        'email_verified_at' => now(),
        'status' => true,
    ]);

    $token = employeesTokenFor($owner);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/company/employees/{$employee->id}", [
            'name' => 'Updated Employee',
            'email' => 'updated-employee@example.com',
            'phone' => '+992900000222',
            'page_access' => ['dashboard', 'billing'],
            'status' => false,
        ])
        ->assertOk()
        ->assertJsonPath('employee.name', 'Updated Employee')
        ->assertJsonPath('employee.status', false)
        ->assertJsonPath('employee.page_access.1', 'billing');

    $employee->refresh();

    expect($employee->name)->toBe('Updated Employee');
    expect($employee->email)->toBe('updated-employee@example.com');
    expect($employee->phone)->toBe('+992900000222');
    expect($employee->status)->toBeFalse();

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/company/employees/{$employee->id}")
        ->assertOk();

    $this->assertDatabaseMissing('users', [
        'id' => $employee->id,
    ]);
});

test('employee cannot manage employees', function () {
    $owner = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);
    $company = app(CompanySubscriptionService::class)
        ->provisionDefaultWorkspaceForUser($owner->id, $owner->name);

    $employee = User::factory()->create([
        'role' => User::ROLE_EMPLOYEE,
        'company_id' => $company->id,
        'created_by_user_id' => $owner->id,
        'page_access' => ['dashboard', 'employees'],
        'email_verified_at' => now(),
        'status' => true,
    ]);

    $token = employeesTokenFor($employee);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/company/employees')
        ->assertStatus(403);
});

test('employee page access middleware blocks routes without granted permission', function () {
    $owner = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);
    $company = app(CompanySubscriptionService::class)
        ->provisionDefaultWorkspaceForUser($owner->id, $owner->name);

    $employee = User::factory()->create([
        'role' => User::ROLE_EMPLOYEE,
        'company_id' => $company->id,
        'created_by_user_id' => $owner->id,
        'page_access' => ['dashboard', 'client-chats'],
        'email_verified_at' => now(),
        'status' => true,
    ]);

    $token = employeesTokenFor($employee);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/chats')
        ->assertOk();

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/billing/subscription')
        ->assertStatus(403)
        ->assertJsonPath('message', 'You do not have access to this page.');
});
