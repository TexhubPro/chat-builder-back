<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assistant;
use App\Models\AssistantProduct;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanySubscriptionService;
use App\Services\OpenAiAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class AssistantProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);

        $validated = $request->validate([
            'assistant_id' => ['required', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $assistant = $this->resolveAssistant($company, (int) $validated['assistant_id']);

        $query = $assistant->products()
            ->orderBy('sort_order')
            ->orderBy('id');

        if (array_key_exists('is_active', $validated)) {
            $query->where('is_active', (bool) $validated['is_active']);
        }

        $products = $query->get();

        return response()->json([
            'products' => $products
                ->map(fn (AssistantProduct $product): array => $this->payload($product))
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'assistant_id' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'sku' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'terms_conditions' => ['nullable', 'string', 'max:5000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'product_url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'stock_quantity' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'is_unlimited_stock' => ['nullable', 'boolean'],
            'photo_urls' => ['nullable', 'array', 'max:12'],
            'photo_urls.*' => ['string', 'max:2048', 'url:http,https'],
            'is_active' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $assistant = $this->resolveAssistant($company, (int) $validated['assistant_id']);
        $isUnlimitedStock = (bool) ($validated['is_unlimited_stock'] ?? true);

        $metadata = $this->normalizeMetadata($validated['metadata'] ?? null);
        $productUrl = $this->nullableTrimmed($validated['product_url'] ?? null);
        if ($productUrl !== null) {
            $metadata['product_url'] = $productUrl;
        }

        $product = AssistantProduct::query()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'assistant_id' => $assistant->id,
            'name' => trim((string) $validated['name']),
            'sku' => $this->nullableTrimmed($validated['sku'] ?? null),
            'description' => $this->nullableTrimmed($validated['description'] ?? null),
            'terms_conditions' => $this->nullableTrimmed($validated['terms_conditions'] ?? null),
            'price' => $this->normalizeMoney($validated['price'] ?? 0),
            'currency' => $this->normalizeCurrency($validated['currency'] ?? null),
            'stock_quantity' => $isUnlimitedStock
                ? null
                : $this->normalizeStock($validated['stock_quantity'] ?? 0),
            'is_unlimited_stock' => $isUnlimitedStock,
            'photo_urls' => $this->normalizePhotoUrls($validated['photo_urls'] ?? []),
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'sort_order' => 0,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);

        $this->syncAssistantCatalogToOpenAi($assistant);

        return response()->json([
            'message' => 'Product created successfully.',
            'product' => $this->payload($product),
        ], 201);
    }

    public function update(Request $request, int $productId): JsonResponse
    {
        $validated = $request->validate([
            'assistant_id' => ['sometimes', 'integer', 'min:1'],
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:160'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'terms_conditions' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'product_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'url:http,https'],
            'stock_quantity' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000000'],
            'is_unlimited_stock' => ['sometimes', 'boolean'],
            'photo_urls' => ['sometimes', 'nullable', 'array', 'max:12'],
            'photo_urls.*' => ['string', 'max:2048', 'url:http,https'],
            'is_active' => ['sometimes', 'boolean'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]);

        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $product = $this->resolveProduct($company, $productId);
        $previousAssistantId = (int) $product->assistant_id;

        if (array_key_exists('assistant_id', $validated)) {
            $assistant = $this->resolveAssistant($company, (int) $validated['assistant_id']);
            $product->assistant_id = $assistant->id;
        }

        if (array_key_exists('name', $validated)) {
            $product->name = trim((string) $validated['name']);
        }

        if (array_key_exists('sku', $validated)) {
            $product->sku = $this->nullableTrimmed($validated['sku']);
        }

        if (array_key_exists('description', $validated)) {
            $product->description = $this->nullableTrimmed($validated['description']);
        }

        if (array_key_exists('terms_conditions', $validated)) {
            $product->terms_conditions = $this->nullableTrimmed($validated['terms_conditions']);
        }

        if (array_key_exists('price', $validated)) {
            $product->price = $this->normalizeMoney($validated['price']);
        }

        if (array_key_exists('currency', $validated)) {
            $product->currency = $this->normalizeCurrency($validated['currency']);
        }

        if (array_key_exists('photo_urls', $validated)) {
            $product->photo_urls = $this->normalizePhotoUrls($validated['photo_urls'] ?? []);
        }

        if (array_key_exists('is_active', $validated)) {
            $product->is_active = (bool) $validated['is_active'];
        }

        $metadata = $this->normalizeMetadata($product->metadata);
        if (array_key_exists('metadata', $validated)) {
            $metadata = $this->normalizeMetadata($validated['metadata']);
        }

        if (array_key_exists('product_url', $validated)) {
            $productUrl = $this->nullableTrimmed($validated['product_url']);
            if ($productUrl === null) {
                unset($metadata['product_url']);
            } else {
                $metadata['product_url'] = $productUrl;
            }
        }

        $isUnlimitedStock = array_key_exists('is_unlimited_stock', $validated)
            ? (bool) $validated['is_unlimited_stock']
            : (bool) $product->is_unlimited_stock;

        $product->is_unlimited_stock = $isUnlimitedStock;

        if ($isUnlimitedStock) {
            $product->stock_quantity = null;
        } elseif (array_key_exists('stock_quantity', $validated)) {
            $product->stock_quantity = $this->normalizeStock($validated['stock_quantity']);
        }

        $product->metadata = $metadata === [] ? null : $metadata;
        $product->save();

        if ($previousAssistantId !== (int) $product->assistant_id) {
            /** @var Assistant|null $previousAssistant */
            $previousAssistant = $company->assistants()->whereKey($previousAssistantId)->first();
            if ($previousAssistant) {
                $this->syncAssistantCatalogToOpenAi($previousAssistant);
            }
        }

        /** @var Assistant|null $currentAssistant */
        $currentAssistant = $company->assistants()->whereKey((int) $product->assistant_id)->first();
        if ($currentAssistant) {
            $this->syncAssistantCatalogToOpenAi($currentAssistant);
        }

        return response()->json([
            'message' => 'Product updated successfully.',
            'product' => $this->payload($product->fresh()),
        ]);
    }

    public function destroy(Request $request, int $productId): JsonResponse
    {
        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $product = $this->resolveProduct($company, $productId);
        /** @var Assistant|null $assistant */
        $assistant = $company->assistants()->whereKey((int) $product->assistant_id)->first();

        $product->delete();

        if ($assistant) {
            $this->syncAssistantCatalogToOpenAi($assistant);
        }

        return response()->json([
            'message' => 'Product deleted successfully.',
        ]);
    }

    private function resolveUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function resolveCompany(User $user): Company
    {
        return $this->subscriptionService()->provisionDefaultWorkspaceForUser($user->id, $user->name);
    }

    private function resolveAssistant(Company $company, int $assistantId): Assistant
    {
        /** @var Assistant|null $assistant */
        $assistant = $company->assistants()->whereKey($assistantId)->first();

        abort_unless($assistant, 404, 'Assistant not found.');

        return $assistant;
    }

    private function resolveProduct(Company $company, int $productId): AssistantProduct
    {
        /** @var AssistantProduct|null $product */
        $product = $company->assistantProducts()->whereKey($productId)->first();

        abort_unless($product, 404, 'Product not found.');

        return $product;
    }

    private function normalizeMoney(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.0;
        }

        $normalized = round((float) $value, 2);

        return $normalized < 0 ? 0.0 : $normalized;
    }

    private function normalizeStock(mixed $value): int
    {
        if (! is_numeric($value)) {
            return 0;
        }

        return max((int) $value, 0);
    }

    private function normalizeCurrency(mixed $value): string
    {
        if (! is_string($value)) {
            return 'TJS';
        }

        $currency = strtoupper(trim($value));

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            return 'TJS';
        }

        return $currency;
    }

    private function normalizePhotoUrls(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $row) {
            if (! is_string($row)) {
                continue;
            }

            $url = trim($row);

            if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
                continue;
            }

            $normalized[] = $url;
        }

        return array_values(array_unique($normalized));
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function payload(AssistantProduct $product): array
    {
        $metadata = is_array($product->metadata) ? $product->metadata : [];
        $productUrl = null;
        if (is_string($metadata['product_url'] ?? null)) {
            $candidate = trim((string) $metadata['product_url']);
            if ($candidate !== '') {
                $productUrl = $candidate;
            }
        }

        return [
            'id' => (int) $product->id,
            'assistant_id' => (int) $product->assistant_id,
            'name' => (string) $product->name,
            'sku' => $product->sku,
            'description' => $product->description,
            'terms_conditions' => $product->terms_conditions,
            'price' => (float) $product->price,
            'currency' => (string) $product->currency,
            'stock_quantity' => $product->stock_quantity !== null ? (int) $product->stock_quantity : null,
            'is_unlimited_stock' => (bool) $product->is_unlimited_stock,
            'product_url' => $productUrl,
            'photo_urls' => is_array($product->photo_urls) ? array_values($product->photo_urls) : [],
            'is_active' => (bool) $product->is_active,
            'sort_order' => (int) $product->sort_order,
            'metadata' => $metadata !== [] ? $metadata : null,
            'created_at' => $product->created_at?->toIso8601String(),
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];
    }

    private function normalizeMetadata(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return $value;
    }

    private function subscriptionService(): CompanySubscriptionService
    {
        return app(CompanySubscriptionService::class);
    }

    private function openAiAssistantService(): OpenAiAssistantService
    {
        return app(OpenAiAssistantService::class);
    }

    private function syncAssistantCatalogToOpenAi(Assistant $assistant): void
    {
        $openAiAssistantService = $this->openAiAssistantService();

        if (! $openAiAssistantService->isConfigured()) {
            return;
        }

        try {
            $openAiAssistantService->syncAssistant($assistant->fresh());
        } catch (Throwable $exception) {
            Log::warning('OpenAI assistant sync failed after product catalog mutation', [
                'assistant_id' => $assistant->id,
                'company_id' => $assistant->company_id,
                'service' => static::class,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
