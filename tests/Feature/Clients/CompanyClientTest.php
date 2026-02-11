<?php

use App\Models\Company;
use App\Models\CompanyClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('company client can be created and linked to its company', function () {
    $user = User::factory()->create();

    $company = Company::query()->create([
        'user_id' => $user->id,
        'name' => 'Client Base Company',
        'slug' => 'client-base-company',
        'status' => Company::STATUS_ACTIVE,
    ]);

    $client = CompanyClient::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Ali Client',
        'phone' => '+992900000001',
        'email' => 'ali.client@example.com',
        'notes' => 'VIP client',
        'status' => CompanyClient::STATUS_ACTIVE,
    ]);

    $this->assertDatabaseHas('company_clients', [
        'id' => $client->id,
        'company_id' => $company->id,
        'name' => 'Ali Client',
        'phone' => '+992900000001',
    ]);

    expect($company->clients()->count())->toBe(1);
    expect($user->companyClients()->count())->toBe(1);
});
