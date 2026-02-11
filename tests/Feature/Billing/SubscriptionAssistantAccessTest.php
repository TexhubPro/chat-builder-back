<?php

use App\Models\Assistant;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('inactive or unpaid subscription disables all assistants for company', function () {
    $user = User::factory()->create([
        'email' => 'billing-lock@example.com',
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $company = Company::query()->create([
        'user_id' => $user->id,
        'name' => 'Billing Lock Company',
        'slug' => 'billing-lock-company',
        'status' => Company::STATUS_ACTIVE,
    ]);

    $plan = SubscriptionPlan::query()->create([
        'code' => 'starter-monthly-test',
        'name' => 'Starter Monthly',
        'billing_period_days' => 30,
        'currency' => 'TJS',
        'price' => 99,
        'included_chats' => 500,
        'overage_chat_price' => 1,
        'assistant_limit' => 1,
        'integrations_per_channel_limit' => 1,
    ]);

    CompanySubscription::query()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'subscription_plan_id' => $plan->id,
        'status' => CompanySubscription::STATUS_UNPAID,
        'quantity' => 1,
        'billing_cycle_days' => 30,
    ]);

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Billing Assistant',
        'is_active' => true,
    ]);

    $token = $user->createToken('frontend')->plainTextToken;

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/auth/me')
        ->assertOk();

    expect($assistant->fresh()->is_active)->toBeFalse();
});

test('active subscription enforces assistant quantity limit', function () {
    $user = User::factory()->create([
        'email' => 'billing-limit@example.com',
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $company = Company::query()->create([
        'user_id' => $user->id,
        'name' => 'Billing Limit Company',
        'slug' => 'billing-limit-company',
        'status' => Company::STATUS_ACTIVE,
    ]);

    $plan = SubscriptionPlan::query()->create([
        'code' => 'starter-monthly-limit-test',
        'name' => 'Starter Monthly',
        'billing_period_days' => 30,
        'currency' => 'TJS',
        'price' => 99,
        'included_chats' => 500,
        'overage_chat_price' => 1,
        'assistant_limit' => 1,
        'integrations_per_channel_limit' => 1,
    ]);

    CompanySubscription::query()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'subscription_plan_id' => $plan->id,
        'status' => CompanySubscription::STATUS_ACTIVE,
        'quantity' => 1,
        'billing_cycle_days' => 30,
        'starts_at' => now()->subDay(),
        'expires_at' => now()->addDays(29),
    ]);

    Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Assistant 1',
        'is_active' => true,
    ]);

    Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Assistant 2',
        'is_active' => true,
    ]);

    $token = $user->createToken('frontend')->plainTextToken;

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/auth/me')
        ->assertOk();

    expect(
        Assistant::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->count()
    )->toBe(1);
});
