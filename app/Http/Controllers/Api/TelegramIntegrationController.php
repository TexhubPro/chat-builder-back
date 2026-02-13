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
use App\Services\TelegramMainWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class TelegramIntegrationController extends Controller
{
    public function connect(Request $request, int $assistantId): JsonResponse
    {
        $validated = $request->validate([
            'bot_token' => ['required', 'string', 'max:191'],
        ]);

        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $assistant = $company->assistants()->whereKey($assistantId)->first();

        if (! $assistant) {
            return response()->json([
                'message' => 'Assistant not found.',
            ], 404);
        }

        [$hasCapacity, $capacityMessage] = $this->canConnectTelegramChannel($assistant);
        if (! $hasCapacity) {
            return response()->json([
                'message' => $capacityMessage,
            ], 422);
        }

        $botToken = trim((string) $validated['bot_token']);
        if ($botToken === '') {
            return response()->json([
                'message' => 'Telegram bot token is required.',
            ], 422);
        }

        try {
            $botProfile = $this->telegramBotApiService()->getMe($botToken);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $botId = trim((string) ($botProfile['id'] ?? ''));
        $botUsername = $this->nullableTrimmedString($botProfile['username'] ?? null);
        $botName = $this->nullableTrimmedString($botProfile['first_name'] ?? null)
            ?? ($botUsername !== null ? '@'.$botUsername : 'Telegram Bot');

        if ($botId === '') {
            return response()->json([
                'message' => 'Telegram bot id is missing in getMe response.',
            ], 422);
        }

        $assistantChannel = AssistantChannel::query()
            ->where('assistant_id', $assistant->id)
            ->where('channel', AssistantChannel::CHANNEL_TELEGRAM)
            ->first();

        $channelSnapshot = $assistantChannel
            ? [
                'name' => $assistantChannel->name,
                'external_account_id' => $assistantChannel->external_account_id,
                'is_active' => (bool) $assistantChannel->is_active,
                'credentials' => is_array($assistantChannel->credentials) ? $assistantChannel->credentials : [],
                'metadata' => is_array($assistantChannel->metadata) ? $assistantChannel->metadata : [],
            ]
            : null;

        $isNewChannel = ! $assistantChannel;

        if (! $assistantChannel) {
            $assistantChannel = new AssistantChannel([
                'user_id' => $user->id,
                'company_id' => $company->id,
                'assistant_id' => $assistant->id,
                'channel' => AssistantChannel::CHANNEL_TELEGRAM,
            ]);
        }

        $secretToken = Str::random(40);
        $credentials = is_array($assistantChannel->credentials) ? $assistantChannel->credentials : [];
        $metadata = is_array($assistantChannel->metadata) ? $assistantChannel->metadata : [];

        $assistantChannel->name = $botName;
        $assistantChannel->external_account_id = $botId;
        $assistantChannel->is_active = true;
        $assistantChannel->credentials = array_replace($credentials, [
            'provider' => 'telegram',
            'bot_token' => $botToken,
            'bot_id' => $botId,
            'bot_username' => $botUsername,
            'webhook_secret' => $secretToken,
            'connected_at' => now()->toIso8601String(),
        ]);
        $assistantChannel->metadata = array_replace_recursive($metadata, array_filter([
            'telegram' => array_filter([
                'bot_id' => $botId,
                'bot_username' => $botUsername,
                'connected_at' => now()->toIso8601String(),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ], static fn (mixed $value): bool => $value !== null && $value !== []));
        $assistantChannel->save();

        $webhookUrl = $this->resolveWebhookUrl($assistantChannel);

        try {
            $setWebhookPayload = $this->telegramBotApiService()->setWebhook(
                $botToken,
                $webhookUrl,
                $secretToken,
            );

            $assistantChannel->credentials = array_replace(
                is_array($assistantChannel->credentials) ? $assistantChannel->credentials : [],
                [
                    'webhook_url' => $webhookUrl,
                    'set_webhook_payload' => $setWebhookPayload,
                ],
            );
            $assistantChannel->metadata = array_replace_recursive(
                is_array($assistantChannel->metadata) ? $assistantChannel->metadata : [],
                [
                    'telegram' => array_filter([
                        'webhook_url' => $webhookUrl,
                        'webhook_set_at' => now()->toIso8601String(),
                    ], static fn (mixed $value): bool => $value !== null && $value !== ''),
                ],
            );
            $assistantChannel->save();
        } catch (Throwable $exception) {
            if ($isNewChannel) {
                $assistantChannel->delete();
            } elseif (is_array($channelSnapshot)) {
                $assistantChannel->forceFill([
                    'name' => $channelSnapshot['name'],
                    'external_account_id' => $channelSnapshot['external_account_id'],
                    'is_active' => (bool) $channelSnapshot['is_active'],
                    'credentials' => is_array($channelSnapshot['credentials']) ? $channelSnapshot['credentials'] : [],
                    'metadata' => is_array($channelSnapshot['metadata']) ? $channelSnapshot['metadata'] : [],
                ])->save();
            }

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        [$subscription, $hasActiveSubscription, $integrationLimit] = $this->subscriptionContext($company);

        return response()->json([
            'message' => 'Telegram bot connected successfully.',
            'channel' => $this->channelPayload($assistantChannel->refresh()),
            'limits' => $this->limitsPayload(
                $company,
                $subscription,
                $hasActiveSubscription,
                $integrationLimit,
                $assistant->id
            ),
            'telegram' => array_filter([
                'bot_id' => $botId,
                'bot_username' => $botUsername,
                'webhook_url' => $webhookUrl,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ]);
    }

    public function webhook(Request $request, int $assistantChannelId): JsonResponse
    {
        $assistantChannel = AssistantChannel::query()
            ->whereKey($assistantChannelId)
            ->where('channel', AssistantChannel::CHANNEL_TELEGRAM)
            ->first();

        if (! $assistantChannel) {
            return response()->json(['ok' => true]);
        }

        if (! $this->isWebhookSecretValid($request, $assistantChannel)) {
            return response()->json([
                'message' => 'Invalid Telegram webhook secret.',
            ], 403);
        }

        try {
            $this->telegramMainWebhookService()->processUpdate($assistantChannel, $request->all());
        } catch (Throwable $exception) {
            report($exception);
        }

        return response()->json(['ok' => true]);
    }

    private function isWebhookSecretValid(Request $request, AssistantChannel $assistantChannel): bool
    {
        $credentials = is_array($assistantChannel->credentials) ? $assistantChannel->credentials : [];
        $expected = trim((string) ($credentials['webhook_secret'] ?? ''));

        if ($expected === '') {
            return true;
        }

        $headerName = trim((string) config(
            'services.telegram.webhook_secret_header',
            'X-Telegram-Bot-Api-Secret-Token'
        ));
        if ($headerName === '') {
            $headerName = 'X-Telegram-Bot-Api-Secret-Token';
        }

        $provided = trim((string) ($request->header($headerName) ?? ''));

        return $provided !== '' && hash_equals($expected, $provided);
    }

    private function canConnectTelegramChannel(Assistant $assistant): array
    {
        $company = $assistant->company;

        if (! $company) {
            return [false, 'Company not found for assistant.'];
        }

        [$_subscription, $hasActiveSubscription, $integrationLimit] = $this->subscriptionContext($company);

        if (! $hasActiveSubscription) {
            return [false, 'Cannot connect integration while subscription is inactive.'];
        }

        if ($integrationLimit <= 0) {
            return [false, 'Integration limit reached for current subscription.'];
        }

        $existing = AssistantChannel::query()
            ->where('assistant_id', $assistant->id)
            ->where('channel', AssistantChannel::CHANNEL_TELEGRAM)
            ->first();

        $activeCount = (int) AssistantChannel::query()
            ->where('assistant_id', $assistant->id)
            ->where('is_active', true)
            ->where('channel', '!=', AssistantChannel::CHANNEL_TELEGRAM)
            ->count();

        if (! ($existing && $existing->is_active) && $activeCount >= $integrationLimit) {
            return [false, 'Integration limit reached for current subscription.'];
        }

        return [true, null];
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

    private function limitsPayload(
        Company $company,
        ?CompanySubscription $subscription,
        bool $hasActiveSubscription,
        int $integrationLimit,
        ?int $assistantId = null,
    ): array {
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

    private function resolveWebhookUrl(AssistantChannel $assistantChannel): string
    {
        $configured = trim((string) config('services.telegram.webhook_url', ''));

        if ($configured !== '') {
            if (str_contains($configured, '{assistant_channel_id}')) {
                return str_replace('{assistant_channel_id}', (string) $assistantChannel->id, $configured);
            }

            return rtrim($configured, '/').'/'.$assistantChannel->id;
        }

        return route('api.integrations.telegram.webhook', [
            'assistantChannelId' => $assistantChannel->id,
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

    private function nullableTrimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function subscriptionService(): CompanySubscriptionService
    {
        return app(CompanySubscriptionService::class);
    }

    private function telegramBotApiService(): TelegramBotApiService
    {
        return app(TelegramBotApiService::class);
    }

    private function telegramMainWebhookService(): TelegramMainWebhookService
    {
        return app(TelegramMainWebhookService::class);
    }
}

