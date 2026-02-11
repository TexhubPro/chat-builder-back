<?php

use App\Models\Assistant;
use App\Models\AssistantInstructionFile;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\OpenAiAssistantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function assistantApiContext(int $assistantLimit = 2, bool $activeSubscription = true): array
{
    config()->set('openai.assistant.api_key', null);

    $user = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $company = Company::query()->create([
        'user_id' => $user->id,
        'name' => 'Assistant API Company',
        'slug' => 'assistant-api-company-'.$user->id,
        'status' => Company::STATUS_ACTIVE,
    ]);

    $plan = SubscriptionPlan::query()->create([
        'code' => 'assistant-api-plan-'.$user->id,
        'name' => 'Assistant API Plan',
        'is_active' => true,
        'is_public' => true,
        'billing_period_days' => 30,
        'currency' => 'USD',
        'price' => 25,
        'included_chats' => 300,
        'overage_chat_price' => 1,
        'assistant_limit' => $assistantLimit,
        'integrations_per_channel_limit' => 1,
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
        'chat_period_started_at' => now()->subDay(),
        'chat_period_ends_at' => now()->addDays(29),
    ]);

    $token = $user->createToken('frontend')->plainTextToken;

    return [$user, $company, $token];
}

test('assistant api returns limits and creates assistant inside plan limit', function () {
    [, $company, $token] = assistantApiContext(2, true);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/assistants')
        ->assertOk()
        ->assertJsonPath('limits.assistant_limit', 2)
        ->assertJsonPath('limits.current_assistants', 0);

    $response = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/assistants', [
            'name' => 'Sales Assistant',
            'instructions' => 'Handle incoming sales questions.',
            'restrictions' => 'Do not provide legal advice.',
            'conversation_tone' => 'friendly',
            'enable_file_search' => true,
            'enable_file_analysis' => true,
            'enable_voice' => false,
            'enable_web_search' => false,
            'settings' => [
                'triggers' => [
                    [
                        'trigger' => 'price',
                        'response' => 'Please check our pricing page.',
                    ],
                ],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('assistant.name', 'Sales Assistant')
        ->assertJsonPath('assistant.conversation_tone', 'friendly')
        ->assertJsonPath('assistant.is_active', false)
        ->json();

    $assistantId = (int) data_get($response, 'assistant.id');

    $this->assertDatabaseHas('assistants', [
        'id' => $assistantId,
        'company_id' => $company->id,
        'name' => 'Sales Assistant',
    ]);
});

test('assistant api blocks creation when assistant limit is reached', function () {
    [$user, $company, $token] = assistantApiContext(1, true);

    Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Primary Assistant',
        'is_active' => true,
    ]);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/assistants', [
            'name' => 'Second Assistant',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Assistant limit reached for current subscription.');
});

test('assistant api stores openai assistant id when new assistant is synced', function () {
    [, $company, $token] = assistantApiContext(2, true);
    config()->set('openai.assistant.api_key', 'test-openai-key');

    $this->mock(OpenAiAssistantService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('isConfigured')->once()->andReturnTrue();
        $mock->shouldReceive('syncAssistant')->once()->andReturnUsing(
            function (Assistant $assistant): void {
                $assistant->forceFill([
                    'openai_assistant_id' => 'asst_created_sync_id',
                ])->save();
            }
        );
    });

    $response = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/assistants', [
            'name' => 'OpenAI Synced Assistant',
        ])
        ->assertCreated()
        ->assertJsonPath('assistant.openai_assistant_id', 'asst_created_sync_id')
        ->json();

    $assistantId = (int) data_get($response, 'assistant.id');
    $this->assertDatabaseHas('assistants', [
        'id' => $assistantId,
        'company_id' => $company->id,
        'openai_assistant_id' => 'asst_created_sync_id',
    ]);
});

test('assistant api starts and stops assistants with limit check', function () {
    [$user, $company, $token] = assistantApiContext(1, true);

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Starter Assistant',
        'is_active' => false,
    ]);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/assistants/'.$assistant->id.'/start')
        ->assertOk()
        ->assertJsonPath('assistant.is_active', true);

    $secondAssistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Second Assistant',
        'is_active' => false,
    ]);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/assistants/'.$secondAssistant->id.'/start')
        ->assertStatus(422)
        ->assertJsonPath('message', 'Assistant limit reached for current subscription.');

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/assistants/'.$assistant->id.'/stop')
        ->assertOk()
        ->assertJsonPath('assistant.is_active', false);
});

test('assistant api uploads and deletes instruction files', function () {
    Storage::fake('public');

    [$user, $company, $token] = assistantApiContext(2, true);

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Knowledge Assistant',
        'is_active' => false,
    ]);

    $uploadResponse = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->post('/api/assistants/'.$assistant->id.'/instruction-files', [
            'files' => [
                UploadedFile::fake()->create('guide.pdf', 120, 'application/pdf'),
            ],
        ])
        ->assertCreated()
        ->json();

    $fileId = (int) data_get($uploadResponse, 'files.0.id');
    $storedPath = AssistantInstructionFile::query()->findOrFail($fileId)->path;

    Storage::disk('public')->assertExists($storedPath);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson('/api/assistants/'.$assistant->id.'/instruction-files/'.$fileId)
        ->assertOk();

    $this->assertDatabaseMissing('assistant_instruction_files', [
        'id' => $fileId,
    ]);
    Storage::disk('public')->assertMissing($storedPath);
});

test('assistant api rejects unsupported instruction file formats', function () {
    Storage::fake('public');

    [$user, $company, $token] = assistantApiContext(2, true);

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Knowledge Assistant',
        'is_active' => false,
    ]);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Accept', 'application/json')
        ->post('/api/assistants/'.$assistant->id.'/instruction-files', [
            'files' => [
                UploadedFile::fake()->create('contacts.csv', 50, 'text/csv'),
            ],
        ])
        ->assertStatus(422);
});

test('assistant api saves update in database but skips openai sync when subscription is inactive', function () {
    [$user, $company, $token] = assistantApiContext(2, false);
    config()->set('openai.assistant.api_key', 'test-openai-key');

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Inactive Plan Assistant',
        'is_active' => false,
    ]);

    $this->mock(OpenAiAssistantService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('isConfigured')->once()->andReturnTrue();
        $mock->shouldNotReceive('syncAssistant');
    });

    $response = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/assistants/'.$assistant->id, [
            'name' => 'Local Only Update',
            'instructions' => 'Saved to local database.',
        ])
        ->assertOk()
        ->assertJsonPath('assistant.name', 'Local Only Update')
        ->json();

    expect((string) data_get($response, 'warning'))
        ->toContain('OpenAI sync is available only with an active subscription.');

    $this->assertDatabaseHas('assistants', [
        'id' => $assistant->id,
        'name' => 'Local Only Update',
    ]);

    expect($user->fresh()->openai_assistant_updated_at)->toBeNull();
});

test('assistant api syncs first update to openai and rate limits next update for 24 hours', function () {
    [$user, $company, $token] = assistantApiContext(2, true);
    config()->set('app.env', 'production');
    config()->set('openai.assistant.api_key', 'test-openai-key');

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Rate Limited Assistant',
        'is_active' => false,
    ]);

    $this->mock(OpenAiAssistantService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('isConfigured')->twice()->andReturnTrue();
        $mock->shouldReceive('syncAssistant')->once()->andReturnUsing(
            function (Assistant $assistant): void {
                $assistant->forceFill([
                    'openai_assistant_id' => 'asst_synced_24h_limit',
                ])->save();
            }
        );
    });

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/assistants/'.$assistant->id, [
            'name' => 'First Synced Update',
            'instructions' => 'OpenAI sync should run now.',
        ])
        ->assertOk()
        ->assertJsonMissingPath('warning')
        ->assertJsonPath('assistant.openai_assistant_id', 'asst_synced_24h_limit');

    expect($user->fresh()->openai_assistant_updated_at)->not->toBeNull();

    $secondUpdateResponse = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/assistants/'.$assistant->id, [
            'name' => 'Second Local Update',
            'instructions' => 'Should stay local for 24h cooldown.',
        ])
        ->assertOk()
        ->assertJsonPath('assistant.name', 'Second Local Update')
        ->json();

    expect((string) data_get($secondUpdateResponse, 'warning'))
        ->toContain('OpenAI update is available once every 24 hours.');

    $assistant->refresh();
    expect($assistant->openai_assistant_id)->toBe('asst_synced_24h_limit');
});

test('assistant api does not enforce daily openai update limit in local environment', function () {
    [$user, $company, $token] = assistantApiContext(2, true);
    config()->set('app.env', 'local');
    config()->set('openai.assistant.api_key', 'test-openai-key');

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Local Environment Assistant',
        'is_active' => false,
    ]);

    $syncCalls = 0;

    $this->mock(OpenAiAssistantService::class, function (MockInterface $mock) use (&$syncCalls): void {
        $mock->shouldReceive('isConfigured')->twice()->andReturnTrue();
        $mock->shouldReceive('syncAssistant')->twice()->andReturnUsing(
            function (Assistant $assistant) use (&$syncCalls): void {
                $syncCalls++;
                $assistant->forceFill([
                    'openai_assistant_id' => 'asst_local_sync_'.$syncCalls,
                ])->save();
            }
        );
    });

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/assistants/'.$assistant->id, [
            'name' => 'Local update #1',
        ])
        ->assertOk()
        ->assertJsonMissingPath('warning');

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/assistants/'.$assistant->id, [
            'name' => 'Local update #2',
        ])
        ->assertOk()
        ->assertJsonMissingPath('warning');

    expect($syncCalls)->toBe(2);

    $assistant->refresh();
    expect($assistant->openai_assistant_id)->toBe('asst_local_sync_2');
});
