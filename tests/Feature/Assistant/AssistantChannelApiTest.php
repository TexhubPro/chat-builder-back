<?php

use App\Models\Assistant;
use App\Models\AssistantChannel;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use TexHub\Meta\Models\InstagramIntegration;

uses(RefreshDatabase::class);

function assistantChannelApiContext(
    bool $activeSubscription = true,
    int $integrationLimit = 2
): array {
    $user = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $company = Company::query()->create([
        'user_id' => $user->id,
        'name' => 'Integrations Company',
        'slug' => 'integrations-company-'.$user->id,
        'status' => Company::STATUS_ACTIVE,
    ]);

    $plan = SubscriptionPlan::query()->create([
        'code' => 'integrations-plan-'.$user->id,
        'name' => 'Integrations Plan',
        'is_active' => true,
        'is_public' => true,
        'billing_period_days' => 30,
        'currency' => 'USD',
        'price' => 25,
        'included_chats' => 300,
        'overage_chat_price' => 1,
        'assistant_limit' => 3,
        'integrations_per_channel_limit' => $integrationLimit,
    ]);

    CompanySubscription::query()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'subscription_plan_id' => $plan->id,
        'status' => $activeSubscription
            ? CompanySubscription::STATUS_ACTIVE
            : CompanySubscription::STATUS_INACTIVE,
        'quantity' => 1,
        'billing_cycle_days' => 30,
        'starts_at' => now()->subDay(),
        'expires_at' => $activeSubscription ? now()->addDays(29) : now()->subDay(),
        'renewal_due_at' => now()->addDays(29),
        'chat_count_current_period' => 0,
        'chat_period_started_at' => now()->subDay(),
        'chat_period_ends_at' => now()->addDays(29),
    ]);

    $token = $user->createToken('frontend')->plainTextToken;

    return [$user, $company, $token];
}

test('assistant channels api returns assistants and channel matrix for selected assistant', function () {
    [$user, $company, $token] = assistantChannelApiContext();

    $assistantA = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Assistant A',
        'is_active' => true,
    ]);

    Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Assistant B',
        'is_active' => false,
    ]);

    AssistantChannel::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'assistant_id' => $assistantA->id,
        'channel' => 'instagram',
        'name' => 'My Instagram',
        'external_account_id' => 'ig_001',
        'is_active' => true,
    ]);

    AssistantChannel::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'assistant_id' => $assistantA->id,
        'channel' => 'telegram',
        'name' => 'My Telegram',
        'external_account_id' => 'tg_001',
        'is_active' => false,
    ]);

    $response = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/assistant-channels?assistant_id='.$assistantA->id)
        ->assertOk()
        ->json();

    expect($response['assistants'])->toHaveCount(2);
    expect($response['selected_assistant_id'])->toBe($assistantA->id);
    expect($response['channels'])->toHaveCount(4);
    expect(collect($response['channels'])->firstWhere('channel', 'instagram')['is_connected'])->toBeTrue();
    expect(collect($response['channels'])->firstWhere('channel', 'instagram')['is_active'])->toBeTrue();
    expect(collect($response['channels'])->firstWhere('channel', 'telegram')['is_connected'])->toBeTrue();
    expect(collect($response['channels'])->firstWhere('channel', 'telegram')['is_active'])->toBeFalse();
    expect(collect($response['channels'])->firstWhere('channel', 'widget')['is_connected'])->toBeFalse();
    expect($response['limits']['integrations_per_channel_limit'])->toBe(2);
});

test('assistant channels api can toggle channel on and off', function () {
    [$user, $company, $token] = assistantChannelApiContext();

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Toggle Assistant',
        'is_active' => true,
    ]);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/assistant-channels/'.$assistant->id.'/telegram', [
            'enabled' => true,
            'name' => 'Support Bot',
            'external_account_id' => 'tg_support_bot',
        ])
        ->assertOk()
        ->assertJsonPath('channel.channel', 'telegram')
        ->assertJsonPath('channel.is_connected', true)
        ->assertJsonPath('channel.is_active', true);

    $this->assertDatabaseHas('assistant_channels', [
        'assistant_id' => $assistant->id,
        'channel' => 'telegram',
        'is_active' => true,
    ]);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/assistant-channels/'.$assistant->id.'/telegram', [
            'enabled' => false,
        ])
        ->assertOk()
        ->assertJsonPath('channel.channel', 'telegram')
        ->assertJsonPath('channel.is_connected', true)
        ->assertJsonPath('channel.is_active', false);

    $this->assertDatabaseHas('assistant_channels', [
        'assistant_id' => $assistant->id,
        'channel' => 'telegram',
        'is_active' => false,
    ]);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson('/api/assistant-channels/'.$assistant->id.'/telegram')
        ->assertOk()
        ->assertJsonPath('channel.channel', 'telegram')
        ->assertJsonPath('channel.is_connected', false)
        ->assertJsonPath('channel.is_active', false);

    $this->assertDatabaseMissing('assistant_channels', [
        'assistant_id' => $assistant->id,
        'channel' => 'telegram',
    ]);
});

test('assistant channels api rejects enabling integration when subscription is inactive', function () {
    [$user, $company, $token] = assistantChannelApiContext(activeSubscription: false);

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Inactive Assistant',
        'is_active' => true,
    ]);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/assistant-channels/'.$assistant->id.'/instagram', [
            'enabled' => true,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Cannot connect integration while subscription is inactive.');
});

test('assistant channels api syncs instagram integration status and removes integration on disconnect', function () {
    [$user, $company, $token] = assistantChannelApiContext();

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Instagram Assistant',
        'is_active' => true,
    ]);

    AssistantChannel::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'assistant_id' => $assistant->id,
        'channel' => 'instagram',
        'name' => 'IG Main',
        'external_account_id' => 'ig_001',
        'is_active' => true,
    ]);

    InstagramIntegration::query()->create([
        'user_id' => $user->id,
        'instagram_user_id' => 'ig_001',
        'username' => 'ig-main',
        'receiver_id' => 'ig_001',
        'access_token' => 'token',
        'is_active' => true,
    ]);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/assistant-channels/'.$assistant->id.'/instagram', [
            'enabled' => false,
        ])
        ->assertOk()
        ->assertJsonPath('channel.is_active', false);

    $this->assertDatabaseHas('instagram_integrations', [
        'user_id' => $user->id,
        'instagram_user_id' => 'ig_001',
        'is_active' => false,
    ]);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson('/api/assistant-channels/'.$assistant->id.'/instagram')
        ->assertOk()
        ->assertJsonPath('channel.is_connected', false);

    $this->assertDatabaseMissing('instagram_integrations', [
        'user_id' => $user->id,
        'instagram_user_id' => 'ig_001',
    ]);
});
