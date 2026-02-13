<?php

use App\Models\Assistant;
use App\Models\AssistantChannel;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function telegramIntegrationContext(): array
{
    $user = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $company = Company::query()->create([
        'user_id' => $user->id,
        'name' => 'Telegram Integrations Company',
        'slug' => 'telegram-integrations-company-'.$user->id,
        'status' => Company::STATUS_ACTIVE,
    ]);

    $plan = SubscriptionPlan::query()->create([
        'code' => 'telegram-integrations-plan-'.$user->id,
        'name' => 'Telegram Integrations Plan',
        'is_active' => true,
        'is_public' => true,
        'billing_period_days' => 30,
        'currency' => 'USD',
        'price' => 20,
        'included_chats' => 100,
        'overage_chat_price' => 1,
        'assistant_limit' => 2,
        'integrations_per_channel_limit' => 2,
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
        'renewal_due_at' => now()->addDays(29),
        'chat_count_current_period' => 0,
        'chat_period_started_at' => now()->subDay(),
        'chat_period_ends_at' => now()->addDays(29),
    ]);

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Telegram Assistant',
        'is_active' => true,
    ]);

    $token = $user->createToken('frontend')->plainTextToken;

    return [$user, $company, $assistant, $token];
}

test('telegram connect endpoint validates token sets webhook and stores assistant channel', function () {
    [, , $assistant, $token] = telegramIntegrationContext();

    config()->set('services.telegram.bot_api_base', 'https://api.telegram.org');

    Http::fake([
        'https://api.telegram.org/bot123456:ABC/getMe' => Http::response([
            'ok' => true,
            'result' => [
                'id' => 987654321,
                'is_bot' => true,
                'first_name' => 'SupportBot',
                'username' => 'support_bot',
            ],
        ], 200),
        'https://api.telegram.org/bot123456:ABC/setWebhook' => Http::response([
            'ok' => true,
            'result' => true,
            'description' => 'Webhook was set',
        ], 200),
    ]);

    $response = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/assistant-channels/'.$assistant->id.'/telegram/connect', [
            'bot_token' => '123456:ABC',
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('channel.channel', 'telegram')
        ->assertJsonPath('channel.is_connected', true)
        ->assertJsonPath('channel.is_active', true)
        ->assertJsonPath('telegram.bot_id', '987654321')
        ->assertJsonPath('telegram.bot_username', 'support_bot');

    $this->assertDatabaseHas('assistant_channels', [
        'assistant_id' => $assistant->id,
        'channel' => 'telegram',
        'external_account_id' => '987654321',
        'is_active' => true,
    ]);

    $assistantChannel = AssistantChannel::query()
        ->where('assistant_id', $assistant->id)
        ->where('channel', 'telegram')
        ->firstOrFail();

    $credentials = is_array($assistantChannel->credentials) ? $assistantChannel->credentials : [];

    expect((string) ($credentials['bot_token'] ?? ''))->toBe('123456:ABC');
    expect((string) ($credentials['bot_id'] ?? ''))->toBe('987654321');
    expect((string) ($credentials['bot_username'] ?? ''))->toBe('support_bot');
    expect(trim((string) ($credentials['webhook_secret'] ?? '')))->not->toBe('');
    expect((string) ($credentials['webhook_url'] ?? ''))
        ->toContain('/api/integrations/telegram/webhook/'.$assistantChannel->id);

    Http::assertSentCount(2);
});

test('telegram connect endpoint returns validation error for invalid token', function () {
    [, , $assistant, $token] = telegramIntegrationContext();

    config()->set('services.telegram.bot_api_base', 'https://api.telegram.org');

    Http::fake([
        'https://api.telegram.org/botbad-token/getMe' => Http::response([
            'ok' => false,
            'error_code' => 401,
            'description' => 'Unauthorized',
        ], 401),
    ]);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/assistant-channels/'.$assistant->id.'/telegram/connect', [
            'bot_token' => 'bad-token',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Unauthorized');

    $this->assertDatabaseMissing('assistant_channels', [
        'assistant_id' => $assistant->id,
        'channel' => 'telegram',
    ]);
});

