<?php

use App\Models\CompanySubscription;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\CompanySubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function billingTokenFor(User $user): string
{
    return $user->createToken('frontend')->plainTextToken;
}

test('authenticated user can view billing plans and current subscription', function () {
    $user = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    SubscriptionPlan::query()->create([
        'code' => 'starter-billing-api',
        'name' => 'Starter Billing API',
        'is_active' => true,
        'is_public' => true,
        'billing_period_days' => 30,
        'currency' => 'TJS',
        'price' => 100,
        'included_chats' => 500,
        'overage_chat_price' => 1,
        'assistant_limit' => 1,
        'integrations_per_channel_limit' => 1,
    ]);

    config()->set('billing.default_plan_code', 'starter-billing-api');

    $token = billingTokenFor($user);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/billing/plans')
        ->assertOk()
        ->assertJsonPath('plans.0.code', 'starter-billing-api');

    $response = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/billing/subscription')
        ->assertOk()
        ->json();

    expect($response['subscription']['status'])->toBe(CompanySubscription::STATUS_INACTIVE);
    expect($response['subscription']['quantity'])->toBe(0);
});

test('checkout creates invoice and marks inactive subscription as pending payment', function () {
    $user = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $plan = SubscriptionPlan::query()->create([
        'code' => 'starter-checkout-test',
        'name' => 'Starter Checkout',
        'is_active' => true,
        'is_public' => true,
        'billing_period_days' => 30,
        'currency' => 'TJS',
        'price' => 99,
        'included_chats' => 500,
        'overage_chat_price' => 1,
        'assistant_limit' => 1,
        'integrations_per_channel_limit' => 1,
    ]);

    $token = billingTokenFor($user);

    $response = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/billing/checkout', [
            'plan_code' => $plan->code,
            'quantity' => 2,
        ])
        ->assertCreated()
        ->json();

    expect($response['subscription']['status'])->toBe(CompanySubscription::STATUS_PENDING_PAYMENT);
    expect($response['subscription']['quantity'])->toBe(2);
    expect($response['invoice']['status'])->toBe(Invoice::STATUS_ISSUED);
    expect((string) $response['invoice']['total'])->toBe('198.00');
});

test('paying invoice activates subscription', function () {
    $user = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $plan = SubscriptionPlan::query()->create([
        'code' => 'starter-pay-test',
        'name' => 'Starter Pay',
        'is_active' => true,
        'is_public' => true,
        'billing_period_days' => 30,
        'currency' => 'TJS',
        'price' => 120,
        'included_chats' => 500,
        'overage_chat_price' => 1,
        'assistant_limit' => 1,
        'integrations_per_channel_limit' => 1,
    ]);

    $token = billingTokenFor($user);

    $checkoutResponse = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/billing/checkout', [
            'plan_code' => $plan->code,
            'quantity' => 1,
        ])
        ->assertCreated()
        ->json();

    $invoiceId = (int) $checkoutResponse['invoice']['id'];

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/billing/invoices/{$invoiceId}/pay")
        ->assertOk()
        ->assertJsonPath('invoice.status', Invoice::STATUS_PAID)
        ->assertJsonPath('subscription.status', CompanySubscription::STATUS_ACTIVE);
});

test('paying invoice by number activates subscription', function () {
    $user = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $plan = SubscriptionPlan::query()->create([
        'code' => 'starter-pay-by-number',
        'name' => 'Starter Pay By Number',
        'is_active' => true,
        'is_public' => true,
        'billing_period_days' => 30,
        'currency' => 'TJS',
        'price' => 90,
        'included_chats' => 500,
        'overage_chat_price' => 1,
        'assistant_limit' => 1,
        'integrations_per_channel_limit' => 1,
    ]);

    $token = billingTokenFor($user);

    $checkoutResponse = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/billing/checkout', [
            'plan_code' => $plan->code,
            'quantity' => 1,
        ])
        ->assertCreated()
        ->json();

    $invoiceNumber = (string) $checkoutResponse['invoice']['number'];

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/billing/invoices/{$invoiceNumber}/pay")
        ->assertOk()
        ->assertJsonPath('invoice.status', Invoice::STATUS_PAID)
        ->assertJsonPath('subscription.status', CompanySubscription::STATUS_ACTIVE);
});

test('paying own invoice is resolved by invoice ownership, not workspace lookup', function () {
    $user = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $plan = SubscriptionPlan::query()->create([
        'code' => 'starter-pay-ownership',
        'name' => 'Starter Ownership',
        'is_active' => true,
        'is_public' => true,
        'billing_period_days' => 30,
        'currency' => 'TJS',
        'price' => 50,
        'included_chats' => 200,
        'overage_chat_price' => 1,
        'assistant_limit' => 1,
        'integrations_per_channel_limit' => 1,
    ]);

    $token = billingTokenFor($user);

    $checkoutResponse = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/billing/checkout', [
            'plan_code' => $plan->code,
            'quantity' => 1,
        ])
        ->assertCreated()
        ->json();

    $invoiceId = (int) $checkoutResponse['invoice']['id'];

    $otherUser = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $otherCompany = Company::query()->create([
        'user_id' => $otherUser->id,
        'name' => 'Other Company',
        'slug' => 'other-company-' . $otherUser->id,
        'status' => Company::STATUS_ACTIVE,
    ]);

    $this->partialMock(CompanySubscriptionService::class, function (MockInterface $mock) use ($otherCompany): void {
        $mock->shouldReceive('provisionDefaultWorkspaceForUser')
            ->andReturn($otherCompany);
    });

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/billing/invoices/{$invoiceId}/pay")
        ->assertOk()
        ->assertJsonPath('invoice.status', Invoice::STATUS_PAID)
        ->assertJsonPath('subscription.status', CompanySubscription::STATUS_ACTIVE);
});
