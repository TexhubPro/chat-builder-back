<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assistant;
use App\Models\AssistantChannel;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\User;
use App\Services\CompanySubscriptionService;
use App\Services\TelegramBotApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use TexHub\Meta\Models\InstagramIntegration;
use Throwable;

class AssistantChannelController extends Controller
{
    private const SUPPORTED_CHANNELS = [
        'instagram',
        'telegram',
        'widget',
        'api',
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'assistant_id' => ['nullable', 'integer'],
        ]);

        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $this->subscriptionService()->syncAssistantAccess($company);

        $assistants = $company->assistants()
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->get(['id', 'name', 'is_active']);

        $selectedAssistantId = isset($validated['assistant_id'])
            ? (int) $validated['assistant_id']
            : null;

        $selectedAssistant = null;
        if ($selectedAssistantId !== null) {
            $selectedAssistant = $assistants->firstWhere('id', $selectedAssistantId);

            if (! $selectedAssistant) {
                return response()->json([
                    'message' => 'Assistant not found.',
                ], 404);
            }
        }

        $channels = [];
        if ($selectedAssistant) {
            $channels = $this->channelsPayloadForAssistant($company, (int) $selectedAssistant->id);
        }

        return response()->json([
            'assistants' => $assistants
                ->map(fn (Assistant $assistant): array => [
                    'id' => $assistant->id,
                    'name' => $assistant->name,
                    'is_active' => (bool) $assistant->is_active,
            ])
                ->values(),
            'selected_assistant_id' => $selectedAssistant?->id,
            'channels' => $channels,
            'limits' => $this->limitsPayload(
                $company,
                assistantId: $selectedAssistant?->id
            ),
        ]);
    }

    public function update(Request $request, int $assistantId, string $channel): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'name' => ['nullable', 'string', 'max:120'],
            'external_account_id' => ['nullable', 'string', 'max:191'],
            'settings' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ]);

        $normalizedChannel = Str::of($channel)->lower()->trim()->value();

        if (! in_array($normalizedChannel, self::SUPPORTED_CHANNELS, true)) {
            return response()->json([
                'message' => 'Unsupported channel.',
            ], 422);
        }

        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $this->subscriptionService()->syncAssistantAccess($company);
        $assistant = $company->assistants()->whereKey($assistantId)->first();

        if (! $assistant) {
            return response()->json([
                'message' => 'Assistant not found.',
            ], 404);
        }

        [$subscription, $hasActiveSubscription, $integrationLimit] = $this->subscriptionContext($company);

        $enabled = filter_var($validated['enabled'], FILTER_VALIDATE_BOOL);
        $existing = AssistantChannel::query()
            ->where('assistant_id', $assistant->id)
            ->where('channel', $normalizedChannel)
            ->first();

        if ($enabled) {
            if (! $hasActiveSubscription) {
                return response()->json([
                    'message' => 'Cannot connect integration while subscription is inactive.',
                ], 422);
            }

            if ($integrationLimit <= 0) {
                return response()->json([
                    'message' => 'Integration limit reached for current subscription.',
                ], 422);
            }

            $activeCount = (int) AssistantChannel::query()
                ->where('assistant_id', $assistant->id)
                ->where('is_active', true)
                ->where('channel', '!=', $normalizedChannel)
                ->count();

            if (! ($existing && $existing->is_active) && $activeCount >= $integrationLimit) {
                return response()->json([
                    'message' => 'Integration limit reached for current subscription.',
                ], 422);
            }

            $assistantChannel = $existing ?? new AssistantChannel([
                'user_id' => $user->id,
                'company_id' => $company->id,
                'assistant_id' => $assistant->id,
                'channel' => $normalizedChannel,
            ]);

            $assistantChannel->name = $this->nullableTrimmedString($validated['name'] ?? null) ?? $assistantChannel->name;
            $assistantChannel->external_account_id = $this->nullableTrimmedString($validated['external_account_id'] ?? null)
                ?? $assistantChannel->external_account_id;
            $assistantChannel->settings = array_key_exists('settings', $validated)
                ? (is_array($validated['settings']) ? $validated['settings'] : [])
                : $assistantChannel->settings;
            $assistantChannel->metadata = array_key_exists('metadata', $validated)
                ? (is_array($validated['metadata']) ? $validated['metadata'] : [])
                : $assistantChannel->metadata;

            if ($normalizedChannel === AssistantChannel::CHANNEL_WIDGET) {
                $this->ensureWidgetChannelDefaults($assistantChannel, $assistant, $company);
            }

            $assistantChannel->is_active = true;
            $assistantChannel->save();

            if ($normalizedChannel === AssistantChannel::CHANNEL_INSTAGRAM) {
                $this->syncInstagramIntegrationStatus(
                    $user->id,
                    $assistantChannel->external_account_id,
                    true
                );
            }

            return response()->json([
                'message' => 'Integration enabled successfully.',
                'channel' => $this->channelPayload($assistantChannel),
                'limits' => $this->limitsPayload($company, $subscription, $hasActiveSubscription, $integrationLimit, $assistant->id),
            ]);
        }

        if ($existing) {
            $existing->forceFill([
                'is_active' => false,
            ])->save();

            if ($normalizedChannel === AssistantChannel::CHANNEL_INSTAGRAM) {
                $this->syncInstagramIntegrationStatus(
                    $user->id,
                    $existing->external_account_id,
                    false
                );
            }
        }

        $payload = $existing
            ? $this->channelPayload($existing->refresh())
            : $this->emptyChannelPayload($normalizedChannel);

        return response()->json([
            'message' => 'Integration disabled successfully.',
            'channel' => $payload,
            'limits' => $this->limitsPayload($company, $subscription, $hasActiveSubscription, $integrationLimit, $assistant->id),
        ]);
    }

    public function destroy(Request $request, int $assistantId, string $channel): JsonResponse
    {
        $normalizedChannel = Str::of($channel)->lower()->trim()->value();

        if (! in_array($normalizedChannel, self::SUPPORTED_CHANNELS, true)) {
            return response()->json([
                'message' => 'Unsupported channel.',
            ], 422);
        }

        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $this->subscriptionService()->syncAssistantAccess($company);
        $assistant = $company->assistants()->whereKey($assistantId)->first();

        if (! $assistant) {
            return response()->json([
                'message' => 'Assistant not found.',
            ], 404);
        }

        [$subscription, $hasActiveSubscription, $integrationLimit] = $this->subscriptionContext($company);

        $existing = AssistantChannel::query()
            ->where('assistant_id', $assistant->id)
            ->where('channel', $normalizedChannel)
            ->first();

        if ($existing) {
            if ($normalizedChannel === AssistantChannel::CHANNEL_INSTAGRAM) {
                $this->disconnectInstagramIntegration($user->id, $existing->external_account_id);
            }

            if ($normalizedChannel === AssistantChannel::CHANNEL_TELEGRAM) {
                $this->disconnectTelegramIntegration($existing);
            }

            $existing->delete();
        }

        return response()->json([
            'message' => 'Integration disconnected successfully.',
            'channel' => $this->emptyChannelPayload($normalizedChannel),
            'limits' => $this->limitsPayload(
                $company,
                $subscription,
                $hasActiveSubscription,
                $integrationLimit,
                $assistant->id
            ),
        ]);
    }

    public function widgetSettings(Request $request, int $assistantId): JsonResponse
    {
        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $this->subscriptionService()->syncAssistantAccess($company);

        $assistant = $company->assistants()->whereKey($assistantId)->first();
        if (! $assistant) {
            return response()->json([
                'message' => 'Assistant not found.',
            ], 404);
        }

        $assistantChannel = AssistantChannel::query()
            ->where('assistant_id', $assistant->id)
            ->where('channel', AssistantChannel::CHANNEL_WIDGET)
            ->first();

        if (! $assistantChannel) {
            return response()->json([
                'message' => 'Widget channel is not connected.',
            ], 404);
        }

        $this->ensureWidgetChannelDefaults($assistantChannel, $assistant, $company);
        $assistantChannel->save();

        return response()->json([
            'message' => 'Widget settings loaded successfully.',
            'channel' => $this->channelPayload($assistantChannel->refresh()),
            'widget' => $this->widgetSettingsPayload($assistantChannel, $assistant, $company),
        ]);
    }

    public function updateWidgetSettings(Request $request, int $assistantId): JsonResponse
    {
        $validated = $request->validate([
            'position' => ['nullable', 'string', 'in:bottom-right,bottom-left'],
            'theme' => ['nullable', 'string', 'in:light,dark'],
            'primary_color' => ['nullable', 'string', 'max:16'],
            'title' => ['nullable', 'string', 'max:120'],
            'welcome_message' => ['nullable', 'string', 'max:500'],
            'placeholder' => ['nullable', 'string', 'max:180'],
            'launcher_label' => ['nullable', 'string', 'max:60'],
        ]);

        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $this->subscriptionService()->syncAssistantAccess($company);

        $assistant = $company->assistants()->whereKey($assistantId)->first();
        if (! $assistant) {
            return response()->json([
                'message' => 'Assistant not found.',
            ], 404);
        }

        $assistantChannel = AssistantChannel::query()
            ->where('assistant_id', $assistant->id)
            ->where('channel', AssistantChannel::CHANNEL_WIDGET)
            ->first();

        if (! $assistantChannel) {
            return response()->json([
                'message' => 'Widget channel is not connected.',
            ], 404);
        }

        $settings = array_replace(
            $this->normalizeWidgetSettings(
                is_array($assistantChannel->settings) ? $assistantChannel->settings : [],
                $assistant,
                $company
            ),
            array_filter([
                'position' => $validated['position'] ?? null,
                'theme' => $validated['theme'] ?? null,
                'primary_color' => $this->normalizeWidgetColor($validated['primary_color'] ?? null),
                'title' => $this->nullableTrimmedString($validated['title'] ?? null),
                'welcome_message' => $this->nullableTrimmedString($validated['welcome_message'] ?? null),
                'placeholder' => $this->nullableTrimmedString($validated['placeholder'] ?? null),
                'launcher_label' => $this->nullableTrimmedString($validated['launcher_label'] ?? null),
            ], static fn (mixed $value): bool => $value !== null)
        );

        $assistantChannel->settings = $this->normalizeWidgetSettings($settings, $assistant, $company);
        $assistantChannel->save();

        return response()->json([
            'message' => 'Widget settings updated successfully.',
            'channel' => $this->channelPayload($assistantChannel->refresh()),
            'widget' => $this->widgetSettingsPayload($assistantChannel, $assistant, $company),
        ]);
    }

    private function channelsPayloadForAssistant(Company $company, int $assistantId): array
    {
        $rows = AssistantChannel::query()
            ->where('company_id', $company->id)
            ->where('assistant_id', $assistantId)
            ->get()
            ->keyBy('channel');

        $items = [];
        foreach (self::SUPPORTED_CHANNELS as $channel) {
            $record = $rows->get($channel);
            $items[] = $record
                ? $this->channelPayload($record)
                : $this->emptyChannelPayload($channel);
        }

        return $items;
    }

    private function channelPayload(AssistantChannel $assistantChannel): array
    {
        return [
            'id' => $assistantChannel->id,
            'assistant_id' => $assistantChannel->assistant_id,
            'channel' => $assistantChannel->channel,
            'name' => $assistantChannel->name,
            'external_account_id' => $assistantChannel->external_account_id,
            'is_connected' => true,
            'is_active' => (bool) $assistantChannel->is_active,
            'settings' => is_array($assistantChannel->settings) ? $assistantChannel->settings : [],
            'metadata' => is_array($assistantChannel->metadata) ? $assistantChannel->metadata : [],
            'updated_at' => $assistantChannel->updated_at?->toIso8601String(),
        ];
    }

    private function widgetSettingsPayload(
        AssistantChannel $assistantChannel,
        Assistant $assistant,
        Company $company,
    ): array {
        $credentials = is_array($assistantChannel->credentials) ? $assistantChannel->credentials : [];
        $widgetKey = $this->nullableTrimmedString($credentials['widget_key'] ?? null);
        $settings = $this->normalizeWidgetSettings(
            is_array($assistantChannel->settings) ? $assistantChannel->settings : [],
            $assistant,
            $company
        );

        $scriptUrl = url('/widget/chat-widget.js');
        $apiBaseUrl = url('/api/widget');
        $embedScriptTag = $widgetKey === null
            ? null
            : '<script src="'.$scriptUrl.'" data-widget-key="'.$widgetKey.'" defer></script>';

        return [
            'widget_key' => $widgetKey,
            'settings' => $settings,
            'script_url' => $scriptUrl,
            'api_base_url' => $apiBaseUrl,
            'embed_script_tag' => $embedScriptTag,
        ];
    }

    private function emptyChannelPayload(string $channel): array
    {
        return [
            'id' => null,
            'assistant_id' => null,
            'channel' => $channel,
            'name' => null,
            'external_account_id' => null,
            'is_connected' => false,
            'is_active' => false,
            'settings' => [],
            'metadata' => [],
            'updated_at' => null,
        ];
    }

    private function limitsPayload(
        Company $company,
        ?CompanySubscription $subscription = null,
        ?bool $hasActiveSubscription = null,
        ?int $integrationLimit = null,
        ?int $assistantId = null,
    ): array {
        if ($subscription === null || $hasActiveSubscription === null || $integrationLimit === null) {
            [$subscription, $hasActiveSubscription, $integrationLimit] = $this->subscriptionContext($company);
        }

        $activeCountQuery = $company->assistantChannels()->where('is_active', true);
        if ($assistantId !== null) {
            $activeCountQuery->where('assistant_id', $assistantId);
        }

        return [
            'has_active_subscription' => $hasActiveSubscription,
            'subscription_status' => $subscription?->status,
            'integrations_per_channel_limit' => $integrationLimit,
            'active_integrations' => (int) $activeCountQuery->count(),
        ];
    }

    private function subscriptionContext(Company $company): array
    {
        $subscription = $company->subscription()->with('plan')->first();

        if (! $subscription) {
            return [null, false, 0];
        }

        $this->subscriptionService()->synchronizeBillingPeriods($subscription);
        $subscription->refresh()->load('plan');

        $isActive = $subscription->isActiveAt();
        $integrationLimit = $isActive ? max($subscription->resolvedIntegrationsPerChannelLimit(), 0) : 0;

        return [$subscription, $isActive, $integrationLimit];
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function ensureWidgetChannelDefaults(
        AssistantChannel $assistantChannel,
        Assistant $assistant,
        Company $company,
    ): void {
        $credentials = is_array($assistantChannel->credentials) ? $assistantChannel->credentials : [];
        $widgetKey = $this->nullableTrimmedString($credentials['widget_key'] ?? null);

        if ($widgetKey === null) {
            $widgetKey = $this->generateUniqueWidgetKey();
        }

        $credentials = array_replace($credentials, [
            'provider' => 'widget',
            'widget_key' => $widgetKey,
            'connected_at' => $credentials['connected_at'] ?? now()->toIso8601String(),
        ]);

        $assistantChannel->credentials = $credentials;
        $assistantChannel->settings = $this->normalizeWidgetSettings(
            is_array($assistantChannel->settings) ? $assistantChannel->settings : [],
            $assistant,
            $company
        );

        if ($this->nullableTrimmedString($assistantChannel->name) === null) {
            $assistantChannel->name = 'Web Widget';
        }

        if ($this->nullableTrimmedString($assistantChannel->external_account_id) === null) {
            $assistantChannel->external_account_id = 'widget-'.substr($widgetKey, 0, 12);
        }
    }

    private function normalizeWidgetSettings(
        array $settings,
        Assistant $assistant,
        Company $company,
    ): array {
        $assistantName = $this->nullableTrimmedString($assistant->name) ?? 'Assistant';
        $companyName = $this->nullableTrimmedString($company->name) ?? 'Company';

        return [
            'position' => in_array((string) ($settings['position'] ?? ''), ['bottom-right', 'bottom-left'], true)
                ? (string) $settings['position']
                : 'bottom-right',
            'theme' => in_array((string) ($settings['theme'] ?? ''), ['light', 'dark'], true)
                ? (string) $settings['theme']
                : 'light',
            'primary_color' => $this->normalizeWidgetColor($settings['primary_color'] ?? null) ?? '#1677FF',
            'title' => $this->nullableTrimmedString($settings['title'] ?? null)
                ?? ($companyName.' Chat'),
            'welcome_message' => $this->nullableTrimmedString($settings['welcome_message'] ?? null)
                ?? ('Здравствуйте! Я '.$assistantName.'. Напишите ваш вопрос.'),
            'placeholder' => $this->nullableTrimmedString($settings['placeholder'] ?? null)
                ?? 'Введите сообщение...',
            'launcher_label' => $this->nullableTrimmedString($settings['launcher_label'] ?? null)
                ?? 'Чат',
        ];
    }

    private function normalizeWidgetColor(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtoupper(trim($value));

        if (preg_match('/^#[0-9A-F]{6}$/', $normalized) === 1) {
            return $normalized;
        }

        return null;
    }

    private function generateUniqueWidgetKey(): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = 'wdg_'.Str::random(48);

            $exists = AssistantChannel::query()
                ->where('channel', AssistantChannel::CHANNEL_WIDGET)
                ->where('credentials->widget_key', $candidate)
                ->exists();

            if (! $exists) {
                return $candidate;
            }
        }

        return 'wdg_'.Str::uuid()->toString();
    }

    private function syncInstagramIntegrationStatus(
        int $userId,
        ?string $externalAccountId,
        bool $isActive
    ): void {
        $normalizedExternalId = $this->nullableTrimmedString($externalAccountId);
        if ($normalizedExternalId === null) {
            return;
        }

        InstagramIntegration::query()
            ->where('user_id', $userId)
            ->where(function ($builder) use ($normalizedExternalId): void {
                $builder
                    ->where('receiver_id', $normalizedExternalId)
                    ->orWhere('instagram_user_id', $normalizedExternalId);
            })
            ->update([
                'is_active' => $isActive,
            ]);
    }

    private function disconnectInstagramIntegration(int $userId, ?string $externalAccountId): void
    {
        $normalizedExternalId = $this->nullableTrimmedString($externalAccountId);
        if ($normalizedExternalId === null) {
            return;
        }

        InstagramIntegration::query()
            ->where('user_id', $userId)
            ->where(function ($builder) use ($normalizedExternalId): void {
                $builder
                    ->where('receiver_id', $normalizedExternalId)
                    ->orWhere('instagram_user_id', $normalizedExternalId);
            })
            ->delete();
    }

    private function disconnectTelegramIntegration(AssistantChannel $assistantChannel): void
    {
        $credentials = is_array($assistantChannel->credentials) ? $assistantChannel->credentials : [];
        $botToken = $this->nullableTrimmedString($credentials['bot_token'] ?? null);

        if ($botToken === null) {
            return;
        }

        try {
            $this->telegramBotApiService()->deleteWebhook($botToken, false);
        } catch (Throwable) {
            // ignore external API errors on disconnect
        }
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

    private function subscriptionService(): CompanySubscriptionService
    {
        return app(CompanySubscriptionService::class);
    }

    private function telegramBotApiService(): TelegramBotApiService
    {
        return app(TelegramBotApiService::class);
    }
}
