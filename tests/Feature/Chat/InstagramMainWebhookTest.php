<?php

use App\Models\Assistant;
use App\Models\AssistantChannel;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use TexHub\Meta\Facades\Instagram as InstagramFacade;
use TexHub\Meta\Models\InstagramIntegration;
use TexHub\OpenAi\Assistant as OpenAiAssistantClient;

uses(RefreshDatabase::class);

function instagramWebhookContext(string $subscriptionStatus = CompanySubscription::STATUS_ACTIVE): array
{
    config()->set('meta.instagram.resolve_customer_profile', false);

    $user = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $company = Company::query()->create([
        'user_id' => $user->id,
        'name' => 'Instagram Company',
        'slug' => 'instagram-company-'.$user->id,
        'status' => Company::STATUS_ACTIVE,
    ]);

    $plan = SubscriptionPlan::query()->create([
        'code' => 'instagram-plan-'.$user->id,
        'name' => 'Instagram Plan',
        'is_active' => true,
        'is_public' => true,
        'billing_period_days' => 30,
        'currency' => 'USD',
        'price' => 30,
        'included_chats' => 10,
        'overage_chat_price' => 1,
        'assistant_limit' => 2,
        'integrations_per_channel_limit' => 2,
    ]);

    CompanySubscription::query()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'subscription_plan_id' => $plan->id,
        'status' => $subscriptionStatus,
        'quantity' => $subscriptionStatus === CompanySubscription::STATUS_ACTIVE ? 1 : 0,
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
        'name' => 'Instagram Assistant',
        'openai_assistant_id' => 'asst_instagram_1',
        'is_active' => true,
        'conversation_tone' => Assistant::TONE_POLITE,
        'enable_file_search' => true,
        'enable_file_analysis' => false,
        'enable_voice' => true,
        'enable_web_search' => false,
    ]);

    $integration = InstagramIntegration::query()->create([
        'user_id' => $user->id,
        'instagram_user_id' => 'ig-business-account-1',
        'username' => 'my_company',
        'receiver_id' => '178900000001',
        'access_token' => 'instagram-access-token',
        'token_expires_at' => now()->addDays(30),
        'is_active' => true,
    ]);

    AssistantChannel::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'assistant_id' => $assistant->id,
        'channel' => AssistantChannel::CHANNEL_INSTAGRAM,
        'name' => 'Instagram Channel',
        'external_account_id' => (string) $integration->receiver_id,
        'is_active' => true,
    ]);

    return [$user, $company, $assistant, $integration];
}

function instagramMainWebhookPayload(array $message): array
{
    return [
        'object' => 'instagram',
        'entry' => [
            [
                'id' => 'entry_1',
                'time' => now()->valueOf(),
                'messaging' => [
                    [
                        'sender' => ['id' => 'customer-1001'],
                        'recipient' => ['id' => '178900000001'],
                        'timestamp' => now()->valueOf(),
                        'message' => $message,
                    ],
                ],
            ],
        ],
    ];
}

function instagramMainWebhookChangesPayload(array $message): array
{
    return [
        'object' => 'instagram',
        'entry' => [
            [
                'id' => '0',
                'time' => now()->valueOf(),
                'changes' => [
                    [
                        'field' => 'messages',
                        'value' => [
                            'sender' => ['id' => 'customer-2001'],
                            'recipient' => ['id' => '178900000001'],
                            'timestamp' => '1527459824',
                            'message' => $message,
                        ],
                    ],
                ],
            ],
        ],
    ];
}

test('instagram main webhook stores chat and sends assistant text reply when conditions are met', function () {
    [, $company] = instagramWebhookContext();

    config()->set('openai.assistant.api_key', 'test-openai-key');
    config()->set('meta.instagram.auto_reply_enabled', true);

    $this->mock(OpenAiAssistantClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('createThread')->once()->andReturn('thread_instagram_1');
        $mock->shouldReceive('sendTextMessage')->once()->andReturn('message_instagram_1');
        $mock->shouldReceive('runThreadAndGetResponse')->once()->andReturn('Здравствуйте! Чем могу помочь?');
    });

    InstagramFacade::shouldReceive('sendTextMessage')
        ->once()
        ->andReturn('out_mid_1');

    $response = $this->postJson('/instagram-main-webhook', instagramMainWebhookPayload([
        'mid' => 'in_mid_1',
        'text' => 'Привет',
    ]));

    $response->assertOk()->assertJsonPath('ok', true);

    $this->assertDatabaseHas('chats', [
        'company_id' => $company->id,
        'channel' => 'instagram',
        'channel_chat_id' => '178900000001:customer-1001',
    ]);

    $chat = Chat::query()->where('company_id', $company->id)->where('channel', 'instagram')->firstOrFail();

    $this->assertDatabaseHas('chat_messages', [
        'chat_id' => $chat->id,
        'sender_type' => ChatMessage::SENDER_CUSTOMER,
        'direction' => ChatMessage::DIRECTION_INBOUND,
        'message_type' => ChatMessage::TYPE_TEXT,
        'channel_message_id' => 'in_mid_1:text',
    ]);

    $this->assertDatabaseHas('chat_messages', [
        'chat_id' => $chat->id,
        'sender_type' => ChatMessage::SENDER_ASSISTANT,
        'direction' => ChatMessage::DIRECTION_OUTBOUND,
        'message_type' => ChatMessage::TYPE_TEXT,
        'channel_message_id' => 'out_mid_1',
    ]);

    $subscription = $company->subscription()->firstOrFail()->refresh();
    expect((int) $subscription->chat_count_current_period)->toBe(1);
});

test('instagram main webhook handles entry changes messages payload and sends assistant reply', function () {
    [, $company] = instagramWebhookContext();

    config()->set('openai.assistant.api_key', 'test-openai-key');
    config()->set('meta.instagram.auto_reply_enabled', true);

    $this->mock(OpenAiAssistantClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('createThread')->once()->andReturn('thread_instagram_changes_1');
        $mock->shouldReceive('sendTextMessage')->once()->andReturn('message_instagram_changes_1');
        $mock->shouldReceive('runThreadAndGetResponse')->once()->andReturn('Салом! Чи хизмат?');
    });

    InstagramFacade::shouldReceive('sendTextMessage')
        ->once()
        ->andReturn('out_mid_changes_1');

    $response = $this->postJson('/instagram-main-webhook', instagramMainWebhookChangesPayload([
        'mid' => 'random_mid',
        'text' => 'салом',
    ]));

    $response->assertOk()->assertJsonPath('ok', true);

    $this->assertDatabaseHas('chats', [
        'company_id' => $company->id,
        'channel' => 'instagram',
        'channel_chat_id' => '178900000001:customer-2001',
    ]);

    $chat = Chat::query()->where('company_id', $company->id)->where('channel', 'instagram')->firstOrFail();

    $this->assertDatabaseHas('chat_messages', [
        'chat_id' => $chat->id,
        'sender_type' => ChatMessage::SENDER_CUSTOMER,
        'direction' => ChatMessage::DIRECTION_INBOUND,
        'message_type' => ChatMessage::TYPE_TEXT,
        'channel_message_id' => 'random_mid:text',
        'text' => 'салом',
    ]);

    $this->assertDatabaseHas('chat_messages', [
        'chat_id' => $chat->id,
        'sender_type' => ChatMessage::SENDER_ASSISTANT,
        'direction' => ChatMessage::DIRECTION_OUTBOUND,
        'message_type' => ChatMessage::TYPE_TEXT,
        'channel_message_id' => 'out_mid_changes_1',
        'text' => 'Салом! Чи хизмат?',
    ]);
});

test('instagram main webhook also handles direct change payload without entry wrapper', function () {
    [, $company] = instagramWebhookContext();

    config()->set('openai.assistant.api_key', 'test-openai-key');
    config()->set('meta.instagram.auto_reply_enabled', true);

    $this->mock(OpenAiAssistantClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('createThread')->once()->andReturn('thread_instagram_direct_1');
        $mock->shouldReceive('sendTextMessage')->once()->andReturn('message_instagram_direct_1');
        $mock->shouldReceive('runThreadAndGetResponse')->once()->andReturn('Салом! Ман ёрдам медихам.');
    });

    InstagramFacade::shouldReceive('sendTextMessage')
        ->once()
        ->andReturn('out_mid_direct_1');

    $response = $this->postJson('/instagram-main-webhook', [
        'field' => 'messages',
        'value' => [
            'sender' => ['id' => 'customer-3001'],
            'recipient' => ['id' => '178900000001'],
            'timestamp' => '1527459824',
            'message' => [
                'mid' => 'random_mid_direct',
                'text' => 'салом',
            ],
        ],
    ]);

    $response->assertOk()->assertJsonPath('ok', true);

    $this->assertDatabaseHas('chats', [
        'company_id' => $company->id,
        'channel' => 'instagram',
        'channel_chat_id' => '178900000001:customer-3001',
    ]);

    $chat = Chat::query()->where('company_id', $company->id)->where('channel', 'instagram')->firstOrFail();

    $this->assertDatabaseHas('chat_messages', [
        'chat_id' => $chat->id,
        'sender_type' => ChatMessage::SENDER_CUSTOMER,
        'direction' => ChatMessage::DIRECTION_INBOUND,
        'message_type' => ChatMessage::TYPE_TEXT,
        'channel_message_id' => 'random_mid_direct:text',
        'text' => 'салом',
    ]);

    $this->assertDatabaseHas('chat_messages', [
        'chat_id' => $chat->id,
        'sender_type' => ChatMessage::SENDER_ASSISTANT,
        'direction' => ChatMessage::DIRECTION_OUTBOUND,
        'message_type' => ChatMessage::TYPE_TEXT,
        'channel_message_id' => 'out_mid_direct_1',
    ]);
});

test('instagram main webhook stores inbound message without auto reply when subscription is inactive', function () {
    [, $company] = instagramWebhookContext(CompanySubscription::STATUS_INACTIVE);

    config()->set('openai.assistant.api_key', 'test-openai-key');
    config()->set('meta.instagram.auto_reply_enabled', true);

    InstagramFacade::shouldReceive('sendTextMessage')->never();
    InstagramFacade::shouldReceive('sendMediaMessage')->never();

    $response = $this->postJson('/instagram-main-webhook', instagramMainWebhookPayload([
        'mid' => 'in_mid_2',
        'text' => 'Здравствуйте',
    ]));

    $response->assertOk()->assertJsonPath('ok', true);

    $chat = Chat::query()->where('company_id', $company->id)->where('channel', 'instagram')->firstOrFail();

    expect($chat->messages()->count())->toBe(1);

    $this->assertDatabaseHas('chat_messages', [
        'chat_id' => $chat->id,
        'sender_type' => ChatMessage::SENDER_CUSTOMER,
        'direction' => ChatMessage::DIRECTION_INBOUND,
        'channel_message_id' => 'in_mid_2:text',
    ]);

    $this->assertDatabaseMissing('chat_messages', [
        'chat_id' => $chat->id,
        'sender_type' => ChatMessage::SENDER_ASSISTANT,
    ]);
});

test('instagram main webhook responds with voice when inbound message is audio', function () {
    [, $company] = instagramWebhookContext();

    config()->set('openai.assistant.api_key', 'test-openai-key');
    config()->set('meta.instagram.auto_reply_enabled', true);
    config()->set('meta.instagram.voice_reply_for_audio', true);

    $this->mock(OpenAiAssistantClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('createThread')->once()->andReturn('thread_instagram_voice_1');
        $mock->shouldReceive('sendTextMessage')->once()->andReturn('message_instagram_voice_1');
        $mock->shouldReceive('runThreadAndGetResponse')->once()->andReturn('Спасибо за голосовое сообщение.');
        $mock->shouldReceive('createSpeech')
            ->once()
            ->andReturnUsing(fn (string $text, array $options, ?string $outputPath): ?string => $outputPath);
    });

    InstagramFacade::shouldReceive('sendMediaMessage')
        ->once()
        ->andReturn('out_voice_mid_1');

    InstagramFacade::shouldReceive('sendTextMessage')->never();

    $response = $this->postJson('/instagram-main-webhook', instagramMainWebhookPayload([
        'mid' => 'in_voice_mid_1',
        'attachments' => [
            [
                'type' => 'audio',
                'payload' => [
                    'url' => 'https://cdn.example.com/customer-voice.mp4',
                ],
            ],
        ],
    ]));

    $response->assertOk()->assertJsonPath('ok', true);

    $chat = Chat::query()->where('company_id', $company->id)->where('channel', 'instagram')->firstOrFail();

    $this->assertDatabaseHas('chat_messages', [
        'chat_id' => $chat->id,
        'sender_type' => ChatMessage::SENDER_CUSTOMER,
        'direction' => ChatMessage::DIRECTION_INBOUND,
        'message_type' => ChatMessage::TYPE_VOICE,
        'channel_message_id' => 'in_voice_mid_1:attachment-0',
    ]);

    $this->assertDatabaseHas('chat_messages', [
        'chat_id' => $chat->id,
        'sender_type' => ChatMessage::SENDER_ASSISTANT,
        'direction' => ChatMessage::DIRECTION_OUTBOUND,
        'message_type' => ChatMessage::TYPE_VOICE,
        'channel_message_id' => 'out_voice_mid_1',
    ]);
});

test('instagram main webhook syncs receiver id and sends outbound via webhook recipient id', function () {
    [, , $assistant, $integration] = instagramWebhookContext();

    config()->set('openai.assistant.api_key', 'test-openai-key');
    config()->set('meta.instagram.auto_reply_enabled', true);

    $integration->forceFill([
        'instagram_user_id' => '178900000001',
        'receiver_id' => 'wrong_receiver_id',
    ])->save();

    $assistantChannel = AssistantChannel::query()
        ->where('assistant_id', $assistant->id)
        ->where('channel', AssistantChannel::CHANNEL_INSTAGRAM)
        ->firstOrFail();

    $assistantChannel->forceFill([
        'external_account_id' => 'wrong_receiver_id',
        'credentials' => [
            'provider' => 'instagram',
            'access_token' => 'instagram-access-token',
            'instagram_user_id' => '178900000001',
            'receiver_id' => 'wrong_receiver_id',
        ],
    ])->save();

    $this->mock(OpenAiAssistantClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('createThread')->once()->andReturn('thread_instagram_sync_1');
        $mock->shouldReceive('sendTextMessage')->once()->andReturn('message_instagram_sync_1');
        $mock->shouldReceive('runThreadAndGetResponse')->once()->andReturn('Понял, отвечаю.');
    });

    InstagramFacade::shouldReceive('sendTextMessage')
        ->once()
        ->withArgs(function (
            string $igUserId,
            string $recipientId,
            string $text,
            ?string $accessToken,
            bool $store,
        ): bool {
            return $igUserId === '178900000001'
                && $recipientId === 'customer-1001'
                && $text === 'Понял, отвечаю.'
                && $accessToken === 'instagram-access-token'
                && $store === false;
        })
        ->andReturn('out_mid_sync_1');

    $response = $this->postJson('/instagram-main-webhook', instagramMainWebhookPayload([
        'mid' => 'in_mid_sync_1',
        'text' => 'Привет',
    ]));

    $response->assertOk()->assertJsonPath('ok', true);

    $integration->refresh();
    expect((string) ($integration->receiver_id ?? ''))->toBe('178900000001');

    $assistantChannel->refresh();
    expect((string) ($assistantChannel->external_account_id ?? ''))->toBe('178900000001');

    $credentials = is_array($assistantChannel->credentials) ? $assistantChannel->credentials : [];
    expect((string) ($credentials['receiver_id'] ?? ''))->toBe('178900000001');
});

test('instagram main webhook resolves integration by recipient probe when receiver id differs from oauth user id', function () {
    [, $company, $assistant, $integration] = instagramWebhookContext();

    config()->set('openai.assistant.api_key', 'test-openai-key');
    config()->set('meta.instagram.auto_reply_enabled', true);
    config()->set('meta.instagram.graph_base', 'https://graph.instagram.com');
    config()->set('meta.instagram.api_version', 'v23.0');

    $integration->forceFill([
        'instagram_user_id' => '24498714506445566',
        'receiver_id' => '24498714506445566',
        'token_expires_at' => now()->addDays(30),
    ])->save();

    $assistantChannel = AssistantChannel::query()
        ->where('assistant_id', $assistant->id)
        ->where('channel', AssistantChannel::CHANNEL_INSTAGRAM)
        ->firstOrFail();

    $assistantChannel->forceFill([
        'external_account_id' => '24498714506445566',
        'credentials' => [
            'provider' => 'instagram',
            'access_token' => 'instagram-access-token',
            'instagram_user_id' => '24498714506445566',
            'receiver_id' => '24498714506445566',
            'token_expires_at' => now()->addDays(30)->toIso8601String(),
        ],
    ])->save();

    Http::fake([
        'https://graph.instagram.com/v23.0/34773679555564224*' => Http::response([
            'id' => '34773679555564224',
        ], 200),
    ]);

    $this->mock(OpenAiAssistantClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('createThread')->once()->andReturn('thread_instagram_probe_1');
        $mock->shouldReceive('sendTextMessage')->once()->andReturn('message_instagram_probe_1');
        $mock->shouldReceive('runThreadAndGetResponse')->once()->andReturn('Отвечаю через новый receiver id.');
    });

    InstagramFacade::shouldReceive('sendTextMessage')
        ->once()
        ->withArgs(function (
            string $igUserId,
            string $recipientId,
            string $text,
            ?string $accessToken,
            bool $store,
        ): bool {
            return $igUserId === '34773679555564224'
                && $recipientId === 'customer-1001'
                && $text === 'Отвечаю через новый receiver id.'
                && $accessToken === 'instagram-access-token'
                && $store === false;
        })
        ->andReturn('out_mid_probe_1');

    $payload = instagramMainWebhookPayload([
        'mid' => 'in_mid_probe_1',
        'text' => 'салом',
    ]);
    data_set($payload, 'entry.0.messaging.0.recipient.id', '34773679555564224');

    $response = $this->postJson('/instagram-main-webhook', $payload);

    $response->assertOk()->assertJsonPath('ok', true);

    $integration->refresh();
    expect((string) ($integration->receiver_id ?? ''))->toBe('34773679555564224');

    $assistantChannel->refresh();
    expect((string) ($assistantChannel->external_account_id ?? ''))->toBe('34773679555564224');

    $this->assertDatabaseHas('chats', [
        'company_id' => $company->id,
        'channel' => 'instagram',
        'channel_chat_id' => '34773679555564224:customer-1001',
    ]);
});

test('instagram main webhook resolves integration by subscribed apps probe when profile id lookup is unsupported', function () {
    [, , $assistant, $integration] = instagramWebhookContext();

    config()->set('openai.assistant.api_key', 'test-openai-key');
    config()->set('meta.instagram.auto_reply_enabled', true);
    config()->set('meta.instagram.graph_base', 'https://graph.instagram.com');
    config()->set('meta.instagram.api_version', 'v23.0');

    $integration->forceFill([
        'instagram_user_id' => '24498714506445566',
        'receiver_id' => '24498714506445566',
        'token_expires_at' => now()->addDays(30),
    ])->save();

    $assistantChannel = AssistantChannel::query()
        ->where('assistant_id', $assistant->id)
        ->where('channel', AssistantChannel::CHANNEL_INSTAGRAM)
        ->firstOrFail();

    $assistantChannel->forceFill([
        'external_account_id' => '24498714506445566',
        'credentials' => [
            'provider' => 'instagram',
            'access_token' => 'instagram-access-token',
            'instagram_user_id' => '24498714506445566',
            'receiver_id' => '24498714506445566',
            'token_expires_at' => now()->addDays(30)->toIso8601String(),
        ],
    ])->save();

    Http::fake([
        'https://graph.instagram.com/v23.0/34773679555564224' => Http::response([
            'error' => [
                'message' => 'Unsupported get request',
                'type' => 'OAuthException',
                'code' => 100,
            ],
        ], 400),
        'https://graph.instagram.com/34773679555564224' => Http::response([
            'error' => [
                'message' => 'Unsupported get request',
                'type' => 'OAuthException',
                'code' => 100,
            ],
        ], 400),
        'https://graph.instagram.com/v23.0/34773679555564224/subscribed_apps*' => Http::response([
            'data' => [
                ['id' => '1234567890'],
            ],
        ], 200),
    ]);

    $this->mock(OpenAiAssistantClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('createThread')->once()->andReturn('thread_instagram_sub_apps_1');
        $mock->shouldReceive('sendTextMessage')->once()->andReturn('message_instagram_sub_apps_1');
        $mock->shouldReceive('runThreadAndGetResponse')->once()->andReturn('Ответ через subscribed_apps.');
    });

    InstagramFacade::shouldReceive('sendTextMessage')
        ->once()
        ->withArgs(function (
            string $igUserId,
            string $recipientId,
            string $text,
            ?string $accessToken,
            bool $store,
        ): bool {
            return $igUserId === '34773679555564224'
                && $recipientId === 'customer-1001'
                && $text === 'Ответ через subscribed_apps.'
                && $accessToken === 'instagram-access-token'
                && $store === false;
        })
        ->andReturn('out_mid_sub_apps_1');

    $payload = instagramMainWebhookPayload([
        'mid' => 'in_mid_sub_apps_1',
        'text' => 'салом',
    ]);
    data_set($payload, 'entry.0.messaging.0.recipient.id', '34773679555564224');

    $response = $this->postJson('/instagram-main-webhook', $payload);

    $response->assertOk()->assertJsonPath('ok', true);

    $integration->refresh();
    expect((string) ($integration->receiver_id ?? ''))->toBe('34773679555564224');
});

test('instagram main webhook resolves customer profile for new chat and stores real name and avatar', function () {
    [, $company] = instagramWebhookContext();

    config()->set('meta.instagram.auto_reply_enabled', false);
    config()->set('meta.instagram.resolve_customer_profile', true);
    config()->set('meta.instagram.graph_base', 'https://graph.instagram.com');
    config()->set('meta.instagram.api_version', 'v23.0');

    Http::fake([
        'https://graph.instagram.com/v23.0/customer-1001*' => Http::response([
            'name' => 'Mahmud Iskhod',
            'username' => 'mahmud.ig',
            'profile_pic' => 'https://cdn.example.com/avatars/mahmud.jpg',
        ], 200),
        'https://graph.facebook.com/v23.0/customer-1001*' => Http::response([
            'name' => 'Mahmud Iskhod',
            'profile_pic' => 'https://cdn.example.com/avatars/mahmud.jpg',
        ], 200),
    ]);

    InstagramFacade::shouldReceive('sendTextMessage')->never();
    InstagramFacade::shouldReceive('sendMediaMessage')->never();

    $response = $this->postJson('/instagram-main-webhook', instagramMainWebhookPayload([
        'mid' => 'in_mid_profile_1',
        'text' => 'Привет',
    ]));

    $response->assertOk()->assertJsonPath('ok', true);

    $chat = Chat::query()
        ->where('company_id', $company->id)
        ->where('channel', 'instagram')
        ->firstOrFail();

    expect((string) $chat->name)->toBe('Mahmud Iskhod');
    expect((string) $chat->avatar)->toBe('https://cdn.example.com/avatars/mahmud.jpg');
    expect((string) data_get($chat->metadata, 'instagram.customer_profile.name'))->toBe('Mahmud Iskhod');
    expect((string) data_get($chat->metadata, 'instagram.customer_profile.username'))->toBe('mahmud.ig');
    expect((string) data_get($chat->metadata, 'instagram.customer_profile.avatar'))
        ->toBe('https://cdn.example.com/avatars/mahmud.jpg');

    Http::assertSent(function ($request): bool {
        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://graph.instagram.com/v23.0/customer-1001')
            && (string) ($request['fields'] ?? '') === 'name,username,profile_pic,profile_picture_url';
    });
});

test('instagram main webhook refreshes existing placeholder chat name and avatar using instagram profile', function () {
    [, $company] = instagramWebhookContext();

    config()->set('meta.instagram.auto_reply_enabled', false);
    config()->set('meta.instagram.resolve_customer_profile', true);
    config()->set('meta.instagram.customer_profile_refresh_minutes', 5);
    config()->set('meta.instagram.graph_base', 'https://graph.instagram.com');
    config()->set('meta.instagram.api_version', 'v23.0');

    $chat = Chat::query()->create([
        'user_id' => $company->user_id,
        'company_id' => $company->id,
        'assistant_id' => null,
        'assistant_channel_id' => null,
        'channel' => 'instagram',
        'channel_chat_id' => '178900000001:customer-1001',
        'channel_user_id' => 'customer-1001',
        'name' => 'Instagram customer-1001',
        'avatar' => null,
        'status' => Chat::STATUS_OPEN,
        'metadata' => [
            'instagram' => [
                'customer_profile' => [
                    'resolved_at' => now()->subHours(2)->toIso8601String(),
                ],
            ],
        ],
    ]);

    Http::fake([
        'https://graph.instagram.com/v23.0/customer-1001*' => Http::response([
            'name' => 'Real Customer Name',
            'username' => 'real.customer',
            'profile_pic' => 'https://cdn.example.com/avatars/real-customer.jpg',
        ], 200),
    ]);

    InstagramFacade::shouldReceive('sendTextMessage')->never();
    InstagramFacade::shouldReceive('sendMediaMessage')->never();

    $response = $this->postJson('/instagram-main-webhook', instagramMainWebhookPayload([
        'mid' => 'in_mid_profile_refresh_1',
        'text' => 'Здравствуйте',
    ]));

    $response->assertOk()->assertJsonPath('ok', true);

    $chat->refresh();

    expect((string) $chat->name)->toBe('Real Customer Name');
    expect((string) $chat->avatar)->toBe('https://cdn.example.com/avatars/real-customer.jpg');
    expect((string) data_get($chat->metadata, 'instagram.customer_profile.name'))->toBe('Real Customer Name');
    expect((string) data_get($chat->metadata, 'instagram.customer_profile.username'))->toBe('real.customer');
    expect((string) data_get($chat->metadata, 'instagram.customer_profile.avatar'))
        ->toBe('https://cdn.example.com/avatars/real-customer.jpg');
});

test('instagram main webhook retries instagram profile request with fallback field sets', function () {
    [, $company] = instagramWebhookContext();

    config()->set('meta.instagram.auto_reply_enabled', false);
    config()->set('meta.instagram.resolve_customer_profile', true);
    config()->set('meta.instagram.graph_base', 'https://graph.instagram.com');
    config()->set('meta.instagram.api_version', 'v23.0');

    Http::fake(function ($request) {
        $url = $request->url();

        if (! str_starts_with($url, 'https://graph.instagram.com/v23.0/customer-1001')) {
            return Http::response([], 404);
        }

        $fields = trim((string) ($request['fields'] ?? ''));
        if ($fields === 'name,username,profile_pic,profile_picture_url') {
            return Http::response([
                'error' => [
                    'message' => '(#100) Tried accessing nonexisting field (profile_pic)',
                    'type' => 'OAuthException',
                    'code' => 100,
                ],
            ], 400);
        }

        if ($fields === 'name,username,profile_picture_url') {
            return Http::response([
                'username' => 'customer.ig',
                'profile_picture_url' => 'https://cdn.example.com/avatars/customer-fallback.jpg',
            ], 200);
        }

        return Http::response([
            'error' => [
                'message' => 'Invalid field list',
                'type' => 'OAuthException',
                'code' => 100,
            ],
        ], 400);
    });

    InstagramFacade::shouldReceive('sendTextMessage')->never();
    InstagramFacade::shouldReceive('sendMediaMessage')->never();

    $response = $this->postJson('/instagram-main-webhook', instagramMainWebhookPayload([
        'mid' => 'in_mid_profile_fallback_fields_1',
        'text' => 'салом',
    ]));

    $response->assertOk()->assertJsonPath('ok', true);

    $chat = Chat::query()
        ->where('company_id', $company->id)
        ->where('channel', 'instagram')
        ->firstOrFail();

    expect((string) $chat->name)->toBe('customer.ig');
    expect((string) $chat->avatar)->toBe('https://cdn.example.com/avatars/customer-fallback.jpg');
    expect((string) data_get($chat->metadata, 'instagram.customer_profile.username'))->toBe('customer.ig');

    Http::assertSent(function ($request): bool {
        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://graph.instagram.com/v23.0/customer-1001')
            && (string) ($request['fields'] ?? '') === 'name,username,profile_pic,profile_picture_url';
    });

    Http::assertSent(function ($request): bool {
        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://graph.instagram.com/v23.0/customer-1001')
            && (string) ($request['fields'] ?? '') === 'name,username,profile_picture_url';
    });
});

test('instagram main webhook refreshes expiring token before customer profile lookup', function () {
    [, $company, , $integration] = instagramWebhookContext();

    config()->set('meta.instagram.auto_reply_enabled', false);
    config()->set('meta.instagram.resolve_customer_profile', true);
    config()->set('meta.instagram.graph_base', 'https://graph.instagram.com');
    config()->set('meta.instagram.api_version', 'v23.0');
    config()->set('meta.instagram.token_refresh_grace_seconds', 900);

    $integration->forceFill([
        'access_token' => 'expired-token',
        'token_expires_at' => now()->subMinutes(10),
    ])->save();

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_starts_with($url, 'https://graph.instagram.com/refresh_access_token')) {
            return Http::response([
                'access_token' => 'fresh-token',
                'expires_in' => 5184000,
            ], 200);
        }

        if (str_starts_with($url, 'https://graph.instagram.com/v23.0/customer-1001')) {
            if ((string) ($request['access_token'] ?? '') !== 'fresh-token') {
                return Http::response([
                    'error' => [
                        'message' => 'Error validating access token',
                        'type' => 'OAuthException',
                        'code' => 190,
                    ],
                ], 401);
            }

            return Http::response([
                'name' => 'Fresh Token User',
                'username' => 'fresh.token.user',
                'profile_picture_url' => 'https://cdn.example.com/avatars/fresh-token-user.jpg',
            ], 200);
        }

        return Http::response([], 404);
    });

    InstagramFacade::shouldReceive('sendTextMessage')->never();
    InstagramFacade::shouldReceive('sendMediaMessage')->never();

    $response = $this->postJson('/instagram-main-webhook', instagramMainWebhookPayload([
        'mid' => 'in_mid_profile_refresh_token_1',
        'text' => 'привет',
    ]));

    $response->assertOk()->assertJsonPath('ok', true);

    $integration->refresh();
    expect((string) $integration->access_token)->toBe('fresh-token');
    expect($integration->token_expires_at)->not->toBeNull();

    $chat = Chat::query()
        ->where('company_id', $company->id)
        ->where('channel', 'instagram')
        ->firstOrFail();

    expect((string) $chat->name)->toBe('Fresh Token User');
    expect((string) $chat->avatar)->toBe('https://cdn.example.com/avatars/fresh-token-user.jpg');

    Http::assertSent(function ($request): bool {
        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://graph.instagram.com/refresh_access_token')
            && (string) ($request['grant_type'] ?? '') === 'ig_refresh_token'
            && (string) ($request['access_token'] ?? '') === 'expired-token';
    });
});

test('instagram main webhook refreshes token for profile lookup when token expiry is missing', function () {
    [, $company, , $integration] = instagramWebhookContext();

    config()->set('meta.instagram.auto_reply_enabled', false);
    config()->set('meta.instagram.resolve_customer_profile', true);
    config()->set('meta.instagram.graph_base', 'https://graph.instagram.com');
    config()->set('meta.instagram.api_version', 'v23.0');
    config()->set('meta.instagram.token_refresh_grace_seconds', 900);
    config()->set('meta.instagram.profile_token_refresh_cooldown_minutes', 360);

    $integration->forceFill([
        'access_token' => 'missing-expiry-token',
        'token_expires_at' => null,
    ])->save();

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_starts_with($url, 'https://graph.instagram.com/refresh_access_token')) {
            return Http::response([
                'access_token' => 'fresh-token-without-expiry',
                'expires_in' => 5184000,
            ], 200);
        }

        if (str_starts_with($url, 'https://graph.instagram.com/v23.0/customer-1001')) {
            if ((string) ($request['access_token'] ?? '') !== 'fresh-token-without-expiry') {
                return Http::response([
                    'error' => [
                        'message' => 'Error validating access token',
                        'type' => 'OAuthException',
                        'code' => 190,
                    ],
                ], 401);
            }

            return Http::response([
                'username' => 'without.expiry.user',
                'profile_picture_url' => 'https://cdn.example.com/avatars/without-expiry-user.jpg',
            ], 200);
        }

        return Http::response([], 404);
    });

    InstagramFacade::shouldReceive('sendTextMessage')->never();
    InstagramFacade::shouldReceive('sendMediaMessage')->never();

    $response = $this->postJson('/instagram-main-webhook', instagramMainWebhookPayload([
        'mid' => 'in_mid_profile_refresh_missing_expiry_1',
        'text' => 'hello',
    ]));

    $response->assertOk()->assertJsonPath('ok', true);

    $integration->refresh();
    expect((string) $integration->access_token)->toBe('fresh-token-without-expiry');
    expect($integration->token_expires_at)->not->toBeNull();

    $chat = Chat::query()
        ->where('company_id', $company->id)
        ->where('channel', 'instagram')
        ->firstOrFail();

    expect((string) $chat->name)->toBe('without.expiry.user');
    expect((string) $chat->avatar)->toBe('https://cdn.example.com/avatars/without-expiry-user.jpg');
});

test('instagram main webhook appends repeated mid messages into same chat', function () {
    [, $company] = instagramWebhookContext();

    config()->set('meta.instagram.auto_reply_enabled', false);

    InstagramFacade::shouldReceive('sendTextMessage')->never();
    InstagramFacade::shouldReceive('sendMediaMessage')->never();

    $payload = [
        'field' => 'messages',
        'value' => [
            'sender' => ['id' => 'customer-3001'],
            'recipient' => ['id' => '178900000001'],
            'timestamp' => '1527459824',
            'message' => [
                'mid' => 'random_mid_direct',
                'text' => 'салом',
            ],
        ],
    ];

    $first = $this->postJson('/instagram-main-webhook', $payload);
    $first->assertOk()->assertJsonPath('ok', true);

    $second = $this->postJson('/instagram-main-webhook', $payload);
    $second->assertOk()->assertJsonPath('ok', true);

    $chat = Chat::query()->where('company_id', $company->id)->where('channel', 'instagram')->firstOrFail();

    expect($chat->channel_chat_id)->toBe('178900000001:customer-3001');
    expect(Chat::query()->where('company_id', $company->id)->where('channel', 'instagram')->count())->toBe(1);

    $inboundMessages = ChatMessage::query()
        ->where('chat_id', $chat->id)
        ->where('sender_type', ChatMessage::SENDER_CUSTOMER)
        ->where('direction', ChatMessage::DIRECTION_INBOUND)
        ->orderBy('id')
        ->get();

    expect($inboundMessages)->toHaveCount(2);
    expect($inboundMessages[0]->channel_message_id)->toBe('random_mid_direct:text');
    expect($inboundMessages[1]->channel_message_id)->toBe('random_mid_direct:text-1527459824000');
    expect($inboundMessages[0]->text)->toBe('салом');
    expect($inboundMessages[1]->text)->toBe('салом');
});

test('instagram main webhook ignores read events and does not store message seen entries', function () {
    [, $company] = instagramWebhookContext();

    config()->set('meta.instagram.auto_reply_enabled', true);

    InstagramFacade::shouldReceive('sendTextMessage')->never();
    InstagramFacade::shouldReceive('sendMediaMessage')->never();

    $payload = [
        'object' => 'instagram',
        'entry' => [
            [
                'id' => 'entry_read_1',
                'time' => now()->valueOf(),
                'messaging' => [
                    [
                        'sender' => ['id' => 'customer-1001'],
                        'recipient' => ['id' => '178900000001'],
                        'timestamp' => now()->valueOf(),
                        'read' => [
                            'mid' => 'in_mid_1',
                            'watermark' => now()->valueOf(),
                        ],
                    ],
                ],
            ],
        ],
    ];

    $response = $this->postJson('/instagram-main-webhook', $payload);
    $response->assertOk()->assertJsonPath('ok', true);

    $chatCount = Chat::query()
        ->where('company_id', $company->id)
        ->where('channel', 'instagram')
        ->count();
    expect($chatCount)->toBe(0);

    $messagesCount = ChatMessage::query()
        ->where('company_id', $company->id)
        ->count();
    expect($messagesCount)->toBe(0);
});

test('instagram main webhook fallback reply does not leak internal prompt text', function () {
    [, $company] = instagramWebhookContext();

    config()->set('openai.assistant.api_key', 'test-openai-key');
    config()->set('meta.instagram.auto_reply_enabled', true);

    $this->mock(OpenAiAssistantClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('createThread')->once()->andReturn('thread_instagram_fallback_1');
        $mock->shouldReceive('sendTextMessage')->once()->andReturn('message_instagram_fallback_1');
        $mock->shouldReceive('runThreadAndGetResponse')->once()->andReturn('');
    });

    InstagramFacade::shouldReceive('sendTextMessage')
        ->once()
        ->andReturn('out_mid_fallback_1');

    $this->postJson('/instagram-main-webhook', instagramMainWebhookPayload([
        'mid' => 'in_mid_fallback_1',
        'text' => 'салом',
    ]))
        ->assertOk()
        ->assertJsonPath('ok', true);

    $chat = Chat::query()->where('company_id', $company->id)->where('channel', 'instagram')->firstOrFail();
    $assistantMessage = ChatMessage::query()
        ->where('chat_id', $chat->id)
        ->where('sender_type', ChatMessage::SENDER_ASSISTANT)
        ->where('direction', ChatMessage::DIRECTION_OUTBOUND)
        ->latest('id')
        ->firstOrFail();

    expect((string) $assistantMessage->text)->toBe('Извините, сейчас не удалось сформировать ответ. Пожалуйста, попробуйте еще раз.');
    expect((string) $assistantMessage->text)->not->toContain('Incoming customer message');
});
