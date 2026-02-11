<?php

use App\Models\Assistant;
use App\Models\AssistantService;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('assistant service can be created with company and photos', function () {
    $user = User::factory()->create();

    $company = Company::query()->create([
        'user_id' => $user->id,
        'name' => 'Clinic Company',
        'slug' => 'clinic-company',
        'status' => Company::STATUS_ACTIVE,
    ]);

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Clinic Assistant',
        'is_active' => true,
    ]);

    $service = AssistantService::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'assistant_id' => $assistant->id,
        'name' => 'Consultation',
        'description' => 'Initial doctor consultation',
        'terms_conditions' => 'By appointment only',
        'price' => 150.50,
        'currency' => 'TJS',
        'photo_urls' => [
            'https://example.com/service-1.jpg',
            'https://example.com/service-2.jpg',
        ],
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $this->assertDatabaseHas('assistant_services', [
        'id' => $service->id,
        'assistant_id' => $assistant->id,
        'name' => 'Consultation',
        'currency' => 'TJS',
    ]);

    expect($assistant->services()->count())->toBe(1);
    expect($service->photo_urls)->toHaveCount(2);
});
