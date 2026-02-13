<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assistant as AssistantModel;
use App\Models\AssistantInstructionFile;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanySubscriptionService;
use App\Services\OpenAiAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AssistantController extends Controller
{
    private const OPENAI_UPDATE_COOLDOWN_HOURS = 24;
    private const OPENAI_SYNC_FAILED_MESSAGE = 'Assistant saved, but OpenAI synchronization failed.';
    private const OPENAI_SYNC_INACTIVE_SUBSCRIPTION_MESSAGE = 'Assistant saved in database. OpenAI sync is available only with an active subscription.';
    private const OPENAI_SYNC_RATE_LIMITED_MESSAGE = 'Assistant saved in database. OpenAI update is available once every 24 hours.';
    private const OPENAI_FILE_SYNC_INACTIVE_SUBSCRIPTION_MESSAGE = 'Files were saved in database. OpenAI sync is available only with an active subscription.';
    private const OPENAI_FILE_SYNC_RATE_LIMITED_MESSAGE = 'Files were saved in database. OpenAI update is available once every 24 hours.';

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $this->subscriptionService()->syncAssistantAccess($company);

        $assistants = $company->assistants()
            ->with('instructionFiles')
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->get();

        return response()->json([
            'assistants' => $assistants
                ->map(fn (AssistantModel $assistant): array => $this->assistantPayload($assistant))
                ->values(),
            'limits' => $this->limitsPayload($company),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->assistantRules(isUpdate: false));

        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        [, $assistantLimit, $hasActiveSubscription] = $this->subscriptionState($company);

        if (! $hasActiveSubscription || $assistantLimit <= 0) {
            return response()->json([
                'message' => 'Assistant creation is unavailable while subscription is inactive.',
            ], 422);
        }

        $existingAssistants = (int) $company->assistants()->count();
        if ($existingAssistants >= $assistantLimit) {
            return response()->json([
                'message' => 'Assistant limit reached for current subscription.',
            ], 422);
        }

        $assistant = AssistantModel::query()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'name' => trim((string) $validated['name']),
            'instructions' => $this->nullableTrimmedString($validated['instructions'] ?? null),
            'restrictions' => $this->nullableTrimmedString($validated['restrictions'] ?? null),
            'conversation_tone' => (string) ($validated['conversation_tone'] ?? AssistantModel::TONE_POLITE),
            'enable_file_search' => $this->toBool($validated['enable_file_search'] ?? true),
            'enable_file_analysis' => $this->toBool($validated['enable_file_analysis'] ?? false),
            'enable_voice' => $this->toBool($validated['enable_voice'] ?? false),
            'enable_web_search' => $this->toBool($validated['enable_web_search'] ?? false),
            'is_active' => false,
            'settings' => $this->normalizeSettings($validated['settings'] ?? []),
        ]);

        $syncWarning = $this->syncAssistantToOpenAi(
            $assistant,
            $user,
            $hasActiveSubscription,
            enforceDailyUpdateLimit: false,
        );
        $assistant->load('instructionFiles');
        $this->subscriptionService()->syncAssistantAccess($company);

        $response = [
            'message' => 'Assistant created successfully.',
            'assistant' => $this->assistantPayload($assistant),
            'limits' => $this->limitsPayload($company),
        ];

        if ($syncWarning !== null) {
            $response['warning'] = $syncWarning;
        }

        return response()->json($response, 201);
    }

    public function update(Request $request, int $assistantId): JsonResponse
    {
        $validated = $request->validate($this->assistantRules(isUpdate: true));

        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $assistant = $this->resolveAssistant($company, $assistantId);
        [, , $hasActiveSubscription] = $this->subscriptionState($company);

        $assistant->name = trim((string) ($validated['name'] ?? $assistant->name));
        $assistant->instructions = $this->nullableTrimmedString(
            $validated['instructions'] ?? $assistant->instructions
        );
        $assistant->restrictions = $this->nullableTrimmedString(
            $validated['restrictions'] ?? $assistant->restrictions
        );
        $assistant->conversation_tone = (string) (
            $validated['conversation_tone'] ?? $assistant->conversation_tone
        );

        if (array_key_exists('enable_file_search', $validated)) {
            $assistant->enable_file_search = $this->toBool($validated['enable_file_search']);
        }

        if (array_key_exists('enable_file_analysis', $validated)) {
            $assistant->enable_file_analysis = $this->toBool($validated['enable_file_analysis']);
        }

        if (array_key_exists('enable_voice', $validated)) {
            $assistant->enable_voice = $this->toBool($validated['enable_voice']);
        }

        if (array_key_exists('enable_web_search', $validated)) {
            $assistant->enable_web_search = $this->toBool($validated['enable_web_search']);
        }

        if (array_key_exists('settings', $validated)) {
            $assistant->settings = $this->normalizeSettings($validated['settings'] ?? []);
        }

        $assistant->save();

        $syncWarning = $this->syncAssistantToOpenAi(
            $assistant,
            $user,
            $hasActiveSubscription,
            enforceDailyUpdateLimit: true,
        );
        $assistant->refresh()->load('instructionFiles');
        $this->subscriptionService()->syncAssistantAccess($company);

        $response = [
            'message' => 'Assistant updated successfully.',
            'assistant' => $this->assistantPayload($assistant),
            'limits' => $this->limitsPayload($company),
        ];

        if ($syncWarning !== null) {
            $response['warning'] = $syncWarning;
        }

        return response()->json($response);
    }

    public function start(Request $request, int $assistantId): JsonResponse
    {
        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $assistant = $this->resolveAssistant($company, $assistantId);
        [, $assistantLimit, $hasActiveSubscription] = $this->subscriptionState($company);

        if (! $hasActiveSubscription || $assistantLimit <= 0) {
            return response()->json([
                'message' => 'Cannot start assistant while subscription is inactive.',
            ], 422);
        }

        if ($assistant->is_active) {
            return response()->json([
                'message' => 'Assistant is already running.',
                'assistant' => $this->assistantPayload($assistant),
                'limits' => $this->limitsPayload($company),
            ]);
        }

        $activeAssistantsExceptCurrent = $company->assistants()
            ->where('is_active', true)
            ->whereKeyNot($assistant->id)
            ->count();

        if ($activeAssistantsExceptCurrent >= $assistantLimit) {
            return response()->json([
                'message' => 'Assistant limit reached for current subscription.',
            ], 422);
        }

        $assistant->forceFill(['is_active' => true])->save();
        $syncWarning = null;

        if (! $assistant->openai_assistant_id) {
            $syncWarning = $this->syncAssistantToOpenAi(
                $assistant,
                $user,
                $hasActiveSubscription,
                enforceDailyUpdateLimit: false,
            );
        }

        $this->subscriptionService()->syncAssistantAccess($company);
        $assistant->refresh()->load('instructionFiles');

        $response = [
            'message' => 'Assistant started.',
            'assistant' => $this->assistantPayload($assistant),
            'limits' => $this->limitsPayload($company),
        ];

        if ($syncWarning !== null) {
            $response['warning'] = $syncWarning;
        }

        return response()->json($response);
    }

    public function stop(Request $request, int $assistantId): JsonResponse
    {
        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $assistant = $this->resolveAssistant($company, $assistantId);

        if (! $assistant->is_active) {
            return response()->json([
                'message' => 'Assistant is already stopped.',
                'assistant' => $this->assistantPayload($assistant),
                'limits' => $this->limitsPayload($company),
            ]);
        }

        $assistant->forceFill(['is_active' => false])->save();
        $this->subscriptionService()->syncAssistantAccess($company);
        $assistant->refresh()->load('instructionFiles');

        return response()->json([
            'message' => 'Assistant stopped.',
            'assistant' => $this->assistantPayload($assistant),
            'limits' => $this->limitsPayload($company),
        ]);
    }

    public function destroy(Request $request, int $assistantId): JsonResponse
    {
        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $assistant = $this->resolveAssistant($company, $assistantId);
        $assistant->load('instructionFiles');

        $warningParts = [];

        foreach ($assistant->instructionFiles as $instructionFile) {
            try {
                $this->openAiAssistantService()->deleteInstructionFile($instructionFile);
            } catch (Throwable) {
                $warningParts[] = 'OpenAI file cleanup failed for one instruction file.';
            }

            Storage::disk((string) $instructionFile->disk)->delete((string) $instructionFile->path);
            $instructionFile->delete();
        }

        try {
            $this->openAiAssistantService()->cleanupAssistant($assistant);
        } catch (Throwable) {
            $warningParts[] = 'OpenAI vector store cleanup failed.';
        }

        $assistant->delete();
        $this->subscriptionService()->syncAssistantAccess($company);

        $response = [
            'message' => 'Assistant deleted successfully.',
            'limits' => $this->limitsPayload($company),
        ];

        if ($warningParts !== []) {
            $response['warning'] = implode(' ', $warningParts);
        }

        return response()->json($response);
    }

    public function uploadInstructionFiles(Request $request, int $assistantId): JsonResponse
    {
        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:8'],
            'files.*' => [
                'required',
                'file',
                'max:10240',
                'mimes:txt,pdf,doc,docx',
            ],
        ]);

        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $assistant = $this->resolveAssistant($company, $assistantId);
        [, , $hasActiveSubscription] = $this->subscriptionState($company);
        $warningParts = [];
        [$canSyncFilesToOpenAi, $syncGateWarning] = $this->instructionFilesOpenAiSyncGate(
            $user,
            $hasActiveSubscription
        );

        if ($syncGateWarning !== null) {
            $warningParts[] = $syncGateWarning;
        }

        $uploadedPayloads = [];
        $openAiSynced = false;
        /** @var array<int, UploadedFile> $files */
        $files = $validated['files'];

        foreach ($files as $file) {
            $disk = 'public';
            $storedPath = $file->store('assistants/'.$assistant->id.'/instructions', $disk);

            $instructionFile = AssistantInstructionFile::query()->create([
                'assistant_id' => $assistant->id,
                'uploaded_by_user_id' => $user->id,
                'disk' => $disk,
                'path' => $storedPath,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'purpose' => 'instructions',
                'metadata' => [
                    'extension' => $file->getClientOriginalExtension(),
                ],
            ]);

            if ($canSyncFilesToOpenAi) {
                try {
                    $this->openAiAssistantService()->uploadInstructionFile(
                        $assistant,
                        $instructionFile,
                        Storage::disk($disk)->path($storedPath)
                    );
                    $openAiSynced = true;
                } catch (Throwable) {
                    $warningParts[] = 'OpenAI upload failed for file: '.$instructionFile->original_name.'.';
                }
            }

            $uploadedPayloads[] = $this->instructionFilePayload($instructionFile->fresh());
        }

        if ($openAiSynced) {
            $this->touchOpenAiAssistantUpdateTimestamp($user);
        }

        $assistant->refresh()->load('instructionFiles');

        $response = [
            'message' => 'Instruction files uploaded successfully.',
            'assistant' => $this->assistantPayload($assistant),
            'files' => $uploadedPayloads,
        ];

        if ($warningParts !== []) {
            $response['warning'] = implode(' ', $warningParts);
        }

        return response()->json($response, 201);
    }

    public function destroyInstructionFile(
        Request $request,
        int $assistantId,
        int $fileId
    ): JsonResponse {
        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $assistant = $this->resolveAssistant($company, $assistantId);
        [, , $hasActiveSubscription] = $this->subscriptionState($company);

        $instructionFile = $assistant->instructionFiles()
            ->whereKey($fileId)
            ->first();

        if (! $instructionFile) {
            return response()->json([
                'message' => 'Instruction file not found.',
            ], 404);
        }

        $warningParts = [];
        [$canSyncFilesToOpenAi, $syncGateWarning] = $this->instructionFilesOpenAiSyncGate(
            $user,
            $hasActiveSubscription
        );

        if ($syncGateWarning !== null) {
            $warningParts[] = $syncGateWarning;
        }

        if ($canSyncFilesToOpenAi) {
            try {
                $this->openAiAssistantService()->deleteInstructionFile($instructionFile);
                $this->touchOpenAiAssistantUpdateTimestamp($user);
            } catch (Throwable) {
                $warningParts[] = 'OpenAI cleanup failed for this file.';
            }
        }

        Storage::disk((string) $instructionFile->disk)->delete((string) $instructionFile->path);
        $instructionFile->delete();

        $assistant->refresh()->load('instructionFiles');

        $response = [
            'message' => 'Instruction file removed.',
            'assistant' => $this->assistantPayload($assistant),
        ];

        if ($warningParts !== []) {
            $response['warning'] = implode(' ', array_unique($warningParts));
        }

        return response()->json($response);
    }

    private function assistantRules(bool $isUpdate): array
    {
        $nameRule = $isUpdate ? ['sometimes', 'string', 'min:2', 'max:120'] : ['required', 'string', 'min:2', 'max:120'];

        return [
            'name' => $nameRule,
            'instructions' => ['nullable', 'string', 'max:20000'],
            'restrictions' => ['nullable', 'string', 'max:12000'],
            'conversation_tone' => [
                'sometimes',
                'string',
                'in:'.implode(',', [
                    AssistantModel::TONE_POLITE,
                    AssistantModel::TONE_CONCISE,
                    AssistantModel::TONE_FRIENDLY,
                    AssistantModel::TONE_FORMAL,
                    AssistantModel::TONE_CUSTOM,
                ]),
            ],
            'enable_file_search' => ['sometimes', 'boolean'],
            'enable_file_analysis' => ['sometimes', 'boolean'],
            'enable_voice' => ['sometimes', 'boolean'],
            'enable_web_search' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
            'settings.triggers' => ['nullable', 'array', 'max:200'],
            'settings.triggers.*.trigger' => ['nullable', 'string', 'max:300'],
            'settings.triggers.*.response' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function normalizeSettings(mixed $rawSettings): array
    {
        if (! is_array($rawSettings)) {
            return [];
        }

        $settings = [];

        $triggers = [];
        foreach ((array) ($rawSettings['triggers'] ?? []) as $trigger) {
            if (! is_array($trigger)) {
                continue;
            }

            $triggerText = trim((string) ($trigger['trigger'] ?? ''));
            $responseText = trim((string) ($trigger['response'] ?? ''));

            if ($triggerText === '' || $responseText === '') {
                continue;
            }

            $triggers[] = [
                'trigger' => mb_substr($triggerText, 0, 300),
                'response' => mb_substr($responseText, 0, 2000),
            ];
        }

        if ($triggers !== []) {
            $settings['triggers'] = $triggers;
        }

        return $settings;
    }

    private function assistantPayload(AssistantModel $assistant): array
    {
        $assistant->loadMissing('instructionFiles');

        return [
            'id' => $assistant->id,
            'name' => $assistant->name,
            'instructions' => $assistant->instructions,
            'restrictions' => $assistant->restrictions,
            'conversation_tone' => $assistant->conversation_tone,
            'is_active' => (bool) $assistant->is_active,
            'enable_file_search' => (bool) $assistant->enable_file_search,
            'enable_file_analysis' => (bool) $assistant->enable_file_analysis,
            'enable_voice' => (bool) $assistant->enable_voice,
            'enable_web_search' => (bool) $assistant->enable_web_search,
            'openai_assistant_id' => $assistant->openai_assistant_id,
            'openai_vector_store_id' => $assistant->openai_vector_store_id,
            'settings' => $assistant->settings ?? [],
            'instruction_files' => $assistant->instructionFiles
                ->map(fn (AssistantInstructionFile $file): array => $this->instructionFilePayload($file))
                ->values(),
            'created_at' => $assistant->created_at?->toIso8601String(),
            'updated_at' => $assistant->updated_at?->toIso8601String(),
        ];
    }

    private function instructionFilePayload(AssistantInstructionFile $file): array
    {
        return [
            'id' => $file->id,
            'original_name' => $file->original_name,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'purpose' => $file->purpose,
            'openai_file_id' => $file->openai_file_id,
            'url' => Storage::disk((string) $file->disk)->url((string) $file->path),
            'created_at' => $file->created_at?->toIso8601String(),
        ];
    }

    private function limitsPayload(Company $company): array
    {
        [$subscription, $assistantLimit, $hasActiveSubscription] = $this->subscriptionState($company);

        $totalAssistants = (int) $company->assistants()->count();
        $activeAssistants = (int) $company->assistants()->where('is_active', true)->count();

        return [
            'assistant_limit' => $assistantLimit,
            'has_active_subscription' => $hasActiveSubscription,
            'subscription_status' => $subscription?->status,
            'current_assistants' => $totalAssistants,
            'active_assistants' => $activeAssistants,
            'can_create' => $hasActiveSubscription && $assistantLimit > $totalAssistants,
        ];
    }

    private function subscriptionState(Company $company): array
    {
        $subscription = $company->subscription()->with('plan')->first();

        if (! $subscription) {
            return [null, 0, false];
        }

        $this->subscriptionService()->synchronizeBillingPeriods($subscription);
        $subscription->refresh()->load('plan');

        $isActive = $subscription->isActiveAt();
        $limit = $isActive ? max($subscription->resolvedAssistantLimit(), 0) : 0;

        return [$subscription, $limit, $isActive];
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

    private function resolveAssistant(Company $company, int $assistantId): AssistantModel
    {
        /** @var AssistantModel|null $assistant */
        $assistant = $company->assistants()
            ->with('instructionFiles')
            ->whereKey($assistantId)
            ->first();

        if (! $assistant) {
            abort(404, 'Assistant not found.');
        }

        return $assistant;
    }

    private function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function syncAssistantToOpenAi(
        AssistantModel $assistant,
        User $user,
        bool $hasActiveSubscription,
        bool $enforceDailyUpdateLimit
    ): ?string
    {
        if (! $this->openAiAssistantService()->isConfigured()) {
            return null;
        }

        if (! $hasActiveSubscription) {
            return self::OPENAI_SYNC_INACTIVE_SUBSCRIPTION_MESSAGE;
        }

        if (
            $enforceDailyUpdateLimit
            && $this->shouldEnforceOpenAiUpdateRateLimit()
            && $this->isOpenAiUpdateRateLimited($user)
        ) {
            return self::OPENAI_SYNC_RATE_LIMITED_MESSAGE;
        }

        try {
            $this->openAiAssistantService()->syncAssistant($assistant);

            if ($enforceDailyUpdateLimit) {
                $this->touchOpenAiAssistantUpdateTimestamp($user);
            }

            return null;
        } catch (Throwable $exception) {
            Log::warning('OpenAI assistant synchronization failed', [
                'assistant_id' => $assistant->id,
                'user_id' => $user->id,
                'company_id' => $assistant->company_id,
                'operation' => $assistant->openai_assistant_id ? 'update' : 'create',
                'exception' => $exception->getMessage(),
            ]);

            return self::OPENAI_SYNC_FAILED_MESSAGE;
        }
    }

    private function instructionFilesOpenAiSyncGate(User $user, bool $hasActiveSubscription): array
    {
        if (! $this->openAiAssistantService()->isConfigured()) {
            return [false, null];
        }

        if (! $hasActiveSubscription) {
            return [false, self::OPENAI_FILE_SYNC_INACTIVE_SUBSCRIPTION_MESSAGE];
        }

        if (
            $this->shouldEnforceOpenAiUpdateRateLimit()
            && $this->isOpenAiUpdateRateLimited($user)
        ) {
            return [false, self::OPENAI_FILE_SYNC_RATE_LIMITED_MESSAGE];
        }

        return [true, null];
    }

    private function shouldEnforceOpenAiUpdateRateLimit(): bool
    {
        return (string) config('app.env') === 'production';
    }

    private function isOpenAiUpdateRateLimited(User $user): bool
    {
        $user->refresh();
        $lastUpdatedAt = $user->openai_assistant_updated_at;

        if (! $lastUpdatedAt instanceof Carbon) {
            return false;
        }

        return $lastUpdatedAt
            ->copy()
            ->addHours(self::OPENAI_UPDATE_COOLDOWN_HOURS)
            ->isFuture();
    }

    private function touchOpenAiAssistantUpdateTimestamp(User $user): void
    {
        $user->forceFill([
            'openai_assistant_updated_at' => now(),
        ])->save();
    }

    private function subscriptionService(): CompanySubscriptionService
    {
        return app(CompanySubscriptionService::class);
    }

    private function openAiAssistantService(): OpenAiAssistantService
    {
        return app(OpenAiAssistantService::class);
    }
}
