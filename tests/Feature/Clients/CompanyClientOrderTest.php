<?php

use App\Models\Assistant;
use App\Models\AssistantService;
use App\Models\Company;
use App\Models\CompanyClient;
use App\Models\CompanyClientOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('company client order history stores service quantity date and totals', function () {
    $user = User::factory()->create();

    $company = Company::query()->create([
        'user_id' => $user->id,
        'name' => 'Order History Company',
        'slug' => 'order-history-company',
        'status' => Company::STATUS_ACTIVE,
    ]);

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Order Assistant',
    ]);

    $service = AssistantService::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'assistant_id' => $assistant->id,
        'name' => 'Haircut',
        'price' => 50,
        'currency' => 'TJS',
    ]);

    $client = CompanyClient::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'John Buyer',
        'phone' => '+992900000002',
    ]);

    $order = CompanyClientOrder::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'company_client_id' => $client->id,
        'assistant_id' => $assistant->id,
        'assistant_service_id' => $service->id,
        'service_name' => 'Haircut',
        'quantity' => 2,
        'unit_price' => 50,
        'total_price' => 100,
        'currency' => 'TJS',
        'ordered_at' => now(),
    ]);

    $this->assertDatabaseHas('company_client_orders', [
        'id' => $order->id,
        'company_client_id' => $client->id,
        'assistant_service_id' => $service->id,
        'quantity' => 2,
        'total_price' => 100,
    ]);

    expect($client->orders()->count())->toBe(1);
    expect($assistant->clientOrders()->count())->toBe(1);
    expect($service->clientOrders()->count())->toBe(1);
});
