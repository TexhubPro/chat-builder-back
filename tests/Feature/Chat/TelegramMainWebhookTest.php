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
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use TexHub\OpenAi\Assistant as OpenAiAssistantClient;

uses(RefreshDatabase::class);

function telegramWebhookContext(string $subscriptionStatus = CompanySubscription::STATUS_ACTIVE): array
{
    config()->set('services.telegram.resolve_customer_profile', false);

    $user = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $company = Company::query()->create([
        'user_id' => $user->id,
        'name' => 'Telegram Company',
        'slug' => 'telegram-company-'.$user->id,
        'status' => Company::STATUS_ACTIVE,
    ]);

    $plan = SubscriptionPlan::query()->create([
        'code' => 'telegram-plan-'.$user->id,
        'name' => 'Telegram Plan',
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
        'name' => 'Telegram Assistant',
        'openai_assistant_id' => 'asst_telegram_1',
        'is_active' => true,
        'conversation_tone' => Assistant::TONE_POLITE,
        'enable_file_search' => true,
        'enable_file_analysis' => false,
        'enable_voice' => true,
        'enable_web_search' => false,
    ]);

    $assistantChannel = AssistantChannel::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'assistant_id' => $assistant->id,
        'channel' => AssistantChannel::CHANNEL_TELEGRAM,
        'name' => 'Telegram Bot',
        'external_account_id' => '987654321',
        'is_active' => true,
        'credentials' => [
            'provider' => 'telegram',
            'bot_token' => 'telegram-token',
            'bot_id' => '987654321',
            'bot_username' => 'support_bot',
            'webhook_secret' => 'tg-secret-123',
        ],
    ]);

    return [$user, $company, $assistant, $assistantChannel];
}

function telegramWebhookPayload(array $message): array
{
    return [
        'update_id' => 712313,
        'message' => array_merge([
            'message_id' => 1001,
            'from' => [
                'id' => 3001,
                'is_bot' => false,
                'first_name' => 'Abdu',
                'username' => 'abdu',
            ],
            'chat' => [
                'id' => 5001,
                'first_name' => 'Abdu',
                'username' => 'abdu',
                'type' => 'private',
            ],
            'date' => 1710000000,
        ], $message),
    ];
}

test('telegram webhook stores chat and sends assistant reply when conditions are met', function () {
    [, $company, , $assistantChannel] = telegramWebhookContext();

    config()->set('services.telegram.bot_api_base', 'https://api.telegram.org');
    config()->set('services.telegram.auto_reply_enabled', true);
    config()->set('openai.assistant.api_key', 'test-openai-key');

    $this->mock(OpenAiAssistantClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('createThread')->once()->andReturn('thread_telegram_1');
        $mock->shouldReceive('sendTextMessage')->once()->andReturn('message_telegram_1');
        $mock->shouldReceive('runThreadAndGetResponse')->once()->andReturn('Здравствуйте! Чем помочь?');
    });

    Http::fake([
        'https://api.telegram.org/bottelegram-token/sendMessage' => Http::response([
            'ok' => true,
            'result' => [
                'message_id' => 9001,
            ],
        ], 200),
    ]);

    $response = $this
        ->withHeader('X-Telegram-Bot-Api-Secret-Token', 'tg-secret-123')
        ->postJson('/api/integrations/telegram/webhook/'.$assistantChannel->id, telegramWebhookPayload([
            'text' => 'салом',
        ]));

    $response->assertOk()->assertJsonPath('ok', true);

    $this->assertDatabaseHas('chats', [
        'company_id' => $company->id,
        'channel' => 'telegram',
        'channel_chat_id' => '5001',
        'assistant_channel_id' => $assistantChannel->id,
    ]);

    $chat = Chat::query()->where('company_id', $company->id)->where('channel', 'telegram')->firstOrFail();

    $this->assertDatabaseHas('chat_messages', [
        'chat_id' => $chat->id,
        'sender_type' => ChatMessage::SENDER_CUSTOMER,
        'direction' => ChatMessage::DIRECTION_INBOUND,
        'message_type' => ChatMessage::TYPE_TEXT,
        'channel_message_id' => '1001:text',
        'text' => 'салом',
    ]);

    $this->assertDatabaseHas('chat_messages', [
        'chat_id' => $chat->id,
        'sender_type' => ChatMessage::SENDER_ASSISTANT,
        'direction' => ChatMessage::DIRECTION_OUTBOUND,
        'message_type' => ChatMessage::TYPE_TEXT,
        'channel_message_id' => '9001',
        'text' => 'Здравствуйте! Чем помочь?',
    ]);

    $subscription = $company->subscription()->firstOrFail()->refresh();
    expect((int) $subscription->chat_count_current_period)->toBe(1);
});

test('telegram webhook downloads inbound photo and sends it to openai as vision file', function () {
    [, $company, , $assistantChannel] = telegramWebhookContext();
    Storage::fake('public');

    config()->set('services.telegram.bot_api_base', 'https://api.telegram.org');
    config()->set('services.telegram.auto_reply_enabled', true);
    config()->set('openai.assistant.api_key', 'test-openai-key');

    $this->mock(OpenAiAssistantClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('createThread')->once()->andReturn('thread_telegram_photo_1');
        $mock->shouldReceive('uploadFile')
            ->once()
            ->with(\Mockery::type('string'), 'vision')
            ->andReturn('file_telegram_photo_1');
        $mock->shouldReceive('sendImageFileMessage')->once()->andReturn('message_telegram_photo_1');
        $mock->shouldReceive('runThreadAndGetResponse')->once()->andReturn('Фото получено, спасибо.');
    });

    Http::fake([
        'https://api.telegram.org/bottelegram-token/getFile' => Http::response([
            'ok' => true,
            'result' => [
                'file_path' => 'photos/test-photo.jpg',
            ],
        ], 200),
        'https://api.telegram.org/file/bottelegram-token/photos/test-photo.jpg' => Http::response(
            'fake-image-binary-content',
            200,
            ['Content-Type' => 'image/jpeg']
        ),
        'https://api.telegram.org/bottelegram-token/sendMessage' => Http::response([
            'ok' => true,
            'result' => [
                'message_id' => 9002,
            ],
        ], 200),
    ]);

    $this
        ->withHeader('X-Telegram-Bot-Api-Secret-Token', 'tg-secret-123')
        ->postJson('/api/integrations/telegram/webhook/'.$assistantChannel->id, telegramWebhookPayload([
            'message_id' => 1004,
            'photo' => [
                ['file_id' => 'tg_file_1', 'width' => 90, 'height' => 90],
                ['file_id' => 'tg_file_2', 'width' => 640, 'height' => 640],
            ],
        ]))
        ->assertOk()
        ->assertJsonPath('ok', true);

    $chat = Chat::query()->where('company_id', $company->id)->where('channel', 'telegram')->firstOrFail();

    $this->assertDatabaseHas('chat_messages', [
        'chat_id' => $chat->id,
        'sender_type' => ChatMessage::SENDER_CUSTOMER,
        'direction' => ChatMessage::DIRECTION_INBOUND,
        'message_type' => ChatMessage::TYPE_IMAGE,
        'channel_message_id' => '1004:photo',
    ]);

    $this->assertDatabaseHas('chat_messages', [
        'chat_id' => $chat->id,
        'sender_type' => ChatMessage::SENDER_ASSISTANT,
        'direction' => ChatMessage::DIRECTION_OUTBOUND,
        'message_type' => ChatMessage::TYPE_TEXT,
        'channel_message_id' => '9002',
        'text' => 'Фото получено, спасибо.',
    ]);
});

test('telegram webhook stores inbound message without assistant reply when channel is inactive', function () {
    [, $company, , $assistantChannel] = telegramWebhookContext();

    config()->set('services.telegram.bot_api_base', 'https://api.telegram.org');
    config()->set('services.telegram.auto_reply_enabled', true);
    config()->set('openai.assistant.api_key', 'test-openai-key');

    $assistantChannel->forceFill([
        'is_active' => false,
    ])->save();

    Http::fake();

    $this
        ->withHeader('X-Telegram-Bot-Api-Secret-Token', 'tg-secret-123')
        ->postJson('/api/integrations/telegram/webhook/'.$assistantChannel->id, telegramWebhookPayload([
            'message_id' => 1002,
            'text' => 'hello',
        ]))
        ->assertOk()
        ->assertJsonPath('ok', true);

    $chat = Chat::query()->where('company_id', $company->id)->where('channel', 'telegram')->firstOrFail();

    expect($chat->messages()->count())->toBe(1);

    $this->assertDatabaseHas('chat_messages', [
        'chat_id' => $chat->id,
        'sender_type' => ChatMessage::SENDER_CUSTOMER,
        'direction' => ChatMessage::DIRECTION_INBOUND,
        'channel_message_id' => '1002:text',
    ]);

    $this->assertDatabaseMissing('chat_messages', [
        'chat_id' => $chat->id,
        'sender_type' => ChatMessage::SENDER_ASSISTANT,
    ]);
});

test('telegram webhook resolves customer avatar and stores profile snapshot in chat metadata', function () {
    [, $company, , $assistantChannel] = telegramWebhookContext();

    config()->set('services.telegram.bot_api_base', 'https://api.telegram.org');
    config()->set('services.telegram.auto_reply_enabled', false);
    config()->set('services.telegram.resolve_customer_profile', true);
    config()->set('services.telegram.customer_profile_refresh_minutes', 1440);

    Http::fake([
        'https://api.telegram.org/bottelegram-token/getUserProfilePhotos' => Http::response([
            'ok' => true,
            'result' => [
                'total_count' => 1,
                'photos' => [
                    [
                        ['file_id' => 'photo-small', 'width' => 90, 'height' => 90],
                        ['file_id' => 'photo-large', 'width' => 640, 'height' => 640],
                    ],
                ],
            ],
        ], 200),
        'https://api.telegram.org/bottelegram-token/getFile' => Http::response([
            'ok' => true,
            'result' => [
                'file_path' => 'photos/profile-3001.jpg',
            ],
        ], 200),
    ]);

    $this
        ->withHeader('X-Telegram-Bot-Api-Secret-Token', 'tg-secret-123')
        ->postJson('/api/integrations/telegram/webhook/'.$assistantChannel->id, telegramWebhookPayload([
            'message_id' => 1010,
            'text' => 'hello',
        ]))
        ->assertOk()
        ->assertJsonPath('ok', true);

    $chat = Chat::query()
        ->where('company_id', $company->id)
        ->where('channel', 'telegram')
        ->firstOrFail();

    expect((string) $chat->name)->toBe('Abdu');
    expect((string) $chat->avatar)->toBe('https://api.telegram.org/file/bottelegram-token/photos/profile-3001.jpg');
    expect((string) data_get($chat->metadata, 'telegram.customer_profile.name'))->toBe('Abdu');
    expect((string) data_get($chat->metadata, 'telegram.customer_profile.username'))->toBe('abdu');
    expect((string) data_get($chat->metadata, 'telegram.customer_profile.avatar'))
        ->toBe('https://api.telegram.org/file/bottelegram-token/photos/profile-3001.jpg');

    Http::assertSent(function ($request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.telegram.org/bottelegram-token/getUserProfilePhotos'
            && (string) ($request['user_id'] ?? '') === '3001';
    });
});

test('telegram webhook appends repeated message id into same chat', function () {
    [, $company, , $assistantChannel] = telegramWebhookContext();

    config()->set('services.telegram.auto_reply_enabled', false);

    Http::fake();

    $payload = telegramWebhookPayload([
        'message_id' => 1003,
        'text' => 'салом',
        'date' => 1710000000,
    ]);

    $this
        ->withHeader('X-Telegram-Bot-Api-Secret-Token', 'tg-secret-123')
        ->postJson('/api/integrations/telegram/webhook/'.$assistantChannel->id, $payload)
        ->assertOk()
        ->assertJsonPath('ok', true);

    $this
        ->withHeader('X-Telegram-Bot-Api-Secret-Token', 'tg-secret-123')
        ->postJson('/api/integrations/telegram/webhook/'.$assistantChannel->id, $payload)
        ->assertOk()
        ->assertJsonPath('ok', true);

    $chat = Chat::query()->where('company_id', $company->id)->where('channel', 'telegram')->firstOrFail();

    expect(Chat::query()->where('company_id', $company->id)->where('channel', 'telegram')->count())->toBe(1);

    $inboundMessages = ChatMessage::query()
        ->where('chat_id', $chat->id)
        ->where('sender_type', ChatMessage::SENDER_CUSTOMER)
        ->where('direction', ChatMessage::DIRECTION_INBOUND)
        ->orderBy('id')
        ->get();

    expect($inboundMessages)->toHaveCount(2);
    expect($inboundMessages[0]->channel_message_id)->toBe('1003:text');
    expect($inboundMessages[1]->channel_message_id)->toBe('1003:text-1710000000000');
});
