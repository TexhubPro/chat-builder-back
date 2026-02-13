<?php

use App\Models\Assistant;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\CompanyClient;
use App\Models\CompanyClientOrder;
use App\Models\CompanyClientTask;
use App\Models\CompanyClientQuestion;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
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
