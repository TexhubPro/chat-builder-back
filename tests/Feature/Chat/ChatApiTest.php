<?php

use App\Models\Assistant;
use App\Models\AssistantChannel;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\CompanyCalendarEvent;
use App\Models\CompanySubscription;
use App\Models\CompanyClient;
use App\Models\CompanyClientOrder;
use App\Models\CompanyClientTask;
use App\Models\CompanyClientQuestion;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use TexHub\Meta\Facades\Instagram as InstagramFacade;
use TexHub\Meta\Models\InstagramIntegration;
use TexHub\OpenAi\Assistant as OpenAiAssistantClient;

uses(RefreshDatabase::class);

function chatApiContext(bool $activeSubscription = true): array
{
    $user = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $company = Company::query()->create([
        'user_id' => $user->id,
        'name' => 'Chat API Company',
        'slug' => 'chat-api-company-'.$user->id,
        'status' => Company::STATUS_ACTIVE,
    ]);

    $plan = SubscriptionPlan::query()->create([
        'code' => 'chat-api-plan-'.$user->id,
        'name' => 'Chat API Plan',
        'is_active' => true,
        'is_public' => true,
        'billing_period_days' => 30,
        'currency' => 'USD',
        'price' => 20,
        'included_chats' => 500,
        'overage_chat_price' => 1,
        'assistant_limit' => 2,
        'integrations_per_channel_limit' => 2,
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

function createInstagramChatForApi(
    User $user,
    Company $company,
    string $suffix = '1',
    array $integrationOverrides = [],
    array $chatOverrides = [],
): array {
    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Instagram Assistant '.$suffix,
        'is_active' => true,
    ]);

    $receiverId = (string) ($integrationOverrides['receiver_id'] ?? ('1789000000'.$suffix));
    $accessToken = (string) ($integrationOverrides['access_token'] ?? ('instagram-token-'.$suffix));
    $instagramUserId = (string) ($integrationOverrides['instagram_user_id'] ?? ('ig-business-'.$suffix));

    $integration = InstagramIntegration::query()->create(array_merge([
        'user_id' => $user->id,
        'instagram_user_id' => $instagramUserId,
        'username' => 'ig-company-'.$suffix,
        'receiver_id' => $receiverId,
        'access_token' => $accessToken,
        'token_expires_at' => now()->addDays(30),
        'is_active' => true,
    ], $integrationOverrides));

    $assistantChannel = AssistantChannel::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'assistant_id' => $assistant->id,
        'channel' => AssistantChannel::CHANNEL_INSTAGRAM,
        'name' => 'Instagram Channel '.$suffix,
        'external_account_id' => (string) ($integration->receiver_id ?? $receiverId),
        'is_active' => true,
        'credentials' => [
            'provider' => 'instagram',
            'access_token' => (string) $integration->access_token,
            'token_expires_at' => $integration->token_expires_at?->toIso8601String(),
            'instagram_user_id' => (string) $integration->instagram_user_id,
            'receiver_id' => (string) ($integration->receiver_id ?? ''),
        ],
    ]);

    $customerId = (string) ($chatOverrides['channel_user_id'] ?? ('customer-'.$suffix));
    $channelChatId = (string) ($chatOverrides['channel_chat_id'] ?? ($receiverId.':'.$customerId));
    $chatMetadata = array_merge([
        'instagram' => [
            'integration_id' => $integration->id,
            'instagram_user_id' => (string) $integration->instagram_user_id,
            'receiver_id' => (string) ($integration->receiver_id ?? ''),
        ],
    ], is_array($chatOverrides['metadata'] ?? null) ? $chatOverrides['metadata'] : []);

    $chat = Chat::query()->create(array_merge([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'assistant_id' => $assistant->id,
        'assistant_channel_id' => $assistantChannel->id,
        'channel' => 'instagram',
        'channel_chat_id' => $channelChatId,
        'channel_user_id' => $customerId,
        'name' => 'Instagram Customer '.$suffix,
        'status' => Chat::STATUS_OPEN,
        'metadata' => $chatMetadata,
    ], $chatOverrides));

    return [$assistant, $assistantChannel, $integration, $chat];
}

function createTelegramChatForApi(
    User $user,
    Company $company,
    string $suffix = '1',
    array $channelOverrides = [],
    array $chatOverrides = [],
): array {
    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Telegram Assistant '.$suffix,
        'is_active' => true,
    ]);

    $botId = (string) ($channelOverrides['external_account_id'] ?? ('99887766'.$suffix));
    $botToken = (string) ($channelOverrides['credentials']['bot_token'] ?? ('telegram-token-'.$suffix));

    $assistantChannel = AssistantChannel::query()->create(array_merge([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'assistant_id' => $assistant->id,
        'channel' => AssistantChannel::CHANNEL_TELEGRAM,
        'name' => 'Telegram Channel '.$suffix,
        'external_account_id' => $botId,
        'is_active' => true,
        'credentials' => [
            'provider' => 'telegram',
            'bot_token' => $botToken,
            'bot_id' => $botId,
            'bot_username' => 'support_bot_'.$suffix,
        ],
    ], $channelOverrides));

    $chatId = (string) ($chatOverrides['channel_chat_id'] ?? ('5000'.$suffix));
    $channelUserId = (string) ($chatOverrides['channel_user_id'] ?? ('3000'.$suffix));

    $chat = Chat::query()->create(array_merge([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'assistant_id' => $assistant->id,
        'assistant_channel_id' => $assistantChannel->id,
        'channel' => AssistantChannel::CHANNEL_TELEGRAM,
        'channel_chat_id' => $chatId,
        'channel_user_id' => $channelUserId,
        'name' => 'Telegram Customer '.$suffix,
        'status' => Chat::STATUS_OPEN,
        'metadata' => [
            'telegram' => [
                'assistant_channel_id' => $assistantChannel->id,
                'chat_id' => $chatId,
            ],
        ],
    ], $chatOverrides));

    return [$assistant, $assistantChannel, $chat];
}

test('chat api lists chats and applies channel and assistant filters', function () {
    [$user, $company, $token] = chatApiContext();

    $assistantA = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Assistant A',
        'is_active' => true,
    ]);

    $assistantB = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Assistant B',
        'is_active' => false,
    ]);

    Chat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'assistant_id' => $assistantA->id,
        'channel' => 'telegram',
        'channel_chat_id' => 'tg-1',
        'name' => 'Telegram Lead',
        'last_message_preview' => 'Hello from Telegram',
        'last_message_at' => now()->subMinute(),
    ]);

    Chat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'assistant_id' => $assistantB->id,
        'channel' => 'instagram',
        'channel_chat_id' => 'ig-1',
        'name' => 'Instagram Lead',
        'last_message_preview' => 'Hello from Instagram',
        'last_message_at' => now()->subMinutes(2),
    ]);

    Chat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'channel' => 'widget',
        'channel_chat_id' => 'wd-1',
        'name' => 'Widget Lead',
        'last_message_preview' => 'Hello from Widget',
        'last_message_at' => now()->subMinutes(3),
    ]);

    $telegramResponse = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/chats?channel=telegram')
        ->assertOk()
        ->json();

    expect($telegramResponse['chats'])->toHaveCount(1);
    expect($telegramResponse['chats'][0]['channel'])->toBe('telegram');
    expect($telegramResponse['assistants'])->toHaveCount(2);

    $assistantResponse = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/chats?channel=assistant')
        ->assertOk()
        ->json();

    expect($assistantResponse['chats'])->toHaveCount(2);

    $assistantFilterResponse = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/chats?assistant_id='.$assistantA->id)
        ->assertOk()
        ->json();

    expect($assistantFilterResponse['chats'])->toHaveCount(1);
    expect((int) $assistantFilterResponse['chats'][0]['assistant']['id'])->toBe($assistantA->id);
});

test('chat api returns chat details marks as read and sends message', function () {
    [$user, $company, $token] = chatApiContext();

    $chat = Chat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'channel' => 'widget',
        'channel_chat_id' => 'widget-read-1',
        'name' => 'Widget Client',
        'unread_count' => 2,
        'status' => Chat::STATUS_OPEN,
    ]);

    ChatMessage::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'chat_id' => $chat->id,
        'sender_type' => ChatMessage::SENDER_CUSTOMER,
        'direction' => ChatMessage::DIRECTION_INBOUND,
        'status' => 'received',
        'message_type' => ChatMessage::TYPE_TEXT,
        'text' => 'Incoming message',
        'sent_at' => now()->subMinute(),
    ]);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/chats/'.$chat->id)
        ->assertOk()
        ->assertJsonPath('chat.id', $chat->id)
        ->assertJsonCount(1, 'messages');

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/chats/'.$chat->id.'/read')
        ->assertOk()
        ->assertJsonPath('chat.unread_count', 0);

    $chat->refresh();
    expect((int) $chat->unread_count)->toBe(0);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/chats/'.$chat->id.'/messages', [
            'text' => 'Operator reply',
            'sender_type' => 'agent',
            'direction' => 'outbound',
        ])
        ->assertCreated()
        ->assertJsonPath('chat_message.text', 'Operator reply')
        ->assertJsonPath('chat_message.sender_type', 'agent');

    $this->assertDatabaseHas('chat_messages', [
        'chat_id' => $chat->id,
        'text' => 'Operator reply',
        'sender_type' => 'agent',
    ]);
});

test('chat api delivers outbound instagram text message through integration', function () {
    [$user, $company, $token] = chatApiContext();

    [, , $integration, $chat] = createInstagramChatForApi($user, $company, '101', [
        'receiver_id' => '178900000101',
        'access_token' => 'ig-token-text-101',
    ], [
        'channel_user_id' => 'customer-101',
    ]);

    InstagramFacade::shouldReceive('sendTextMessage')
        ->once()
        ->with(
            '178900000101',
            'customer-101',
            'Operator message to Instagram',
            'ig-token-text-101',
            false
        )
        ->andReturn('ig_out_text_101');

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/chats/'.$chat->id.'/messages', [
            'text' => 'Operator message to Instagram',
            'sender_type' => ChatMessage::SENDER_AGENT,
            'direction' => ChatMessage::DIRECTION_OUTBOUND,
            'message_type' => ChatMessage::TYPE_TEXT,
        ])
        ->assertCreated()
        ->assertJsonPath('chat_message.channel_message_id', 'ig_out_text_101')
        ->assertJsonPath('chat_message.message_type', ChatMessage::TYPE_TEXT);

    $this->assertDatabaseHas('chat_messages', [
        'chat_id' => $chat->id,
        'channel_message_id' => 'ig_out_text_101',
        'text' => 'Operator message to Instagram',
        'direction' => ChatMessage::DIRECTION_OUTBOUND,
    ]);

    $this->assertDatabaseHas('instagram_integrations', [
        'id' => $integration->id,
        'access_token' => 'ig-token-text-101',
    ]);
});

test('chat api delivers outbound instagram media message through integration', function () {
    [$user, $company, $token] = chatApiContext();
    Storage::fake('public');

    [, , , $chat] = createInstagramChatForApi($user, $company, '102', [
        'receiver_id' => '178900000102',
        'access_token' => 'ig-token-media-102',
    ], [
        'channel_user_id' => 'customer-102',
    ]);

    InstagramFacade::shouldReceive('sendMediaMessage')
        ->once()
        ->with(
            '178900000102',
            'customer-102',
            'image',
            \Mockery::on(static fn (mixed $url): bool => is_string($url) && str_contains($url, '/storage/chat-files/')),
            false,
            'ig-token-media-102',
            false
        )
        ->andReturn('ig_out_media_102');

    $file = UploadedFile::fake()->image('photo.jpg', 1000, 800)->size(900);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->post('/api/chats/'.$chat->id.'/messages', [
            'file' => $file,
            'sender_type' => ChatMessage::SENDER_AGENT,
            'direction' => ChatMessage::DIRECTION_OUTBOUND,
        ])
        ->assertCreated()
        ->assertJsonPath('chat_message.channel_message_id', 'ig_out_media_102')
        ->assertJsonPath('chat_message.message_type', ChatMessage::TYPE_IMAGE);

    $this->assertDatabaseHas('chat_messages', [
        'chat_id' => $chat->id,
        'channel_message_id' => 'ig_out_media_102',
        'message_type' => ChatMessage::TYPE_IMAGE,
        'direction' => ChatMessage::DIRECTION_OUTBOUND,
    ]);
});

test('chat api delivers outbound telegram text message through integration', function () {
    [$user, $company, $token] = chatApiContext();

    [, , $chat] = createTelegramChatForApi($user, $company, '201', [
        'credentials' => [
            'provider' => 'telegram',
            'bot_token' => 'telegram-token-201',
            'bot_id' => '99887766201',
            'bot_username' => 'support_bot_201',
        ],
    ], [
        'channel_chat_id' => '5201',
    ]);

    config()->set('services.telegram.bot_api_base', 'https://api.telegram.org');

    Http::fake([
        'https://api.telegram.org/bottelegram-token-201/sendMessage' => Http::response([
            'ok' => true,
            'result' => [
                'message_id' => 7771,
            ],
        ], 200),
    ]);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/chats/'.$chat->id.'/messages', [
            'text' => 'Telegram operator text',
            'sender_type' => ChatMessage::SENDER_AGENT,
            'direction' => ChatMessage::DIRECTION_OUTBOUND,
            'message_type' => ChatMessage::TYPE_TEXT,
        ])
        ->assertCreated()
        ->assertJsonPath('chat_message.channel_message_id', '7771')
        ->assertJsonPath('chat_message.message_type', ChatMessage::TYPE_TEXT);

    $this->assertDatabaseHas('chat_messages', [
        'chat_id' => $chat->id,
        'channel_message_id' => '7771',
        'text' => 'Telegram operator text',
        'direction' => ChatMessage::DIRECTION_OUTBOUND,
    ]);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        if ($request->url() !== 'https://api.telegram.org/bottelegram-token-201/sendMessage') {
            return false;
        }

        $data = $request->data();

        return (string) ($data['chat_id'] ?? '') === '5201'
            && (string) ($data['text'] ?? '') === 'Telegram operator text';
    });
});

test('chat api delivers outbound telegram media message through integration', function () {
    [$user, $company, $token] = chatApiContext();
    Storage::fake('public');

    [, , $chat] = createTelegramChatForApi($user, $company, '202', [
        'credentials' => [
            'provider' => 'telegram',
            'bot_token' => 'telegram-token-202',
            'bot_id' => '99887766202',
            'bot_username' => 'support_bot_202',
        ],
    ], [
        'channel_chat_id' => '5202',
    ]);

    config()->set('services.telegram.bot_api_base', 'https://api.telegram.org');

    Http::fake([
        'https://api.telegram.org/bottelegram-token-202/sendPhoto' => Http::response([
            'ok' => true,
            'result' => [
                'message_id' => 7772,
            ],
        ], 200),
    ]);

    $file = UploadedFile::fake()->image('telegram-photo.jpg', 1200, 900)->size(900);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->post('/api/chats/'.$chat->id.'/messages', [
            'file' => $file,
            'sender_type' => ChatMessage::SENDER_AGENT,
            'direction' => ChatMessage::DIRECTION_OUTBOUND,
        ])
        ->assertCreated()
        ->assertJsonPath('chat_message.channel_message_id', '7772')
        ->assertJsonPath('chat_message.message_type', ChatMessage::TYPE_IMAGE);

    $this->assertDatabaseHas('chat_messages', [
        'chat_id' => $chat->id,
        'channel_message_id' => '7772',
        'message_type' => ChatMessage::TYPE_IMAGE,
        'direction' => ChatMessage::DIRECTION_OUTBOUND,
    ]);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        if ($request->url() !== 'https://api.telegram.org/bottelegram-token-202/sendPhoto') {
            return false;
        }

        $data = $request->data();

        return (string) ($data['chat_id'] ?? '') === '5202'
            && is_string($data['photo'] ?? null)
            && str_contains((string) $data['photo'], '/storage/chat-files/');
    });
});

test('chat api refreshes expired instagram token before outbound send', function () {
    [$user, $company, $token] = chatApiContext();

    [, , $integration, $chat] = createInstagramChatForApi($user, $company, '103', [
        'receiver_id' => '178900000103',
        'access_token' => 'expired_token_103',
        'token_expires_at' => now()->subMinutes(2),
    ], [
        'channel_user_id' => 'customer-103',
    ]);

    config()->set('meta.instagram.graph_base', 'https://graph.instagram.com');
    config()->set('meta.instagram.token_refresh_grace_seconds', 60);

    Http::fake([
        'https://graph.instagram.com/refresh_access_token*' => Http::response([
            'access_token' => 'fresh_token_103',
            'token_type' => 'bearer',
            'expires_in' => 5184000,
        ], 200),
    ]);

    InstagramFacade::shouldReceive('sendTextMessage')
        ->once()
        ->with(
            '178900000103',
            'customer-103',
            'Need token refresh',
            'fresh_token_103',
            false
        )
        ->andReturn('ig_out_text_103');

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/chats/'.$chat->id.'/messages', [
            'text' => 'Need token refresh',
            'sender_type' => ChatMessage::SENDER_AGENT,
            'direction' => ChatMessage::DIRECTION_OUTBOUND,
            'message_type' => ChatMessage::TYPE_TEXT,
        ])
        ->assertCreated()
        ->assertJsonPath('chat_message.channel_message_id', 'ig_out_text_103');

    Http::assertSent(function ($request): bool {
        if (! str_starts_with($request->url(), 'https://graph.instagram.com/refresh_access_token')) {
            return false;
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return (string) ($query['grant_type'] ?? '') === 'ig_refresh_token'
            && (string) ($query['access_token'] ?? '') === 'expired_token_103';
    });

    $integration->refresh();
    expect((string) $integration->access_token)->toBe('fresh_token_103');
    expect($integration->token_expires_at)->not->toBeNull();
});

test('chat api refreshes instagram token when token_expires_at is null', function () {
    [$user, $company, $token] = chatApiContext();

    [, , $integration, $chat] = createInstagramChatForApi($user, $company, '104', [
        'receiver_id' => '178900000104',
        'access_token' => 'legacy_token_104',
        'token_expires_at' => null,
    ], [
        'channel_user_id' => 'customer-104',
    ]);

    config()->set('meta.instagram.graph_base', 'https://graph.instagram.com');
    config()->set('meta.instagram.token_refresh_grace_seconds', 60);

    Http::fake([
        'https://graph.instagram.com/refresh_access_token*' => Http::response([
            'access_token' => 'fresh_token_104',
            'token_type' => 'bearer',
            'expires_in' => 5184000,
        ], 200),
    ]);

    InstagramFacade::shouldReceive('sendTextMessage')
        ->once()
        ->with(
            '178900000104',
            'customer-104',
            'Need refresh from null expires',
            'fresh_token_104',
            false
        )
        ->andReturn('ig_out_text_104');

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/chats/'.$chat->id.'/messages', [
            'text' => 'Need refresh from null expires',
            'sender_type' => ChatMessage::SENDER_AGENT,
            'direction' => ChatMessage::DIRECTION_OUTBOUND,
            'message_type' => ChatMessage::TYPE_TEXT,
        ])
        ->assertCreated()
        ->assertJsonPath('chat_message.channel_message_id', 'ig_out_text_104');

    Http::assertSent(function ($request): bool {
        if (! str_starts_with($request->url(), 'https://graph.instagram.com/refresh_access_token')) {
            return false;
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return (string) ($query['grant_type'] ?? '') === 'ig_refresh_token'
            && (string) ($query['access_token'] ?? '') === 'legacy_token_104';
    });

    $integration->refresh();
    expect((string) $integration->access_token)->toBe('fresh_token_104');
    expect($integration->token_expires_at)->not->toBeNull();
});

test('chat api sends file up to 4 megabytes', function () {
    [$user, $company, $token] = chatApiContext();
    Storage::fake('public');

    $chat = Chat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'channel' => 'widget',
        'channel_chat_id' => 'widget-file-1',
        'name' => 'File Client',
        'status' => Chat::STATUS_OPEN,
    ]);

    $file = UploadedFile::fake()->create('offer.pdf', 3500, 'application/pdf');

    $response = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->post('/api/chats/'.$chat->id.'/messages', [
            'file' => $file,
            'sender_type' => 'agent',
            'direction' => 'outbound',
        ])
        ->assertCreated()
        ->json();

    expect($response['chat_message']['message_type'])->toBe('file');
    expect($response['chat_message']['media_url'])->toContain('/storage/chat-files/');
    expect($response['chat_message']['text'])->toBe('offer.pdf');

    $attachment = is_array($response['chat_message']['attachments'] ?? null)
        ? ($response['chat_message']['attachments'][0] ?? null)
        : null;
    $storedPath = is_array($attachment) ? str_replace('/storage/', '', (string) ($attachment['url'] ?? '')) : '';

    expect($storedPath)->toStartWith('chat-files/');
    Storage::disk('public')->assertExists($storedPath);
});

test('chat api rejects files larger than 4 megabytes', function () {
    [$user, $company, $token] = chatApiContext();

    $chat = Chat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'channel' => 'widget',
        'channel_chat_id' => 'widget-file-limit-1',
        'name' => 'File Limit Client',
        'status' => Chat::STATUS_OPEN,
    ]);

    $file = UploadedFile::fake()->create('too-large.pdf', 5000, 'application/pdf');

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Accept', 'application/json')
        ->post('/api/chats/'.$chat->id.'/messages', [
            'file' => $file,
            'sender_type' => 'agent',
            'direction' => 'outbound',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
});

test('assistant chat auto-replies for inbound image file via openai', function () {
    [$user, $company, $token] = chatApiContext();
    Storage::fake('public');

    config()->set('openai.assistant.api_key', 'test-openai-key');

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Media Assistant',
        'openai_assistant_id' => 'asst_media_1',
        'is_active' => true,
        'enable_file_search' => true,
        'enable_file_analysis' => true,
    ]);

    $chat = Chat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'assistant_id' => $assistant->id,
        'channel' => 'assistant',
        'channel_chat_id' => 'assistant-media-'.$assistant->id,
        'name' => 'Assistant Media Test',
        'status' => Chat::STATUS_OPEN,
        'metadata' => [],
    ]);

    $this->mock(OpenAiAssistantClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('createThread')->once()->andReturn('thread_media_1');
        $mock->shouldReceive('uploadFile')->once()->andReturn('file_media_1');
        $mock->shouldReceive('sendImageFileMessage')->once()->andReturn('message_media_1');
        $mock->shouldReceive('runThreadAndGetResponse')->once()->andReturn('Media reply from AI');
    });

    $file = UploadedFile::fake()->image('customer-photo.jpg', 1200, 800)->size(900);

    $response = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Accept', 'application/json')
        ->post('/api/chats/'.$chat->id.'/messages', [
            'file' => $file,
            'sender_type' => ChatMessage::SENDER_CUSTOMER,
            'direction' => ChatMessage::DIRECTION_INBOUND,
        ])
        ->assertCreated()
        ->assertJsonPath('chat_message.message_type', ChatMessage::TYPE_IMAGE)
        ->assertJsonPath('assistant_message.sender_type', ChatMessage::SENDER_ASSISTANT)
        ->assertJsonPath('assistant_message.text', 'Media reply from AI')
        ->json();

    expect($response['assistant_message'])->not->toBeNull();

    $this->assertDatabaseHas('chat_messages', [
        'chat_id' => $chat->id,
        'sender_type' => ChatMessage::SENDER_ASSISTANT,
        'text' => 'Media reply from AI',
    ]);
});

test('chat api can generate assistant reply and store thread metadata', function () {
    [$user, $company, $token] = chatApiContext();

    config()->set('openai.assistant.api_key', 'test-openai-key');

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Support Assistant',
        'openai_assistant_id' => 'asst_openai_1',
        'is_active' => true,
    ]);

    $chat = Chat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'assistant_id' => $assistant->id,
        'channel' => 'api',
        'channel_chat_id' => 'api-thread-1',
        'name' => 'API Client',
        'metadata' => [],
    ]);

    $this->mock(OpenAiAssistantClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('createThread')->once()->andReturn('thread_1');
        $mock->shouldReceive('sendTextMessage')->once()->andReturn('message_1');
        $mock->shouldReceive('runThreadAndGetResponse')->once()->andReturn('Hello from AI');
    });

    $response = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/chats/'.$chat->id.'/assistant-reply', [
            'assistant_id' => $assistant->id,
            'prompt' => 'How can I help this customer?',
        ])
        ->assertCreated()
        ->assertJsonPath('assistant_message.text', 'Hello from AI')
        ->json();

    expect($response['assistant']['id'])->toBe($assistant->id);

    $chat->refresh();
    $metadata = is_array($chat->metadata) ? $chat->metadata : [];
    $threads = is_array($metadata['openai_threads'] ?? null) ? $metadata['openai_threads'] : [];
    expect($threads[(string) $assistant->id] ?? null)->toBe('thread_1');

    expect(
        ChatMessage::query()->where('chat_id', $chat->id)->count()
    )->toBe(2);
});

test('chat api can toggle ai replies per chat', function () {
    [$user, $company, $token] = chatApiContext();

    $chat = Chat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'channel' => 'assistant',
        'channel_chat_id' => 'assistant-toggle-1',
        'name' => 'Toggle chat',
        'status' => Chat::STATUS_OPEN,
        'metadata' => [],
    ]);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/chats/'.$chat->id.'/ai-enabled', [
            'enabled' => false,
        ])
        ->assertOk()
        ->assertJsonPath('chat.metadata.is_active', false);

    $chat->refresh();
    $metadata = is_array($chat->metadata) ? $chat->metadata : [];
    expect($metadata['is_active'] ?? null)->toBeFalse();

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/chats/'.$chat->id.'/ai-enabled', [
            'enabled' => true,
        ])
        ->assertOk()
        ->assertJsonPath('chat.metadata.is_active', true);
});

test('chat api blocks assistant reply when chat ai is disabled', function () {
    [$user, $company, $token] = chatApiContext();

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Disabled Chat Assistant',
        'openai_assistant_id' => 'asst_disabled_chat',
        'is_active' => true,
    ]);

    $chat = Chat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'assistant_id' => $assistant->id,
        'channel' => 'assistant',
        'channel_chat_id' => 'assistant-disabled-1',
        'name' => 'Disabled chat',
        'status' => Chat::STATUS_OPEN,
        'metadata' => [
            'is_active' => false,
        ],
    ]);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/chats/'.$chat->id.'/assistant-reply', [
            'assistant_id' => $assistant->id,
            'prompt' => 'hello',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'AI replies are disabled for this chat.');

    expect(
        ChatMessage::query()->where('chat_id', $chat->id)->count()
    )->toBe(0);
});

test('chat webhook stores telegram message and increments usage', function () {
    [, $company] = chatApiContext();

    $this
        ->postJson('/api/chats/webhook/telegram', [
            'company_id' => $company->id,
            'channel_chat_id' => 'telegram-room-77',
            'channel_user_id' => 'telegram-user-77',
            'name' => 'Telegram Customer',
            'text' => 'Hello from telegram webhook',
            'channel_message_id' => 'tg-msg-77',
        ])
        ->assertCreated()
        ->assertJsonPath('chat.channel', 'telegram')
        ->assertJsonPath('chat.unread_count', 1)
        ->assertJsonPath('duplicate', false);

    $this->assertDatabaseHas('chats', [
        'company_id' => $company->id,
        'channel' => 'telegram',
        'channel_chat_id' => 'telegram-room-77',
        'unread_count' => 1,
    ]);

    $this->assertDatabaseHas('chat_messages', [
        'company_id' => $company->id,
        'channel_message_id' => 'tg-msg-77',
        'sender_type' => ChatMessage::SENDER_CUSTOMER,
    ]);

    $subscription = $company->subscription()->firstOrFail()->refresh();
    expect((int) $subscription->chat_count_current_period)->toBe(1);
});

test('chat webhook requires token when webhook token is configured', function () {
    [, $company] = chatApiContext();
    config()->set('chats.webhook_token', 'secret-123');

    $this
        ->postJson('/api/chats/webhook/api', [
            'company_id' => $company->id,
            'channel_chat_id' => 'api-room-token',
            'text' => 'payload',
        ])
        ->assertStatus(401);

    $this
        ->withHeader('X-Webhook-Token', 'secret-123')
        ->postJson('/api/chats/webhook/api', [
            'company_id' => $company->id,
            'channel_chat_id' => 'api-room-token',
            'text' => 'payload',
        ])
        ->assertCreated()
        ->assertJsonPath('chat.channel', 'api');
});

test('chat api auto-creates assistant test chat when assistant has no chats', function () {
    [$user, $company, $token] = chatApiContext();

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Test Assistant',
        'is_active' => true,
    ]);

    $response = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/chats?channel=assistant')
        ->assertOk()
        ->json();

    expect($response['chats'])->toHaveCount(1);
    expect($response['chats'][0]['channel'])->toBe('assistant');
    expect((int) ($response['chats'][0]['assistant']['id'] ?? 0))->toBe((int) $assistant->id);
    expect($response['chats'][0]['channel_chat_id'])->toBe('assistant-test-'.$assistant->id);

    $this->assertDatabaseHas('chats', [
        'company_id' => $company->id,
        'assistant_id' => $assistant->id,
        'channel' => 'assistant',
        'channel_chat_id' => 'assistant-test-'.$assistant->id,
    ]);
});

test('chat insights returns contacts and history linked to chat', function () {
    [$user, $company, $token] = chatApiContext();

    $chat = Chat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'channel' => 'telegram',
        'channel_chat_id' => 'tg-insights-1',
        'channel_user_id' => '+992900000001',
        'name' => 'Insights Lead',
        'metadata' => [
            'address' => 'Dushanbe',
        ],
        'status' => Chat::STATUS_OPEN,
    ]);

    $client = CompanyClient::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Insights Lead',
        'phone' => '+992900000001',
        'email' => 'insights@example.com',
        'status' => CompanyClient::STATUS_ACTIVE,
    ]);

    $task = CompanyClientTask::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'description' => 'Follow up by phone',
        'status' => CompanyClientTask::STATUS_TODO,
        'board_column' => 'todo',
        'position' => 0,
        'priority' => CompanyClientTask::PRIORITY_NORMAL,
        'sync_with_calendar' => false,
        'metadata' => [
            'chat_id' => $chat->id,
            'chat_message_id' => 111,
        ],
    ]);

    CompanyClientQuestion::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'description' => 'Unrelated question',
        'status' => CompanyClientQuestion::STATUS_OPEN,
        'board_column' => 'new',
        'position' => 0,
        'metadata' => [
            'chat_id' => 999999,
        ],
    ]);

    $response = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/chats/'.$chat->id.'/insights')
        ->assertOk()
        ->json();

    expect($response['client']['id'])->toBe($client->id);
    expect($response['contacts'])->not->toBeEmpty();
    expect($response['history'])->toHaveCount(1);
    expect($response['history'][0]['id'])->toBe('task-'.$task->id);
    expect((int) ($response['history'][0]['chat_message_id'] ?? 0))->toBe(111);
});

test('chat api can create task from chat and link it in metadata', function () {
    [$user, $company, $token] = chatApiContext();

    $chat = Chat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'channel' => 'assistant',
        'channel_chat_id' => 'assistant-task-1',
        'channel_user_id' => 'assistant-user-task-1',
        'name' => 'Create Task Lead',
        'status' => Chat::STATUS_OPEN,
        'metadata' => [],
    ]);

    $chatMessage = ChatMessage::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'chat_id' => $chat->id,
        'sender_type' => ChatMessage::SENDER_CUSTOMER,
        'direction' => ChatMessage::DIRECTION_INBOUND,
        'status' => 'received',
        'message_type' => ChatMessage::TYPE_TEXT,
        'text' => 'Need a callback',
        'sent_at' => now()->subMinute(),
    ]);

    $response = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/chats/'.$chat->id.'/tasks', [
            'description' => 'Create task from info panel',
            'chat_message_id' => $chatMessage->id,
        ])
        ->assertCreated()
        ->json();

    expect($response['task']['type'])->toBe('task');
    expect((int) ($response['task']['chat_message_id'] ?? 0))->toBe($chatMessage->id);

    $this->assertDatabaseHas('company_client_tasks', [
        'company_id' => $company->id,
        'description' => 'Create task from info panel',
    ]);

    $task = CompanyClientTask::query()
        ->where('company_id', $company->id)
        ->where('description', 'Create task from info panel')
        ->latest('id')
        ->first();

    expect($task)->not->toBeNull();
    expect((int) data_get($task?->metadata, 'chat_id'))->toBe($chat->id);
    expect((int) data_get($task?->metadata, 'chat_message_id'))->toBe($chatMessage->id);
});

test('chat api rejects task creation when chat_message_id does not belong to chat', function () {
    [$user, $company, $token] = chatApiContext();

    $chat = Chat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'channel' => 'assistant',
        'channel_chat_id' => 'assistant-task-invalid-1',
        'channel_user_id' => 'assistant-user-task-invalid-1',
        'name' => 'Create Task Lead Invalid',
        'status' => Chat::STATUS_OPEN,
        'metadata' => [],
    ]);

    $otherChat = Chat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'channel' => 'assistant',
        'channel_chat_id' => 'assistant-task-invalid-2',
        'channel_user_id' => 'assistant-user-task-invalid-2',
        'name' => 'Other chat',
        'status' => Chat::STATUS_OPEN,
        'metadata' => [],
    ]);

    $foreignMessage = ChatMessage::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'chat_id' => $otherChat->id,
        'sender_type' => ChatMessage::SENDER_CUSTOMER,
        'direction' => ChatMessage::DIRECTION_INBOUND,
        'status' => 'received',
        'message_type' => ChatMessage::TYPE_TEXT,
        'text' => 'Foreign message',
        'sent_at' => now()->subMinute(),
    ]);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/chats/'.$chat->id.'/tasks', [
            'description' => 'Should fail',
            'chat_message_id' => $foreignMessage->id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'chat_message_id is invalid for this chat.');
});

test('chat api can create order from chat and link it in metadata', function () {
    [$user, $company, $token] = chatApiContext();

    $chat = Chat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'channel' => 'assistant',
        'channel_chat_id' => 'assistant-order-1',
        'channel_user_id' => 'assistant-user-order-1',
        'name' => 'Create Order Lead',
        'status' => Chat::STATUS_OPEN,
        'metadata' => [],
    ]);

    $chatMessage = ChatMessage::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'chat_id' => $chat->id,
        'sender_type' => ChatMessage::SENDER_CUSTOMER,
        'direction' => ChatMessage::DIRECTION_INBOUND,
        'status' => 'received',
        'message_type' => ChatMessage::TYPE_TEXT,
        'text' => 'Need order',
        'sent_at' => now()->subMinute(),
    ]);

    $response = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/chats/'.$chat->id.'/orders', [
            'phone' => '+992900111222',
            'service_name' => 'Premium consultation',
            'address' => 'Dushanbe',
            'amount' => 120.50,
            'note' => 'Call before arrival',
            'chat_message_id' => $chatMessage->id,
        ])
        ->assertCreated()
        ->json();

    expect($response['order']['type'])->toBe('order');
    expect((int) ($response['order']['chat_message_id'] ?? 0))->toBe($chatMessage->id);

    $this->assertDatabaseHas('company_client_orders', [
        'company_id' => $company->id,
        'service_name' => 'Premium consultation',
        'currency' => 'TJS',
    ]);

    $order = CompanyClientOrder::query()
        ->where('company_id', $company->id)
        ->where('service_name', 'Premium consultation')
        ->latest('id')
        ->first();

    expect($order)->not->toBeNull();
    expect((int) data_get($order?->metadata, 'chat_id'))->toBe($chat->id);
    expect((int) data_get($order?->metadata, 'chat_message_id'))->toBe($chatMessage->id);
});

test('chat api can create order with appointment booking when company supports appointments', function () {
    [$user, $company, $token] = chatApiContext();

    $company->forceFill([
        'settings' => [
            'account_type' => 'with_appointments',
            'business' => [
                'timezone' => 'Asia/Dushanbe',
            ],
            'appointment' => [
                'enabled' => true,
            ],
        ],
    ])->save();

    $chat = Chat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'channel' => 'assistant',
        'channel_chat_id' => 'assistant-order-booking-1',
        'channel_user_id' => 'assistant-user-order-booking-1',
        'name' => 'Booking Lead',
        'status' => Chat::STATUS_OPEN,
        'metadata' => [],
    ]);

    $response = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/chats/'.$chat->id.'/orders', [
            'phone' => '+992901112233',
            'service_name' => 'On-site consultation',
            'address' => 'Dushanbe, Rudaki ave 1',
            'book_appointment' => true,
            'appointment_date' => '2026-02-20',
            'appointment_time' => '14:30',
            'appointment_duration_minutes' => 60,
        ])
        ->assertCreated()
        ->json();

    $orderId = (int) ($response['order']['record_id'] ?? 0);
    expect($orderId)->toBeGreaterThan(0);

    $order = CompanyClientOrder::query()->findOrFail($orderId);
    $eventId = (int) data_get($order->metadata, 'appointment.calendar_event_id', 0);
    expect($eventId)->toBeGreaterThan(0);

    $event = CompanyCalendarEvent::query()->findOrFail($eventId);
    expect((int) $event->company_id)->toBe((int) $company->id);
    expect((int) $event->company_client_id)->toBe((int) $order->company_client_id);
    expect($event->timezone)->toBe('Asia/Dushanbe');
    expect($event->starts_at)->not->toBeNull();
    expect($event->ends_at)->not->toBeNull();
    expect($event->starts_at->copy()->timezone('Asia/Dushanbe')->format('Y-m-d H:i'))->toBe('2026-02-20 14:30');
    expect($event->ends_at->copy()->timezone('Asia/Dushanbe')->format('Y-m-d H:i'))->toBe('2026-02-20 15:30');
    expect((int) data_get($event->metadata, 'order_id'))->toBe((int) $order->id);
});

test('chat api rejects appointment booking when company appointments are disabled', function () {
    [$user, $company, $token] = chatApiContext();

    $chat = Chat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'channel' => 'assistant',
        'channel_chat_id' => 'assistant-order-booking-disabled-1',
        'channel_user_id' => 'assistant-user-order-booking-disabled-1',
        'name' => 'Booking Disabled Lead',
        'status' => Chat::STATUS_OPEN,
        'metadata' => [],
    ]);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/chats/'.$chat->id.'/orders', [
            'phone' => '+992901112244',
            'service_name' => 'On-site consultation',
            'address' => 'Dushanbe',
            'book_appointment' => true,
            'appointment_date' => '2026-02-20',
            'appointment_time' => '14:30',
            'appointment_duration_minutes' => 60,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Appointments are disabled for this company.');

    expect(CompanyCalendarEvent::query()->count())->toBe(0);
});
