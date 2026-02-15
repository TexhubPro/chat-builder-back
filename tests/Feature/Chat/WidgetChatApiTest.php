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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use TexHub\OpenAi\Assistant as OpenAiAssistantClient;

uses(RefreshDatabase::class);

function widgetContext(string $subscriptionStatus = CompanySubscription::STATUS_ACTIVE): array
{
    $user = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $company = Company::query()->create([
        'user_id' => $user->id,
        'name' => 'Widget Company',
        'slug' => 'widget-company-'.$user->id,
        'status' => Company::STATUS_ACTIVE,
    ]);

    $plan = SubscriptionPlan::query()->create([
        'code' => 'widget-plan-'.$user->id,
        'name' => 'Widget Plan',
        'is_active' => true,
        'is_public' => true,
        'billing_period_days' => 30,
        'currency' => 'USD',
        'price' => 19,
        'included_chats' => 20,
        'overage_chat_price' => 1,
        'assistant_limit' => 3,
        'integrations_per_channel_limit' => 4,
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
        'name' => 'Widget Assistant',
        'openai_assistant_id' => 'asst_widget_1',
        'is_active' => true,
    ]);

    $token = $user->createToken('frontend')->plainTextToken;

    return [$user, $company, $assistant, $token];
}

test('widget settings endpoint returns embed script and saves updates', function () {
    [, $company, $assistant, $token] = widgetContext();

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/assistant-channels/'.$assistant->id.'/widget', [
            'enabled' => true,
        ])
        ->assertOk()
        ->assertJsonPath('channel.channel', 'widget')
        ->assertJsonPath('channel.is_active', true);

    $settingsResponse = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/assistant-channels/'.$assistant->id.'/widget/settings')
        ->assertOk()
        ->json();

    expect($settingsResponse['widget']['widget_key'])->toBeString()->not->toBe('');
    expect($settingsResponse['widget']['embed_script_tag'])->toContain('data-widget-key');

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/assistant-channels/'.$assistant->id.'/widget/settings', [
            'position' => 'bottom-left',
            'theme' => 'dark',
            'primary_color' => '#22AAEE',
            'title' => 'Support Chat',
            'welcome_message' => 'Welcome to our website',
            'placeholder' => 'Write here',
            'launcher_label' => 'Help',
        ])
        ->assertOk()
        ->assertJsonPath('widget.settings.position', 'bottom-left')
        ->assertJsonPath('widget.settings.theme', 'dark')
        ->assertJsonPath('widget.settings.primary_color', '#22AAEE')
        ->assertJsonPath('widget.settings.launcher_label', 'Help');

    $channel = AssistantChannel::query()
        ->where('company_id', $company->id)
        ->where('assistant_id', $assistant->id)
        ->where('channel', AssistantChannel::CHANNEL_WIDGET)
        ->firstOrFail();

    $settings = is_array($channel->settings) ? $channel->settings : [];

    expect($settings['position'] ?? null)->toBe('bottom-left');
    expect($settings['theme'] ?? null)->toBe('dark');
    expect($settings['primary_color'] ?? null)->toBe('#22AAEE');
});

test('widget public endpoint creates chat and assistant reply', function () {
    [, $company, $assistant] = widgetContext();

    config()->set('openai.assistant.api_key', 'test-openai-key');
    config()->set('chats.widget.auto_reply_enabled', true);

    $assistantChannel = AssistantChannel::query()->create([
        'user_id' => $company->user_id,
        'company_id' => $company->id,
        'assistant_id' => $assistant->id,
        'channel' => AssistantChannel::CHANNEL_WIDGET,
        'name' => 'Web Widget',
        'external_account_id' => 'widget-abc',
        'is_active' => true,
        'credentials' => [
            'provider' => 'widget',
            'widget_key' => 'wdg_test_key_1',
        ],
    ]);

    $this->mock(OpenAiAssistantClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('createThread')->once()->andReturn('thread_widget_1');
        $mock->shouldReceive('sendTextMessage')->once()->andReturn('msg_widget_1');
        $mock->shouldReceive('runThreadAndGetResponse')->once()->andReturn('Здравствуйте! Чем помочь?');
    });

    $response = $this
        ->postJson('/api/widget/wdg_test_key_1/messages', [
            'session_id' => 'session-101',
            'text' => 'салом',
            'visitor_name' => 'Mahmud',
            'page_url' => 'https://example.com/contact',
        ])
        ->assertCreated()
        ->json();

    expect($response['chat']['channel'])->toBe('widget');
    expect($response['assistant_message']['text'])->toBe('Здравствуйте! Чем помочь?');

    $chat = Chat::query()
        ->where('company_id', $company->id)
        ->where('channel', AssistantChannel::CHANNEL_WIDGET)
        ->where('channel_chat_id', 'session-101')
        ->firstOrFail();

    expect((int) $chat->assistant_channel_id)->toBe((int) $assistantChannel->id);
    expect($chat->messages()->count())->toBe(2);

    $this->assertDatabaseHas('chat_messages', [
        'chat_id' => $chat->id,
        'sender_type' => ChatMessage::SENDER_CUSTOMER,
        'direction' => ChatMessage::DIRECTION_INBOUND,
        'message_type' => ChatMessage::TYPE_TEXT,
        'text' => 'салом',
    ]);

    $this->assertDatabaseHas('chat_messages', [
        'chat_id' => $chat->id,
        'sender_type' => ChatMessage::SENDER_ASSISTANT,
        'direction' => ChatMessage::DIRECTION_OUTBOUND,
        'message_type' => ChatMessage::TYPE_TEXT,
        'text' => 'Здравствуйте! Чем помочь?',
    ]);

    $subscription = $company->subscription()->firstOrFail()->refresh();
    expect((int) $subscription->chat_count_current_period)->toBe(1);
});

test('widget public endpoint appends repeated inbound messages into same chat', function () {
    [, $company, $assistant] = widgetContext();

    config()->set('chats.widget.auto_reply_enabled', false);

    AssistantChannel::query()->create([
        'user_id' => $company->user_id,
        'company_id' => $company->id,
        'assistant_id' => $assistant->id,
        'channel' => AssistantChannel::CHANNEL_WIDGET,
        'name' => 'Web Widget',
        'external_account_id' => 'widget-def',
        'is_active' => true,
        'credentials' => [
            'provider' => 'widget',
            'widget_key' => 'wdg_test_key_2',
        ],
    ]);

    $this
        ->postJson('/api/widget/wdg_test_key_2/messages', [
            'session_id' => 'session-repeat-1',
            'text' => 'hello',
        ])
        ->assertCreated();

    $this
        ->postJson('/api/widget/wdg_test_key_2/messages', [
            'session_id' => 'session-repeat-1',
            'text' => 'second message',
        ])
        ->assertCreated();

    $chat = Chat::query()
        ->where('company_id', $company->id)
        ->where('channel', AssistantChannel::CHANNEL_WIDGET)
        ->firstOrFail();

    expect(Chat::query()
        ->where('company_id', $company->id)
        ->where('channel', AssistantChannel::CHANNEL_WIDGET)
        ->count())->toBe(1);

    expect($chat->messages()
        ->where('sender_type', ChatMessage::SENDER_CUSTOMER)
        ->count())->toBe(2);

    expect($chat->messages()
        ->where('sender_type', ChatMessage::SENDER_ASSISTANT)
        ->count())->toBe(0);
});

test('widget public endpoint accepts image uploads', function () {
    Storage::fake('public');

    [, $company, $assistant] = widgetContext();

    config()->set('chats.widget.auto_reply_enabled', false);

    AssistantChannel::query()->create([
        'user_id' => $company->user_id,
        'company_id' => $company->id,
        'assistant_id' => $assistant->id,
        'channel' => AssistantChannel::CHANNEL_WIDGET,
        'name' => 'Web Widget',
        'external_account_id' => 'widget-photo',
        'is_active' => true,
        'credentials' => [
            'provider' => 'widget',
            'widget_key' => 'wdg_test_key_3',
        ],
    ]);

    $response = $this
        ->post('/api/widget/wdg_test_key_3/messages', [
            'session_id' => 'session-image-1',
            'file' => UploadedFile::fake()->image('customer-photo.jpg'),
        ]);

    $response->assertCreated();

    $chat = Chat::query()
        ->where('company_id', $company->id)
        ->where('channel', AssistantChannel::CHANNEL_WIDGET)
        ->where('channel_chat_id', 'session-image-1')
        ->firstOrFail();

    $message = $chat->messages()
        ->where('sender_type', ChatMessage::SENDER_CUSTOMER)
        ->firstOrFail();

    expect($message->message_type)->toBe(ChatMessage::TYPE_IMAGE);
    expect((string) $message->media_url)->toContain('/storage/chat-files/widget/');

    $attachments = is_array($message->attachments) ? $message->attachments : [];
    $storagePath = (string) ($attachments[0]['storage_path'] ?? '');

    expect($storagePath)->not->toBe('');
    Storage::disk('public')->assertExists($storagePath);
});
