<?php

use App\Models\Assistant;
use App\Models\Chat;
use App\Models\Company;
use App\Models\CompanyClient;
use App\Models\CompanyClientQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function clientQuestionsApiContext(): array
{
    $user = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $company = Company::query()->create([
        'user_id' => $user->id,
        'name' => 'Client Questions Company',
        'slug' => 'client-questions-company-'.$user->id,
        'status' => Company::STATUS_ACTIVE,
    ]);

    $token = $user->createToken('frontend')->plainTextToken;

    return [$user, $company, $token];
}

test('client questions api lists, updates and archives questions', function () {
    [$user, $company, $token] = clientQuestionsApiContext();

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Q Assistant',
        'is_active' => true,
    ]);

    $client = CompanyClient::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Question Client',
        'phone' => '+992900123400',
        'status' => CompanyClient::STATUS_ACTIVE,
    ]);

    $chat = Chat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'assistant_id' => $assistant->id,
        'channel' => 'assistant',
        'channel_chat_id' => 'question-chat-1',
        'channel_user_id' => 'question-chat-user-1',
        'name' => 'Question Chat',
        'status' => Chat::STATUS_OPEN,
        'metadata' => [
            'company_client_id' => $client->id,
        ],
    ]);

    $question = CompanyClientQuestion::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'assistant_id' => $assistant->id,
        'description' => 'Client asked about unavailable service details',
        'status' => CompanyClientQuestion::STATUS_OPEN,
        'board_column' => 'new',
        'metadata' => [
            'source' => 'assistant_crm_action',
            'chat_id' => $chat->id,
            'source_channel' => 'assistant',
        ],
    ]);

    $listResponse = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/client-questions')
        ->assertOk()
        ->json();

    expect($listResponse['questions'])->toHaveCount(1);
    expect($listResponse['questions'][0]['id'])->toBe($question->id);
    expect($listResponse['questions'][0]['board'])->toBe('new');
    expect($listResponse['questions'][0]['source_chat_id'])->toBe($chat->id);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/client-questions/'.$question->id, [
            'description' => 'Updated question description for manager.',
            'board' => 'in_progress',
        ])
        ->assertOk()
        ->assertJsonPath('question.board', 'in_progress')
        ->assertJsonPath('question.status', CompanyClientQuestion::STATUS_IN_PROGRESS);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/client-questions/'.$question->id, [
            'board' => 'completed',
        ])
        ->assertOk()
        ->assertJsonPath('question.board', 'completed')
        ->assertJsonPath('question.status', CompanyClientQuestion::STATUS_ANSWERED);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson('/api/client-questions/'.$question->id)
        ->assertOk();

    $afterDelete = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/client-questions')
        ->assertOk()
        ->json();

    expect($afterDelete['questions'])->toHaveCount(0);
});

test('client questions api blocks multiple active cards for one chat', function () {
    [$user, $company, $token] = clientQuestionsApiContext();

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Q Assistant 2',
        'is_active' => true,
    ]);

    $client = CompanyClient::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Question Client 2',
        'phone' => '+992900777111',
        'status' => CompanyClient::STATUS_ACTIVE,
    ]);

    $chat = Chat::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'assistant_id' => $assistant->id,
        'channel' => 'assistant',
        'channel_chat_id' => 'question-chat-2',
        'channel_user_id' => 'question-chat-user-2',
        'name' => 'Question Chat 2',
        'status' => Chat::STATUS_OPEN,
        'metadata' => [
            'company_client_id' => $client->id,
        ],
    ]);

    CompanyClientQuestion::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'assistant_id' => $assistant->id,
        'description' => 'Active question card',
        'status' => CompanyClientQuestion::STATUS_OPEN,
        'board_column' => 'new',
        'metadata' => [
            'chat_id' => $chat->id,
        ],
    ]);

    $completedQuestion = CompanyClientQuestion::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'assistant_id' => $assistant->id,
        'description' => 'Old completed card',
        'status' => CompanyClientQuestion::STATUS_ANSWERED,
        'board_column' => 'completed',
        'metadata' => [
            'chat_id' => $chat->id,
        ],
    ]);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/client-questions/'.$completedQuestion->id, [
            'board' => 'in_progress',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Only one active question is allowed per chat.');
});
