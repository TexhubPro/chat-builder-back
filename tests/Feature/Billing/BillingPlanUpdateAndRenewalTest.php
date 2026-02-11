<?php

use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Invoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function billingToken(User $user): string
{
    return $user->createToken('frontend')->plainTextToken;
}

test('checkout applies unused current plan credit when active subscription is upgraded', function () {
    $user = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $starter = SubscriptionPlan::query()->create([
        'code' => 'starter-proration-test',
        'name' => 'Starter Proration',
        'is_active' => true,
        'is_public' => true,
        'billing_period_days' => 30,
        'currency' => 'USD',
        'price' => 30,
        'included_chats' => 400,
        'overage_chat_price' => 1,
        'assistant_limit' => 1,
        'integrations_per_channel_limit' => 1,
    ]);

    $growth = SubscriptionPlan::query()->create([
        'code' => 'growth-proration-test',
        'name' => 'Growth Proration',
        'is_active' => true,
        'is_public' => true,
        'billing_period_days' => 30,
        'currency' => 'USD',
        'price' => 50,
        'included_chats' => 700,
        'overage_chat_price' => 1,
        'assistant_limit' => 2,
        'integrations_per_channel_limit' => 2,
    ]);

    $company = Company::query()->create([
        'user_id' => $user->id,
        'name' => 'Proration Company',
        'slug' => 'proration-company',
        'status' => 'active',
    ]);

    CompanySubscription::query()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'subscription_plan_id' => $starter->id,
        'status' => CompanySubscription::STATUS_ACTIVE,
        'quantity' => 1,
        'billing_cycle_days' => 30,
        'chat_count_current_period' => 200,
        'starts_at' => now()->subDays(15),
        'expires_at' => now()->addDays(15),
        'renewal_due_at' => now()->addDays(15),
        'chat_period_started_at' => now()->subDays(15),
        'chat_period_ends_at' => now()->addDays(15),
    ]);

    $token = billingToken($user);

    $response = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/billing/checkout', [
            'plan_code' => $growth->code,
            'quantity' => 1,
        ])
        ->assertCreated()
        ->json();

    expect($response['subscription']['status'])->toBe(CompanySubscription::STATUS_ACTIVE);
    expect((string) $response['subscription']['plan']['code'])->toBe($starter->code);
    expect((string) $response['invoice']['subtotal'])->toBe('50.00');
    expect((string) $response['invoice']['total'])->toBe('35.00');

    $invoice = Invoice::query()->findOrFail((int) $response['invoice']['id']);
    expect((string) data_get($invoice->metadata, 'purpose'))->toBe('plan_change');
    expect((string) data_get($invoice->metadata, 'credit_amount'))->toBe('15.00');
});

test('daily renewal command creates invoice with overage when subscription is near expiration', function () {
    $user = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $plan = SubscriptionPlan::query()->create([
        'code' => 'starter-renewal-test',
        'name' => 'Starter Renewal',
        'is_active' => true,
        'is_public' => true,
        'billing_period_days' => 30,
        'currency' => 'USD',
        'price' => 30,
        'included_chats' => 400,
        'overage_chat_price' => 1,
        'assistant_limit' => 1,
        'integrations_per_channel_limit' => 1,
    ]);

    $company = Company::query()->create([
        'user_id' => $user->id,
        'name' => 'Renewal Company',
        'slug' => 'renewal-company',
        'status' => 'active',
    ]);

    CompanySubscription::query()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'subscription_plan_id' => $plan->id,
        'status' => CompanySubscription::STATUS_ACTIVE,
        'quantity' => 1,
        'billing_cycle_days' => 30,
        'chat_count_current_period' => 500,
        'starts_at' => now()->subDays(28),
        'expires_at' => now()->addDays(2),
        'renewal_due_at' => now()->addDays(2),
        'chat_period_started_at' => now()->subDays(28),
        'chat_period_ends_at' => now()->addDays(2),
    ]);

    $this->artisan('billing:generate-renewal-invoices')
        ->expectsOutputToContain('Renewal invoices processed: 1')
        ->assertExitCode(0);

    $invoice = Invoice::query()->first();

    expect($invoice)->not()->toBeNull();
    expect($invoice->status)->toBe(Invoice::STATUS_ISSUED);
    expect((string) $invoice->subtotal)->toBe('30.00');
    expect((string) $invoice->overage_amount)->toBe('100.00');
    expect((string) $invoice->total)->toBe('130.00');
    expect((int) $invoice->chat_overage)->toBe(100);
    expect((string) data_get($invoice->metadata, 'purpose'))->toBe('renewal');
});

