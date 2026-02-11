<?php

use App\Models\Assistant;
use App\Models\AssistantInstructionFile;
use App\Models\AssistantProduct;
use App\Models\AssistantService;
use App\Models\Company;
use App\Models\User;
use App\Services\OpenAiAssistantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use TexHub\OpenAi\Assistant as OpenAiAssistantClient;

uses(RefreshDatabase::class);

test('openai assistant service composes structured instructions with catalog and settings', function () {
    config()->set('openai.assistant.api_key', 'test-openai-key');
    config()->set('openai.assistant.base_instructions', 'BASE_INSTRUCTIONS_BLOCK');
    config()->set('openai.assistant.base_limits', 'BASE_LIMITS_BLOCK');
    config()->set('openai.assistant.defaults.model', 'gpt-4o');
    config()->set('openai.assistant.defaults.temperature', 0.8);
    config()->set('openai.assistant.defaults.top_p', 0.9);

    $user = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $company = Company::query()->create([
        'user_id' => $user->id,
        'name' => 'Service Test Company',
        'slug' => 'service-test-company-'.$user->id,
        'status' => Company::STATUS_ACTIVE,
    ]);

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Catalog Assistant',
        'openai_vector_store_id' => 'vs_existing_1',
        'instructions' => 'Help users choose the best option.',
        'restrictions' => 'Do not guarantee unavailable items.',
        'conversation_tone' => Assistant::TONE_FRIENDLY,
        'enable_file_search' => true,
        'enable_file_analysis' => true,
        'enable_voice' => true,
        'enable_web_search' => false,
        'settings' => [
            'triggers' => [
                [
                    'trigger' => 'price',
                    'response' => 'I will provide current pricing details.',
                ],
                [
                    'trigger' => 'delivery',
                    'response' => 'I will explain delivery options.',
                ],
            ],
        ],
    ]);

    AssistantService::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'assistant_id' => $assistant->id,
        'name' => 'Initial consultation',
        'description' => '30-minute consultation call.',
        'terms_conditions' => 'Pre-booking is required.',
        'price' => 20,
        'currency' => 'USD',
        'is_active' => true,
        'sort_order' => 1,
    ]);

    AssistantProduct::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'assistant_id' => $assistant->id,
        'name' => 'Premium package',
        'sku' => 'PREM-01',
        'description' => 'Extended support package.',
        'terms_conditions' => 'Non-refundable after activation.',
        'price' => 99,
        'currency' => 'USD',
        'stock_quantity' => 12,
        'is_unlimited_stock' => false,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $instructionFile = AssistantInstructionFile::query()->create([
        'assistant_id' => $assistant->id,
        'uploaded_by_user_id' => $user->id,
        'disk' => 'public',
        'path' => 'assistants/test/instructions/doc1.pdf',
        'original_name' => 'doc1.pdf',
        'mime_type' => 'application/pdf',
        'size' => 1024,
        'purpose' => 'instructions',
        'openai_file_id' => 'file_instruction_1',
        'metadata' => [],
    ]);

    $capturedPayload = null;

    $client = Mockery::mock(OpenAiAssistantClient::class);
    $client->shouldReceive('createVectorStore')->never();
    $client->shouldReceive('updateAssistant')->never();
    $client->shouldReceive('createVectorStoreFile')
        ->once()
        ->with(
            'vs_existing_1',
            'file_instruction_1',
            \Mockery::on(function (array $attributes) use ($assistant, $company, $instructionFile): bool {
                return ($attributes['assistant_id'] ?? null) === (string) $assistant->id
                    && ($attributes['company_id'] ?? null) === (string) $company->id
                    && ($attributes['instruction_file_id'] ?? null) === (string) $instructionFile->id;
            })
        )
        ->andReturn(['id' => 'vs_file_1']);

    $client->shouldReceive('createAssistant')
        ->once()
        ->with(\Mockery::on(function (array $payload) use (&$capturedPayload): bool {
            $capturedPayload = $payload;

            return true;
        }))
        ->andReturn('asst_structured_1');

    $service = new OpenAiAssistantService($client);
    $service->syncAssistant($assistant);

    $assistant->refresh();
    $instructionFile->refresh();

    expect($assistant->openai_assistant_id)->toBe('asst_structured_1');
    expect((string) data_get($instructionFile->metadata, 'openai_vector_store_id'))->toBe('vs_existing_1');
    expect((string) data_get($instructionFile->metadata, 'openai_vector_store_file_id'))->toBe('vs_file_1');
    expect($capturedPayload)->toBeArray();
    expect((string) data_get($capturedPayload, 'name'))->toBe('Catalog Assistant');
    expect((string) data_get($capturedPayload, 'model'))->toBe('gpt-4o');
    expect((float) data_get($capturedPayload, 'temperature'))->toBe(0.8);
    expect((float) data_get($capturedPayload, 'top_p'))->toBe(0.9);
    expect((array) data_get($capturedPayload, 'tool_resources.file_search.vector_store_ids'))->toBe(['vs_existing_1']);

    $tools = array_map(
        fn (array $tool): string => (string) ($tool['type'] ?? ''),
        (array) data_get($capturedPayload, 'tools', [])
    );
    expect($tools)->toContain('file_search');
    expect($tools)->toContain('code_interpreter');

    $instructions = (string) data_get($capturedPayload, 'instructions', '');
    $blocksInOrder = [
        'BASE_INSTRUCTIONS_BLOCK',
        'BASE_LIMITS_BLOCK',
        'Assistant context:',
        'Conversation tone:',
        'Main instructions:',
        'Trigger-response rules:',
        'Company catalog:',
        'Restrictions:',
        'Tool settings:',
    ];

    $lastPosition = -1;
    foreach ($blocksInOrder as $block) {
        $position = strpos($instructions, $block);
        expect($position)->not->toBeFalse();
        expect((int) $position)->toBeGreaterThan($lastPosition);
        $lastPosition = (int) $position;
    }

    expect($instructions)->toContain('Assistant ID: '.$assistant->id);
    expect($instructions)->toContain('Company ID: '.$company->id);
    expect($instructions)->toContain('Initial consultation');
    expect($instructions)->toContain('Premium package');
    expect($instructions)->toContain('PREM-01');
    expect($instructions)->toContain('File search: enabled');
    expect($instructions)->toContain('Voice mode: enabled');
    expect($instructions)->toContain('Web search: disabled');
});

test('openai assistant service attaches existing files to newly created vector store during update', function () {
    config()->set('openai.assistant.api_key', 'test-openai-key');
    config()->set('openai.assistant.defaults.model', 'gpt-4o');

    $user = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $company = Company::query()->create([
        'user_id' => $user->id,
        'name' => 'Vector Store Company',
        'slug' => 'vector-store-company-'.$user->id,
        'status' => Company::STATUS_ACTIVE,
    ]);

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Vector Sync Assistant',
        'openai_assistant_id' => 'asst_existing_1',
        'openai_vector_store_id' => null,
        'instructions' => 'Sync vector store files.',
        'conversation_tone' => Assistant::TONE_POLITE,
        'enable_file_search' => true,
        'enable_file_analysis' => false,
        'enable_voice' => false,
        'enable_web_search' => false,
        'settings' => [],
    ]);

    $instructionFile = AssistantInstructionFile::query()->create([
        'assistant_id' => $assistant->id,
        'uploaded_by_user_id' => $user->id,
        'disk' => 'public',
        'path' => 'assistants/test/instructions/vector.pdf',
        'original_name' => 'vector.pdf',
        'mime_type' => 'application/pdf',
        'size' => 2048,
        'purpose' => 'instructions',
        'openai_file_id' => 'file_for_vector_sync',
        'metadata' => [],
    ]);

    $capturedUpdatePayload = null;

    $client = Mockery::mock(OpenAiAssistantClient::class);
    $client->shouldReceive('createAssistant')->never();
    $client->shouldReceive('createVectorStore')
        ->once()
        ->with(Mockery::type('array'))
        ->andReturn(['id' => 'vs_generated_1']);
    $client->shouldReceive('createVectorStoreFile')
        ->once()
        ->with(
            'vs_generated_1',
            'file_for_vector_sync',
            \Mockery::on(function (array $attributes) use ($assistant, $company, $instructionFile): bool {
                return ($attributes['assistant_id'] ?? null) === (string) $assistant->id
                    && ($attributes['company_id'] ?? null) === (string) $company->id
                    && ($attributes['instruction_file_id'] ?? null) === (string) $instructionFile->id;
            })
        )
        ->andReturn(['id' => 'vs_file_generated_1']);
    $client->shouldReceive('updateAssistant')
        ->once()
        ->with(
            'asst_existing_1',
            \Mockery::on(function (array $payload) use (&$capturedUpdatePayload): bool {
                $capturedUpdatePayload = $payload;

                return true;
            })
        )
        ->andReturn(true);

    $service = new OpenAiAssistantService($client);
    $service->syncAssistant($assistant);

    $assistant->refresh();
    $instructionFile->refresh();

    expect($assistant->openai_vector_store_id)->toBe('vs_generated_1');
    expect((string) data_get($instructionFile->metadata, 'openai_vector_store_id'))->toBe('vs_generated_1');
    expect((string) data_get($instructionFile->metadata, 'openai_vector_store_file_id'))->toBe('vs_file_generated_1');
    expect((array) data_get($capturedUpdatePayload, 'tool_resources.file_search.vector_store_ids'))->toBe(['vs_generated_1']);
});

test('openai assistant service uploads missing openai file id from storage before vector store sync', function () {
    config()->set('openai.assistant.api_key', 'test-openai-key');
    config()->set('openai.assistant.defaults.model', 'gpt-4o');
    Storage::fake('public');

    $user = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $company = Company::query()->create([
        'user_id' => $user->id,
        'name' => 'Storage Sync Company',
        'slug' => 'storage-sync-company-'.$user->id,
        'status' => Company::STATUS_ACTIVE,
    ]);

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Storage Sync Assistant',
        'openai_assistant_id' => 'asst_existing_storage_1',
        'openai_vector_store_id' => 'vs_existing_storage_1',
        'instructions' => 'Sync from local storage when missing OpenAI file id.',
        'conversation_tone' => Assistant::TONE_POLITE,
        'enable_file_search' => true,
        'enable_file_analysis' => false,
        'enable_voice' => false,
        'enable_web_search' => false,
        'settings' => [],
    ]);

    $path = 'assistants/'.$assistant->id.'/instructions/missing-openai-id.txt';
    Storage::disk('public')->put($path, 'local file content for openai upload');

    $instructionFile = AssistantInstructionFile::query()->create([
        'assistant_id' => $assistant->id,
        'uploaded_by_user_id' => $user->id,
        'disk' => 'public',
        'path' => $path,
        'original_name' => 'missing-openai-id.txt',
        'mime_type' => 'text/plain',
        'size' => 128,
        'purpose' => 'instructions',
        'openai_file_id' => null,
        'metadata' => [],
    ]);

    $capturedUpdatePayload = null;

    $client = Mockery::mock(OpenAiAssistantClient::class);
    $client->shouldReceive('createAssistant')->never();
    $client->shouldReceive('createVectorStore')->never();
    $client->shouldReceive('uploadFile')
        ->once()
        ->with(Mockery::on(fn (string $absolutePath): bool => str_contains($absolutePath, 'missing-openai-id.txt')), 'assistants')
        ->andReturn('file_uploaded_from_storage_1');
    $client->shouldReceive('createVectorStoreFile')
        ->once()
        ->with(
            'vs_existing_storage_1',
            'file_uploaded_from_storage_1',
            \Mockery::on(function (array $attributes) use ($assistant, $company, $instructionFile): bool {
                return ($attributes['assistant_id'] ?? null) === (string) $assistant->id
                    && ($attributes['company_id'] ?? null) === (string) $company->id
                    && ($attributes['instruction_file_id'] ?? null) === (string) $instructionFile->id;
            })
        )
        ->andReturn(['id' => 'vs_file_from_storage_1']);
    $client->shouldReceive('updateAssistant')
        ->once()
        ->with(
            'asst_existing_storage_1',
            \Mockery::on(function (array $payload) use (&$capturedUpdatePayload): bool {
                $capturedUpdatePayload = $payload;

                return true;
            })
        )
        ->andReturn(true);

    $service = new OpenAiAssistantService($client);
    $service->syncAssistant($assistant);

    $assistant->refresh();
    $instructionFile->refresh();

    expect($instructionFile->openai_file_id)->toBe('file_uploaded_from_storage_1');
    expect((string) data_get($instructionFile->metadata, 'openai_vector_store_id'))->toBe('vs_existing_storage_1');
    expect((string) data_get($instructionFile->metadata, 'openai_vector_store_file_id'))->toBe('vs_file_from_storage_1');
    expect((array) data_get($capturedUpdatePayload, 'tool_resources.file_search.vector_store_ids'))->toBe(['vs_existing_storage_1']);
});
