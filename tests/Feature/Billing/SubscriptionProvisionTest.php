<?php

use App\Models\CompanySubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('registration provisions company and default subscription workspace', function () {
    Mail::fake();

    $plan = SubscriptionPlan::query()->create([
        'code' => SubscriptionPlan::CODE_STARTER_MONTHLY,
        'name' => 'Starter Monthly',
        'billing_period_days' => 30,
        'currency' => 'TJS',
        'price' => 99,
        'included_chats' => 500,
        'overage_chat_price' => 1,
        'assistant_limit' => 1,
        'integrations_per_channel_limit' => 1,
    ]);

    $response = $this->postJson('/api/auth/register', [
        'name' => 'Workspace User',
        'email' => 'workspace@example.com',
        'phone' => '+1 (555) 321-1111',
        'password' => 'StrongP@ssw0rd!',
    ]);

    $response->assertCreated();

    $user = User::query()->where('email', 'workspace@example.com')->first();

    expect($user)->not->toBeNull();
    expect($user?->company)->not->toBeNull();

    $subscription = $user?->company?->subscription;

    expect($subscription)->not->toBeNull();
    expect($subscription?->subscription_plan_id)->toBe($plan->id);
    expect($subscription?->status)->toBe(CompanySubscription::STATUS_INACTIVE);
    expect($subscription?->quantity)->toBe(0);
});
