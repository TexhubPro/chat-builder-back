<?php

use App\Models\Assistant;
use App\Models\AssistantProduct;
use App\Models\AssistantService;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\OpenAiAssistantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function assistantCatalogApiContext(): array
{
    $user = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $company = Company::query()->create([
        'user_id' => $user->id,
        'name' => 'Catalog API Company',
        'slug' => 'catalog-api-company-'.$user->id,
        'status' => Company::STATUS_ACTIVE,
    ]);

    $plan = SubscriptionPlan::query()->create([
        'code' => 'catalog-api-plan-'.$user->id,
        'name' => 'Catalog API Plan',
        'is_active' => true,
        'is_public' => true,
        'billing_period_days' => 30,
        'currency' => 'USD',
        'price' => 30,
        'included_chats' => 400,
        'overage_chat_price' => 1,
        'assistant_limit' => 3,
        'integrations_per_channel_limit' => 2,
    ]);

    CompanySubscription::query()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'subscription_plan_id' => $plan->id,
        'status' => CompanySubscription::STATUS_ACTIVE,
        'quantity' => 1,
        'billing_cycle_days' => 30,
        'starts_at' => now()->subDay(),
        'expires_at' => now()->addDays(29),
        'renewal_due_at' => now()->addDays(29),
        'chat_period_started_at' => now()->subDay(),
        'chat_period_ends_at' => now()->addDays(29),
    ]);

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Catalog Assistant',
        'is_active' => true,
    ]);

    $token = $user->createToken('frontend')->plainTextToken;

    return [$user, $company, $assistant, $token];
}

test('assistant catalog api manages services', function () {
    [, $company, $assistant, $token] = assistantCatalogApiContext();
    config()->set('openai.assistant.api_key', 'test-openai-key');

    $this->mock(OpenAiAssistantService::class, function (MockInterface $mock) use ($assistant): void {
        $mock->shouldReceive('isConfigured')->times(3)->andReturn(true);
        $mock->shouldReceive('syncAssistant')
            ->times(3)
            ->with(\Mockery::on(fn ($value): bool => $value instanceof Assistant && (int) $value->id === (int) $assistant->id))
            ->andReturnNull();
    });

    $createResponse = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/assistant-services', [
            'assistant_id' => $assistant->id,
            'name' => 'Consultation',
            'description' => 'Online consultation service.',
            'price' => 149.99,
            'currency' => 'USD',
            'is_active' => true,
            'specialists' => [
                ['name' => 'Master 1', 'price' => 149.99],
                ['name' => 'Master 2', 'price' => 129.99],
            ],
            'photo_urls' => [
                'https://example.com/service-1.jpg',
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('service.name', 'Consultation')
        ->assertJsonPath('service.specialists.0.name', 'Master 1')
        ->json();

    $serviceId = (int) data_get($createResponse, 'service.id');

    $this->assertDatabaseHas('assistant_services', [
        'id' => $serviceId,
        'company_id' => $company->id,
        'assistant_id' => $assistant->id,
        'name' => 'Consultation',
    ]);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/assistant-services?assistant_id='.$assistant->id)
        ->assertOk()
        ->assertJsonPath('services.0.id', $serviceId);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/assistant-services/'.$serviceId, [
            'name' => 'Premium Consultation',
            'price' => 199.5,
            'is_active' => false,
            'specialists' => [
                ['name' => 'Master 1', 'price' => 199.5],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('service.name', 'Premium Consultation')
        ->assertJsonPath('service.is_active', false)
        ->assertJsonPath('service.specialists.0.price', 199.5);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson('/api/assistant-services/'.$serviceId)
        ->assertOk()
        ->assertJsonPath('message', 'Service deleted successfully.');

    $this->assertDatabaseMissing('assistant_services', [
        'id' => $serviceId,
    ]);
});

test('assistant catalog api manages products', function () {
    [, $company, $assistant, $token] = assistantCatalogApiContext();
    config()->set('openai.assistant.api_key', 'test-openai-key');

    $this->mock(OpenAiAssistantService::class, function (MockInterface $mock) use ($assistant): void {
        $mock->shouldReceive('isConfigured')->times(3)->andReturn(true);
        $mock->shouldReceive('syncAssistant')
            ->times(3)
            ->with(\Mockery::on(fn ($value): bool => $value instanceof Assistant && (int) $value->id === (int) $assistant->id))
            ->andReturnNull();
    });

    $createResponse = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/assistant-products', [
            'assistant_id' => $assistant->id,
            'name' => 'Gift Box',
            'sku' => 'GB-001',
            'description' => 'Gift box with branding.',
            'price' => 79.95,
            'currency' => 'USD',
            'is_unlimited_stock' => false,
            'stock_quantity' => 25,
            'is_active' => true,
            'product_url' => 'https://example.com/products/gift-box',
            'photo_urls' => [
                'https://example.com/product-1.jpg',
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('product.name', 'Gift Box')
        ->assertJsonPath('product.product_url', 'https://example.com/products/gift-box')
        ->json();

    $productId = (int) data_get($createResponse, 'product.id');

    $this->assertDatabaseHas('assistant_products', [
        'id' => $productId,
        'company_id' => $company->id,
        'assistant_id' => $assistant->id,
        'name' => 'Gift Box',
        'sku' => 'GB-001',
    ]);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/assistant-products?assistant_id='.$assistant->id)
        ->assertOk()
        ->assertJsonPath('products.0.id', $productId);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/assistant-products/'.$productId, [
            'name' => 'Gift Box Premium',
            'is_unlimited_stock' => true,
            'is_active' => false,
            'product_url' => 'https://example.com/products/gift-box-premium',
        ])
        ->assertOk()
        ->assertJsonPath('product.name', 'Gift Box Premium')
        ->assertJsonPath('product.is_unlimited_stock', true)
        ->assertJsonPath('product.stock_quantity', null)
        ->assertJsonPath('product.product_url', 'https://example.com/products/gift-box-premium')
        ->assertJsonPath('product.is_active', false);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson('/api/assistant-products/'.$productId)
        ->assertOk()
        ->assertJsonPath('message', 'Product deleted successfully.');

    $this->assertDatabaseMissing('assistant_products', [
        'id' => $productId,
    ]);
});

test('assistant catalog api does not allow cross company assistant access', function () {
    [, , $assistant, $token] = assistantCatalogApiContext();

    $otherUser = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $otherCompany = Company::query()->create([
        'user_id' => $otherUser->id,
        'name' => 'Other Company',
        'slug' => 'other-company-'.$otherUser->id,
        'status' => Company::STATUS_ACTIVE,
    ]);

    $otherAssistant = Assistant::query()->create([
        'user_id' => $otherUser->id,
        'company_id' => $otherCompany->id,
        'name' => 'Other Assistant',
        'is_active' => true,
    ]);

    $service = AssistantService::query()->create([
        'user_id' => $otherUser->id,
        'company_id' => $otherCompany->id,
        'assistant_id' => $otherAssistant->id,
        'name' => 'Other Service',
        'price' => 10,
        'currency' => 'USD',
    ]);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/assistant-services?assistant_id='.$otherAssistant->id)
        ->assertStatus(404);

    $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/assistant-services/'.$service->id, [
            'name' => 'Stolen update',
        ])
        ->assertStatus(404);

    expect($assistant->id)->not->toBe($otherAssistant->id);
});
