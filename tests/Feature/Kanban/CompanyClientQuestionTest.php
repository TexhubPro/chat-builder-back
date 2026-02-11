<?php

use App\Models\Assistant;
use App\Models\Company;
use App\Models\CompanyClient;
use App\Models\CompanyClientQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('question card is independent and not linked with calendar', function () {
    $user = User::factory()->create();

    $company = Company::query()->create([
        'user_id' => $user->id,
        'name' => 'Kanban Questions Company',
        'slug' => 'kanban-questions-company',
        'status' => Company::STATUS_ACTIVE,
    ]);

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Kanban Assistant',
    ]);

    $client = CompanyClient::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Client Question',
        'phone' => '+992900000111',
    ]);

    $question = CompanyClientQuestion::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'assistant_id' => $assistant->id,
        'description' => 'Client asks about package details',
    ])->fresh();

    expect($question->status)->toBe(CompanyClientQuestion::STATUS_OPEN);
    expect($question->board_column)->toBe('new');
    expect(Schema::hasColumn('company_client_questions', 'company_calendar_event_id'))->toBeFalse();
    expect(Schema::hasColumn('company_client_questions', 'sync_with_calendar'))->toBeFalse();
});
