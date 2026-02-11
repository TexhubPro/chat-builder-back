<?php

use App\Models\CompanySubscription;
use App\Models\Invoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function billingAuthToken(User $user): string
{
    return $user->createToken('frontend')->plainTextToken;
}

function alifCallbackToken(string $key, string $password, string $orderId, string $status, string $transactionId): string
{
    $hashPassword = hash_hmac('sha256', $key, $password);

    return hash_hmac('sha256', $orderId . $status . $transactionId, $hashPassword);
}

function configureAlifForTests(): void
{
    config()->set('billing.alif.mode', 'test');
    config()->set('billing.alif.callback_url', 'http://localhost:8000/api/billing/alif/callback');
    config()->set('billing.alif.return_url', 'http://localhost:5173/billing');
    config()->set('billing.alif.test_checkout_url', 'https://test-web.alif.tj/');
    config()->set('alifbank.key', 'alif_test_key');
    config()->set('alifbank.password', 'alif_test_password');
    config()->set('alifbank.gate', 'km');
}

function createActiveUserWithPlan(): array
{
    $user = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $plan = SubscriptionPlan::query()->create([
        'code' => 'starter-alif-test',
        'name' => 'Starter Alif',
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

    return [$user, $plan];
}

function createInvoiceForAlifFlow(User $user, SubscriptionPlan $plan): array
{
    $token = billingAuthToken($user);

    $checkout = test()
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/billing/checkout', [
            'plan_code' => $plan->code,
            'quantity' => 1,
        ])
        ->assertCreated()
        ->json();

    $invoiceId = (int) $checkout['invoice']['id'];

    $pay = test()
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/billing/invoices/{$invoiceId}/pay")
        ->assertOk()
        ->json();

    return [$token, $invoiceId, $pay];
}

test('pay endpoint creates alif payment session in test mode', function () {
    configureAlifForTests();
    [$user, $plan] = createActiveUserWithPlan();

    [$token, $invoiceId, $pay] = createInvoiceForAlifFlow($user, $plan);

    expect($token)->toBeString();
    expect($pay['payment']['provider'])->toBe('alifbank');
    expect($pay['payment']['mode'])->toBe('test');
    expect($pay['payment']['method'])->toBe('post');
    expect($pay['payment']['checkout_url'])->toBe('https://test-web.alif.tj');
    expect($pay['payment']['payload']['order_id'])->toContain('INV-' . $invoiceId . '-ALIF-');
    expect($pay['invoice']['status'])->toBe(Invoice::STATUS_PENDING);
});

test('alif callback success marks invoice paid and activates subscription', function () {
    configureAlifForTests();
    [$user, $plan] = createActiveUserWithPlan();
    [$token, $invoiceId, $pay] = createInvoiceForAlifFlow($user, $plan);

    $orderId = (string) $pay['payment']['payload']['order_id'];
    $status = 'success';
    $transactionId = 'ALIF-TX-1001';
    $tokenHash = alifCallbackToken('alif_test_key', 'alif_test_password', $orderId, $status, $transactionId);

    $response = $this
        ->postJson('/api/billing/alif/callback', [
            'order_id' => $orderId,
            'status' => $status,
            'transaction_id' => $transactionId,
            'token' => $tokenHash,
        ])
        ->assertOk()
        ->json();

    expect($response['ok'])->toBeTrue();
    expect($response['status'])->toBe('success');
    expect($response['invoice']['status'])->toBe(Invoice::STATUS_PAID);

    $invoice = Invoice::query()->findOrFail($invoiceId);
    expect($invoice->status)->toBe(Invoice::STATUS_PAID);

    $subscription = CompanySubscription::query()->where('user_id', $user->id)->firstOrFail();
    expect($subscription->status)->toBe(CompanySubscription::STATUS_ACTIVE);
});

test('alif callback failed marks invoice as failed', function () {
    configureAlifForTests();
    [$user, $plan] = createActiveUserWithPlan();
    [, $invoiceId, $pay] = createInvoiceForAlifFlow($user, $plan);

    $orderId = (string) $pay['payment']['payload']['order_id'];
    $status = 'failed';
    $transactionId = 'ALIF-TX-2001';
    $tokenHash = alifCallbackToken('alif_test_key', 'alif_test_password', $orderId, $status, $transactionId);

    $response = $this
        ->postJson('/api/billing/alif/callback', [
            'order_id' => $orderId,
            'status' => $status,
            'transaction_id' => $transactionId,
            'token' => $tokenHash,
        ])
        ->assertOk()
        ->json();

    expect($response['ok'])->toBeTrue();
    expect($response['status'])->toBe('failed');

    $invoice = Invoice::query()->findOrFail($invoiceId);
    expect($invoice->status)->toBe(Invoice::STATUS_FAILED);
});

test('alif callback pending keeps invoice in pending status', function () {
    configureAlifForTests();
    [$user, $plan] = createActiveUserWithPlan();
    [, $invoiceId, $pay] = createInvoiceForAlifFlow($user, $plan);

    $orderId = (string) $pay['payment']['payload']['order_id'];
    $status = 'pending';
    $transactionId = 'ALIF-TX-3001';
    $tokenHash = alifCallbackToken('alif_test_key', 'alif_test_password', $orderId, $status, $transactionId);

    $response = $this
        ->postJson('/api/billing/alif/callback', [
            'order_id' => $orderId,
            'status' => $status,
            'transaction_id' => $transactionId,
            'token' => $tokenHash,
        ])
        ->assertOk()
        ->json();

    expect($response['ok'])->toBeTrue();
    expect($response['status'])->toBe('pending');

    $invoice = Invoice::query()->findOrFail($invoiceId);
    expect($invoice->status)->toBe(Invoice::STATUS_PENDING);
});

