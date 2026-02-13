<?php

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use TexHub\OpenAi\Assistant as OpenAiAssistantClient;

uses(Tests\TestCase::class);

test('openai assistant client normalizes numeric assistant defaults on create', function (): void {
    $client = new OpenAiAssistantClient([
        'api_key' => 'test-key',
        'base_url' => 'https://api.openai.com/v1',
        'defaults' => [
            'model' => 'gpt-4o',
            'temperature' => '1',
            'top_p' => '1',
            'response_format' => ' auto ',
        ],
    ]);

    Http::fake([
        'https://api.openai.com/v1/assistants' => Http::response([
            'id' => 'asst_test_1',
        ], 200),
    ]);

    $assistantId = $client->createAssistant([
        'name' => 'Payload Normalizer',
        'instructions' => 'Test',
    ]);

    expect($assistantId)->toBe('asst_test_1');

    Http::assertSent(function (HttpRequest $request): bool {
        $data = $request->data();

        return $request->url() === 'https://api.openai.com/v1/assistants'
            && is_array($data)
            && isset($data['temperature'])
            && is_float($data['temperature'])
            && $data['temperature'] === 1.0
            && isset($data['top_p'])
            && is_float($data['top_p'])
            && $data['top_p'] === 1.0
            && ($data['response_format'] ?? null) === 'auto';
    });
});

test('openai assistant client removes invalid strict fields on update', function (): void {
    $client = new OpenAiAssistantClient([
        'api_key' => 'test-key',
        'base_url' => 'https://api.openai.com/v1',
    ]);

    Http::fake([
        'https://api.openai.com/v1/assistants/asst_test_2' => Http::response([
            'id' => 'asst_test_2',
        ], 200),
    ]);

    $updated = $client->updateAssistant('asst_test_2', [
        'instructions' => 'Updated instructions',
        'temperature' => 'not-a-number',
        'top_p' => 'nan',
        'response_format' => 123,
    ]);

    expect($updated)->toBeTrue();

    Http::assertSent(function (HttpRequest $request): bool {
        $data = $request->data();

        return $request->url() === 'https://api.openai.com/v1/assistants/asst_test_2'
            && is_array($data)
            && ($data['instructions'] ?? null) === 'Updated instructions'
            && ! array_key_exists('temperature', $data)
            && ! array_key_exists('top_p', $data)
            && ! array_key_exists('response_format', $data);
    });
});
