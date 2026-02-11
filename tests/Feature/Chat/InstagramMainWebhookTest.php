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
use Mockery\MockInterface;
use TexHub\Meta\Facades\Instagram as InstagramFacade;
use TexHub\Meta\Models\InstagramIntegration;
use TexHub\OpenAi\Assistant as OpenAiAssistantClient;

uses(RefreshDatabase::class);

function instagramWebhookContext(string $subscriptionStatus = CompanySubscription::STATUS_ACTIVE): array
{
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
