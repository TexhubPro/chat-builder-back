<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assistant;
use App\Models\AssistantService;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanySubscriptionService;
use App\Services\OpenAiAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class AssistantServiceController extends Controller
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

        $query = $assistant->services()
            ->orderBy('sort_order')
            ->orderBy('id');

        if (array_key_exists('is_active', $validated)) {
            $query->where('is_active', (bool) $validated['is_active']);
        }

        $services = $query->get();

        return response()->json([
            'services' => $services
                ->map(fn (AssistantService $service): array => $this->payload($service))
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'assistant_id' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'terms_conditions' => ['nullable', 'string', 'max:5000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'photo_urls' => ['nullable', 'array', 'max:12'],
            'photo_urls.*' => ['string', 'max:2048', 'url:http,https'],
            'specialists' => ['nullable', 'array', 'max:50'],
            'specialists.*.name' => ['required', 'string', 'min:2', 'max:120'],
            'specialists.*.price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'is_active' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $assistant = $this->resolveAssistant($company, (int) $validated['assistant_id']);

        $price = $this->normalizeMoney($validated['price'] ?? 0);
        $metadata = $this->normalizeMetadata($validated['metadata'] ?? null);
        $specialists = $this->normalizeSpecialists($validated['specialists'] ?? null, $price);
        if ($specialists !== []) {
            $metadata['specialists'] = $specialists;
        }

        $service = AssistantService::query()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'assistant_id' => $assistant->id,
            'name' => trim((string) $validated['name']),
            'description' => $this->nullableTrimmed($validated['description'] ?? null),
            'terms_conditions' => $this->nullableTrimmed($validated['terms_conditions'] ?? null),
            'price' => $price,
            'currency' => $this->normalizeCurrency($validated['currency'] ?? null),
            'photo_urls' => $this->normalizePhotoUrls($validated['photo_urls'] ?? []),
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'sort_order' => 0,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);

        $this->syncAssistantCatalogToOpenAi($assistant);

        return response()->json([
            'message' => 'Service created successfully.',
            'service' => $this->payload($service),
        ], 201);
    }

    public function update(Request $request, int $serviceId): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'terms_conditions' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'photo_urls' => ['sometimes', 'nullable', 'array', 'max:12'],
            'photo_urls.*' => ['string', 'max:2048', 'url:http,https'],
            'specialists' => ['sometimes', 'nullable', 'array', 'max:50'],
            'specialists.*.name' => ['required', 'string', 'min:2', 'max:120'],
            'specialists.*.price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'is_active' => ['sometimes', 'boolean'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'assistant_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $service = $this->resolveService($company, $serviceId);
        $previousAssistantId = (int) $service->assistant_id;

        if (array_key_exists('assistant_id', $validated)) {
            $assistant = $this->resolveAssistant($company, (int) $validated['assistant_id']);
            $service->assistant_id = $assistant->id;
        }

        if (array_key_exists('name', $validated)) {
            $service->name = trim((string) $validated['name']);
        }

        if (array_key_exists('description', $validated)) {
            $service->description = $this->nullableTrimmed($validated['description']);
        }

        if (array_key_exists('terms_conditions', $validated)) {
            $service->terms_conditions = $this->nullableTrimmed($validated['terms_conditions']);
        }

        $resolvedPrice = array_key_exists('price', $validated)
            ? $this->normalizeMoney($validated['price'])
            : $this->normalizeMoney($service->price);

        if (array_key_exists('price', $validated)) {
            $service->price = $resolvedPrice;
        }

        if (array_key_exists('currency', $validated)) {
            $service->currency = $this->normalizeCurrency($validated['currency']);
        }

        if (array_key_exists('photo_urls', $validated)) {
            $service->photo_urls = $this->normalizePhotoUrls($validated['photo_urls'] ?? []);
        }

        if (array_key_exists('is_active', $validated)) {
            $service->is_active = (bool) $validated['is_active'];
        }

        $metadata = $this->normalizeMetadata($service->metadata);
        if (array_key_exists('metadata', $validated)) {
            $metadata = $this->normalizeMetadata($validated['metadata']);
        }

        if (array_key_exists('specialists', $validated)) {
            $specialists = $this->normalizeSpecialists($validated['specialists'], $resolvedPrice);

            if ($specialists === []) {
                unset($metadata['specialists']);
            } else {
                $metadata['specialists'] = $specialists;
            }
        }

        $service->metadata = $metadata === [] ? null : $metadata;
        $service->save();

        if ($previousAssistantId !== (int) $service->assistant_id) {
            /** @var Assistant|null $previousAssistant */
            $previousAssistant = $company->assistants()->whereKey($previousAssistantId)->first();
            if ($previousAssistant) {
                $this->syncAssistantCatalogToOpenAi($previousAssistant);
            }
        }

        /** @var Assistant|null $currentAssistant */
        $currentAssistant = $company->assistants()->whereKey((int) $service->assistant_id)->first();
        if ($currentAssistant) {
            $this->syncAssistantCatalogToOpenAi($currentAssistant);
        }

        return response()->json([
            'message' => 'Service updated successfully.',
            'service' => $this->payload($service->fresh()),
        ]);
    }

    public function destroy(Request $request, int $serviceId): JsonResponse
    {
        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $service = $this->resolveService($company, $serviceId);
        /** @var Assistant|null $assistant */
        $assistant = $company->assistants()->whereKey((int) $service->assistant_id)->first();

        $service->delete();

        if ($assistant) {
            $this->syncAssistantCatalogToOpenAi($assistant);
        }

        return response()->json([
            'message' => 'Service deleted successfully.',
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

    private function resolveService(Company $company, int $serviceId): AssistantService
    {
        /** @var AssistantService|null $service */
        $service = $company->assistantServices()->whereKey($serviceId)->first();

        abort_unless($service, 404, 'Service not found.');

        return $service;
    }

    private function normalizeMoney(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.0;
        }

        $normalized = round((float) $value, 2);

        return $normalized < 0 ? 0.0 : $normalized;
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

    private function payload(AssistantService $service): array
    {
        $specialists = $this->extractSpecialists($service);

        return [
            'id' => (int) $service->id,
            'assistant_id' => (int) $service->assistant_id,
            'name' => (string) $service->name,
            'description' => $service->description,
            'terms_conditions' => $service->terms_conditions,
            'price' => (float) $service->price,
            'currency' => (string) $service->currency,
            'specialists' => $specialists,
            'photo_urls' => is_array($service->photo_urls) ? array_values($service->photo_urls) : [],
            'is_active' => (bool) $service->is_active,
            'sort_order' => (int) $service->sort_order,
            'metadata' => is_array($service->metadata) ? $service->metadata : null,
            'created_at' => $service->created_at?->toIso8601String(),
            'updated_at' => $service->updated_at?->toIso8601String(),
        ];
    }

    private function normalizeMetadata(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return $value;
    }

    private function normalizeSpecialists(mixed $value, float $fallbackPrice): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $price = $row['price'] ?? null;
            $resolvedPrice = $price === null || $price === ''
                ? $fallbackPrice
                : $this->normalizeMoney($price);

            $normalized[] = [
                'name' => mb_substr($name, 0, 120),
                'price' => $resolvedPrice,
            ];
        }

        return array_values($normalized);
    }

    private function extractSpecialists(AssistantService $service): array
    {
        $metadata = is_array($service->metadata) ? $service->metadata : [];
        $specialists = $metadata['specialists'] ?? [];

        if (! is_array($specialists)) {
            return [];
        }

        return $this->normalizeSpecialists($specialists, (float) $service->price);
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
            Log::warning('OpenAI assistant sync failed after service catalog mutation', [
                'assistant_id' => $assistant->id,
                'company_id' => $assistant->company_id,
                'service' => static::class,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
