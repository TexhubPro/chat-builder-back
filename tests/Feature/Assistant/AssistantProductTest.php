<?php

use App\Models\Assistant;
use App\Models\AssistantProduct;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('assistant product can be created with price stock and photos', function () {
    $user = User::factory()->create();

    $company = Company::query()->create([
        'user_id' => $user->id,
        'name' => 'Store Company',
        'slug' => 'store-company',
        'status' => Company::STATUS_ACTIVE,
    ]);

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Store Assistant',
        'is_active' => true,
    ]);

    $product = AssistantProduct::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'assistant_id' => $assistant->id,
        'name' => 'Vitamin C',
        'sku' => 'VIT-C-1000',
        'description' => 'Vitamin C tablets',
        'terms_conditions' => 'No returns after opening',
        'price' => 89.90,
        'currency' => 'TJS',
        'stock_quantity' => 42,
        'is_unlimited_stock' => false,
        'photo_urls' => [
            'https://example.com/product-1.jpg',
            'https://example.com/product-2.jpg',
        ],
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $this->assertDatabaseHas('assistant_products', [
        'id' => $product->id,
        'assistant_id' => $assistant->id,
        'name' => 'Vitamin C',
        'sku' => 'VIT-C-1000',
        'currency' => 'TJS',
        'stock_quantity' => 42,
    ]);

    expect($assistant->products()->count())->toBe(1);
    expect($product->photo_urls)->toHaveCount(2);
});
