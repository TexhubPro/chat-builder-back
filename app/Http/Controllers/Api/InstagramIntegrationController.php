<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assistant;
use App\Models\AssistantChannel;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanySubscriptionService;
use App\Services\InstagramTokenService;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use TexHub\Meta\Models\InstagramIntegration;
use Throwable;

class InstagramIntegrationController extends Controller
{
    private const OAUTH_STATE_CACHE_PREFIX = 'instagram:oauth:state:';

    public function redirect(Request $request, int $assistantId): JsonResponse
    {
        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $assistant = $company->assistants()->whereKey($assistantId)->first();

        if (! $assistant) {
            return response()->json([
                'message' => 'Assistant not found.',
            ], 404);
        }

        [$hasCapacity, $capacityMessage] = $this->canConnectInstagramChannel($assistant);
        if (! $hasCapacity) {
            return response()->json([
                'message' => $capacityMessage,
            ], 422);
        }

        $state = Str::random(64);

        Cache::put(
            $this->stateCacheKey($state),
            [
                'user_id' => $user->id,
                'company_id' => $company->id,
                'assistant_id' => $assistant->id,
                'ua_hash' => hash('sha256', (string) $request->userAgent()),
                'return_url' => $this->frontendRedirectUrl(),
            ],
            now()->addMinutes($this->oauthStateTtlMinutes())
        );

        try {
            $authorizationUrl = $this->buildAuthorizationUrl($state);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'authorization_url' => $authorizationUrl,
            'state_ttl_seconds' => $this->oauthStateTtlMinutes() * 60,
        ]);
    }

    public function callback(Request $request): RedirectResponse
    {
        $frontendRedirectUrl = $this->frontendRedirectUrl();
        $assistantId = null;

        try {
            $state = trim((string) $request->query('state', ''));
            $statePayload = $this->consumeStatePayload($state, $request);

            if ($statePayload === null) {
                return redirect()->away($this->appendQuery($frontendRedirectUrl, [
                    'instagram_status' => 'error',
                    'instagram_error' => 'OAuth state is invalid or expired.',
                ]));
            }

            $frontendRedirectUrl = $this->sanitizeFrontendRedirectUrl(
                (string) ($statePayload['return_url'] ?? '')
            );
            $assistantId = (int) ($statePayload['assistant_id'] ?? 0);

            if ($request->filled('error')) {
                $errorMessage = trim((string) $request->query('error_description', ''));
                if ($errorMessage === '') {
                    $errorMessage = trim((string) $request->query('error', 'Instagram authorization failed.'));
                }

                return redirect()->away($this->appendQuery($frontendRedirectUrl, [
                    'instagram_status' => 'error',
                    'assistant_id' => $assistantId > 0 ? $assistantId : null,
                    'instagram_error' => $errorMessage,
                ]));
            }

            $code = trim((string) $request->query('code', ''));
            if ($code === '') {
                return redirect()->away($this->appendQuery($frontendRedirectUrl, [
                    'instagram_status' => 'error',
                    'assistant_id' => $assistantId > 0 ? $assistantId : null,
                    'instagram_error' => 'Instagram callback code is missing.',
                ]));
            }

            $tokenResponse = $this->exchangeCodeForToken($code);
            $accessToken = trim((string) ($tokenResponse['access_token'] ?? ''));
            $expiresIn = $tokenResponse['expires_in'] ?? null;
            $instagramUserId = trim((string) ($tokenResponse['user_id'] ?? ''));

            if ($accessToken === '') {
                return redirect()->away($this->appendQuery($frontendRedirectUrl, [
                    'instagram_status' => 'error',
                    'assistant_id' => $assistantId > 0 ? $assistantId : null,
                    'instagram_error' => 'Instagram access token is missing.',
                ]));
            }

            $longLivedResponse = $this->exchangeForLongLivedToken($accessToken);
            if ($longLivedResponse !== null) {
                $candidateToken = trim((string) ($longLivedResponse['access_token'] ?? ''));
                if ($candidateToken !== '') {
                    $accessToken = $candidateToken;
                }

                if (is_numeric($longLivedResponse['expires_in'] ?? null)) {
                    $expiresIn = (int) $longLivedResponse['expires_in'];
                }
            }

            $profiles = $this->fetchProfileVariants($accessToken);
            $profile = $profiles[0];
            $profileId = trim((string) ($profile['id'] ?? ''));
            $profileUserId = trim((string) ($profile['user_id'] ?? ''));
            $username = $this->firstNonEmptyString(array_map(
                fn (array $item): string => trim((string) ($item['username'] ?? '')),
                $profiles,
            ));
            $profilePictureUrl = $this->firstNonEmptyString(array_map(
                fn (array $item): string => trim((string) ($item['profile_picture_url'] ?? '')),
                $profiles,
            ));

            $receiverIdCandidates = $this->extractReceiverIdCandidates($profiles, $instagramUserId);

            if ($instagramUserId === '') {
                $instagramUserId = $receiverIdCandidates[0] ?? '';
            }

            if ($instagramUserId === '' && $receiverIdCandidates === []) {
                return redirect()->away($this->appendQuery($frontendRedirectUrl, [
                    'instagram_status' => 'error',
                    'assistant_id' => $assistantId > 0 ? $assistantId : null,
                    'instagram_error' => 'Instagram account id was not returned.',
                ]));
            }

            $user = User::query()->find((int) ($statePayload['user_id'] ?? 0));
            if (! $user) {
                return redirect()->away($this->appendQuery($frontendRedirectUrl, [
                    'instagram_status' => 'error',
                    'assistant_id' => $assistantId > 0 ? $assistantId : null,
                    'instagram_error' => 'User was not found.',
                ]));
            }

            $company = Company::query()
                ->whereKey((int) ($statePayload['company_id'] ?? 0))
                ->where('user_id', $user->id)
                ->first();

            if (! $company) {
                $company = $this->subscriptionService()->provisionDefaultWorkspaceForUser($user->id, $user->name);
            }

            $assistant = $company->assistants()->whereKey($assistantId)->first();
            if (! $assistant) {
                return redirect()->away($this->appendQuery($frontendRedirectUrl, [
                    'instagram_status' => 'error',
                    'assistant_id' => $assistantId > 0 ? $assistantId : null,
                    'instagram_error' => 'Assistant not found.',
                ]));
            }

            [$hasCapacity, $capacityMessage] = $this->canConnectInstagramChannel($assistant);
            if (! $hasCapacity) {
                return redirect()->away($this->appendQuery($frontendRedirectUrl, [
                    'instagram_status' => 'error',
                    'assistant_id' => $assistantId > 0 ? $assistantId : null,
                    'instagram_error' => $capacityMessage,
                ]));
            }

            $integrationKey = $instagramUserId !== ''
                ? $instagramUserId
                : ($receiverIdCandidates[0] ?? '');
            $existingIntegration = InstagramIntegration::query()
                ->where('user_id', $user->id)
                ->where('instagram_user_id', $integrationKey)
                ->first();

            $subscribedFields = $this->instagramSubscribedFields();
            [$receiverId, $subscriptionPayload] = $this->resolveReceiverIdAndSubscriptionPayload(
                $accessToken,
                array_values(array_unique(array_filter([
                    ...$receiverIdCandidates,
                    $integrationKey,
                ], static fn (string $value): bool => $value !== ''))),
                $subscribedFields,
            );

            [$avatarPath, $avatarUrl] = $this->storeAvatarFromProfile(
                $profilePictureUrl,
                $existingIntegration?->avatar_path,
                $user->id,
                $integrationKey
            );

            $expiresAt = is_numeric($expiresIn)
                ? now()->addSeconds((int) $expiresIn)
                : $this->instagramTokenService()->resolveExpiresAt($longLivedResponse);

            $integration = InstagramIntegration::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'instagram_user_id' => $integrationKey,
                ],
                [
                    'username' => $username,
                    'receiver_id' => $receiverId !== '' ? $receiverId : null,
                    'access_token' => $accessToken,
                    'token_expires_at' => $expiresAt,
                    'profile_picture_url' => $profilePictureUrl,
                    'avatar_path' => $avatarPath,
                    'is_active' => true,
                ]
            );

            $assistantChannel = AssistantChannel::query()
                ->where('assistant_id', $assistant->id)
                ->where('channel', AssistantChannel::CHANNEL_INSTAGRAM)
                ->first();

            $externalAccountId = $receiverId !== '' ? $receiverId : $integrationKey;
            $existingMetadata = is_array($assistantChannel?->metadata) ? $assistantChannel->metadata : [];

            if (! $assistantChannel) {
                $assistantChannel = new AssistantChannel([
                    'user_id' => $user->id,
                    'company_id' => $company->id,
                    'assistant_id' => $assistant->id,
                    'channel' => AssistantChannel::CHANNEL_INSTAGRAM,
                ]);
            }

            $assistantChannel->name = $username ?? $assistantChannel->name ?? 'Instagram';
            $assistantChannel->external_account_id = $externalAccountId;
            $assistantChannel->is_active = true;
            $assistantChannel->credentials = [
                'provider' => 'instagram',
                'access_token' => $accessToken,
                'token_expires_at' => $expiresAt?->toIso8601String(),
                'instagram_user_id' => $integration->instagram_user_id,
                'receiver_id' => $integration->receiver_id,
            ];
            $assistantChannel->metadata = array_replace_recursive($existingMetadata, array_filter([
                'instagram_integration_id' => (int) $integration->id,
                'username' => $username,
                'oauth_user_id' => $instagramUserId,
                'profile_id' => $profileId,
                'profile_user_id' => $profileUserId !== '' ? $profileUserId : null,
                'profile_picture_url' => $profilePictureUrl,
                'avatar_path' => $avatarPath,
                'avatar_url' => $avatarUrl,
                'subscribed_fields' => $subscribedFields,
                'subscribed_apps_payload' => is_array($subscriptionPayload) ? $subscriptionPayload : null,
                'subscribed_at' => is_array($subscriptionPayload) ? now()->toIso8601String() : null,
                'connected_at' => now()->toIso8601String(),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''));
            $assistantChannel->save();

            return redirect()->away($this->appendQuery($frontendRedirectUrl, [
                'instagram_status' => 'connected',
                'assistant_id' => $assistant->id,
            ]));
        } catch (Throwable $exception) {
            Log::error('Instagram integration callback failed.', [
                'assistant_id' => $assistantId,
                'state_present' => trim((string) $request->query('state', '')) !== '',
                'error_class' => $exception::class,
                'error_message' => $exception->getMessage(),
            ]);

            report($exception);

            return redirect()->away($this->appendQuery($frontendRedirectUrl, [
                'instagram_status' => 'error',
                'assistant_id' => $assistantId > 0 ? $assistantId : null,
                'instagram_error' => $this->publicInstagramErrorMessage($exception),
            ]));
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

    private function canConnectInstagramChannel(Assistant $assistant): array
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
            ->where('channel', AssistantChannel::CHANNEL_INSTAGRAM)
            ->first();

        $activeCount = (int) AssistantChannel::query()
            ->where('assistant_id', $assistant->id)
            ->where('is_active', true)
            ->where('channel', '!=', AssistantChannel::CHANNEL_INSTAGRAM)
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

    private function subscriptionService(): CompanySubscriptionService
    {
        return app(CompanySubscriptionService::class);
    }

    private function buildAuthorizationUrl(string $state): string
    {
        $authUrl = trim((string) config('meta.instagram.auth_url', ''));
        if ($authUrl === '') {
            $authUrl = 'https://www.instagram.com/oauth/authorize';
        }

        $parsed = parse_url($authUrl);
        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            $parsed = [
                'scheme' => 'https',
                'host' => 'www.instagram.com',
                'path' => '/oauth/authorize',
            ];
        }

        $query = [];
        parse_str((string) ($parsed['query'] ?? ''), $query);

        $clientId = trim((string) config('meta.instagram.app_id', ''));
        $scopes = trim((string) config('meta.instagram.scopes', ''));
        $redirectUri = $this->oauthRedirectUri();

        if ($clientId !== '') {
            $query['client_id'] = $clientId;
        } elseif (! isset($query['client_id']) || trim((string) $query['client_id']) === '') {
            $query['client_id'] = $clientId;
        }

        // Keep authorize and token exchange redirect_uri strictly identical.
        $query['redirect_uri'] = $redirectUri;

        if (! isset($query['response_type']) || trim((string) $query['response_type']) === '') {
            $query['response_type'] = 'code';
        }

        if ($scopes !== '') {
            $query['scope'] = $scopes;
        } elseif (! isset($query['scope']) || trim((string) $query['scope']) === '') {
            $query['scope'] = $scopes;
        }

        if (trim((string) ($query['client_id'] ?? '')) === '') {
            throw new \RuntimeException('Instagram app id is not configured.');
        }

        if (trim((string) ($query['redirect_uri'] ?? '')) === '') {
            throw new \RuntimeException('Instagram redirect uri is not configured.');
        }

        $query['state'] = $state;

        $scheme = (string) $parsed['scheme'];
        $host = (string) $parsed['host'];
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path = (string) ($parsed['path'] ?? '');
        if ($path === '') {
            $path = '/oauth/authorize';
        }

        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';
        $normalizedQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return "{$scheme}://{$host}{$port}{$path}"
            . ($normalizedQuery !== '' ? "?{$normalizedQuery}" : '')
            . $fragment;
    }

    private function oauthRedirectUri(): string
    {
        $configured = trim((string) config('meta.instagram.redirect_uri', ''));

        if ($configured !== '') {
            return $configured;
        }

        return route('api.integrations.instagram.callback');
    }

    private function exchangeCodeForToken(string $code): array
    {
        $appId = trim((string) config('meta.instagram.app_id', ''));
        $appSecret = trim((string) config('meta.instagram.app_secret', ''));

        if ($appId === '' || $appSecret === '') {
            throw new \RuntimeException('Instagram app credentials are not configured.');
        }

        $response = Http::asForm()->post('https://api.instagram.com/oauth/access_token', [
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->oauthRedirectUri(),
            'code' => $code,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                $this->httpErrorMessage(
                    'Failed to exchange Instagram authorization code.',
                    $response
                )
            );
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new \RuntimeException('Invalid Instagram token response.');
        }

        return $payload;
    }

    private function exchangeForLongLivedToken(string $accessToken): ?array
    {
        return $this->instagramTokenService()->exchangeForLongLivedToken($accessToken);
    }

    private function shouldSubscribeInstagramWebhooks(): bool
    {
        return (bool) config('meta.instagram.subscribe_after_connect', true);
    }

    private function instagramSubscribedFields(): string
    {
        $configured = trim((string) config('meta.instagram.subscribed_fields', 'messages'));

        return $configured !== '' ? $configured : 'messages';
    }

    private function subscribeInstagramAccountToWebhook(
        string $accessToken,
        string $instagramUserId,
        string $subscribedFields,
    ): array {
        if (trim($accessToken) === '') {
            throw new \RuntimeException('Instagram access token is missing for webhook subscription.');
        }

        if (trim($instagramUserId) === '') {
            throw new \RuntimeException('Instagram account id is missing for webhook subscription.');
        }

        $graphBase = rtrim((string) config('meta.instagram.graph_base', 'https://graph.instagram.com'), '/');
        $apiVersion = trim((string) config('meta.instagram.api_version', 'v23.0'));
        if ($apiVersion === '') {
            $apiVersion = 'v23.0';
        }

        $endpoint = $graphBase.'/'.$apiVersion.'/'.$instagramUserId.'/subscribed_apps';
        $query = [
            'subscribed_fields' => $subscribedFields,
            'access_token' => $accessToken,
        ];

        $response = Http::post($endpoint.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));

        if (! $response->successful()) {
            throw new \RuntimeException(
                $this->httpErrorMessage(
                    'Failed to subscribe Instagram account to webhook events.',
                    $response
                )
            );
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new \RuntimeException('Invalid Instagram subscribed_apps response.');
        }

        $success = $payload['success'] ?? null;
        if ($success === false) {
            $error = $this->extractInstagramApiErrorMessage($payload);
            throw new \RuntimeException(
                $error !== null
                    ? 'Instagram webhook subscription request was rejected: '.$error
                    : 'Instagram webhook subscription request was rejected.'
            );
        }

        return $payload;
    }

    private function resolveReceiverIdAndSubscriptionPayload(
        string $accessToken,
        array $receiverIdCandidates,
        string $subscribedFields,
    ): array {
        $normalizedCandidates = array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $receiverIdCandidates),
            static fn (string $value): bool => $value !== ''
        )));

        if ($normalizedCandidates === []) {
            throw new \RuntimeException('Instagram account id was not returned.');
        }

        if (! $this->shouldSubscribeInstagramWebhooks()) {
            return [$normalizedCandidates[0], null];
        }

        $lastException = null;

        foreach ($normalizedCandidates as $candidateId) {
            try {
                $payload = $this->subscribeInstagramAccountToWebhook(
                    $accessToken,
                    $candidateId,
                    $subscribedFields,
                );

                return [$candidateId, $payload];
            } catch (Throwable $exception) {
                $lastException = $exception;
            }
        }

        if ($lastException !== null) {
            throw $lastException;
        }

        return [$normalizedCandidates[0], null];
    }

    private function fetchProfile(string $accessToken): array
    {
        $graphBase = rtrim((string) config('meta.instagram.graph_base', 'https://graph.instagram.com'), '/');

        $payload = $this->requestProfileFromGraphBase($graphBase, $accessToken, true);
        if (! is_array($payload)) {
            throw new \RuntimeException('Invalid Instagram profile response.');
        }

        return $payload;
    }

    private function fetchProfileVariants(string $accessToken): array
    {
        $variants = [];
        $configuredGraphBase = rtrim((string) config('meta.instagram.graph_base', 'https://graph.instagram.com'), '/');
        $graphBases = array_values(array_unique(array_filter([
            $configuredGraphBase,
            'https://graph.facebook.com',
        ], static fn (string $value): bool => trim($value) !== '')));

        foreach ($graphBases as $index => $graphBase) {
            $profiles = $this->requestProfileFromGraphBase($graphBase, $accessToken, $index === 0);

            foreach ($profiles as $profile) {
                if (is_array($profile)) {
                    $variants[] = $profile;
                }
            }
        }

        if ($variants === []) {
            throw new \RuntimeException('Failed to fetch Instagram profile.');
        }

        return $variants;
    }

    private function requestProfileFromGraphBase(
        string $graphBase,
        string $accessToken,
        bool $throwOnFailure,
    ): array {
        $apiVersion = trim((string) config('meta.instagram.api_version', 'v23.0'));
        if ($apiVersion === '') {
            $apiVersion = 'v23.0';
        }

        $endpoints = array_values(array_unique([
            rtrim($graphBase, '/').'/me',
            rtrim($graphBase, '/').'/'.$apiVersion.'/me',
        ]));

        $variants = [];
        $lastErrorResponse = null;
        $hadInvalidPayload = false;

        foreach ($endpoints as $endpoint) {
            $response = Http::get($endpoint, [
                'fields' => 'id,user_id,username,profile_picture_url',
                'access_token' => $accessToken,
            ]);

            if (! $response->successful()) {
                $lastErrorResponse = $response;
                continue;
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                $hadInvalidPayload = true;
                continue;
            }

            $variants[] = $payload;
        }

        if ($throwOnFailure && $variants === []) {
            if ($lastErrorResponse instanceof Response) {
                throw new \RuntimeException(
                    $this->httpErrorMessage('Failed to fetch Instagram profile.', $lastErrorResponse)
                );
            }

            if ($hadInvalidPayload) {
                throw new \RuntimeException('Invalid Instagram profile response.');
            }

            throw new \RuntimeException('Failed to fetch Instagram profile.');
        }

        return $variants;
    }

    private function extractReceiverIdCandidates(array $profiles, string $oauthUserId): array
    {
        $candidates = [];
        $order = 0;

        $registerCandidate = static function (array &$buffer, string $value, int $sourcePriority, int $order): void {
            $normalized = trim($value);
            if ($normalized === '') {
                return;
            }

            $score = $sourcePriority;

            if (str_starts_with($normalized, '178')) {
                $score += 100;
            }

            if (ctype_digit($normalized)) {
                $score += 20;
            }

            if (strlen($normalized) >= 12) {
                $score += 10;
            }

            if (! array_key_exists($normalized, $buffer)) {
                $buffer[$normalized] = [
                    'id' => $normalized,
                    'score' => $score,
                    'order' => $order,
                ];

                return;
            }

            $buffer[$normalized]['score'] = max((int) $buffer[$normalized]['score'], $score);
            $buffer[$normalized]['order'] = min((int) $buffer[$normalized]['order'], $order);
        };

        foreach ($profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $registerCandidate($candidates, (string) ($profile['id'] ?? ''), 300, $order);
            $order += 1;
            $registerCandidate($candidates, (string) ($profile['user_id'] ?? ''), 200, $order);
            $order += 1;
        }

        $registerCandidate($candidates, $oauthUserId, 100, $order);

        $normalizedCandidates = array_values($candidates);
        usort($normalizedCandidates, static function (array $left, array $right): int {
            $scoreComparison = ((int) ($right['score'] ?? 0)) <=> ((int) ($left['score'] ?? 0));

            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            return ((int) ($left['order'] ?? 0)) <=> ((int) ($right['order'] ?? 0));
        });

        return array_values(array_map(
            static fn (array $item): string => (string) ($item['id'] ?? ''),
            array_filter($normalizedCandidates, static fn (array $item): bool => trim((string) ($item['id'] ?? '')) !== '')
        ));
    }

    private function firstNonEmptyString(array $values): ?string
    {
        foreach ($values as $value) {
            $normalized = trim((string) $value);

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function stateCacheKey(string $state): string
    {
        return self::OAUTH_STATE_CACHE_PREFIX . hash('sha256', $state);
    }

    private function consumeStatePayload(string $state, Request $request): ?array
    {
        if ($state === '') {
            return null;
        }

        $payload = Cache::pull($this->stateCacheKey($state));
        if (! is_array($payload)) {
            return null;
        }

        $userAgentHash = hash('sha256', (string) $request->userAgent());
        if (($payload['ua_hash'] ?? null) !== $userAgentHash) {
            return null;
        }

        return $payload;
    }

    private function oauthStateTtlMinutes(): int
    {
        return max((int) config('meta.instagram.oauth_state_ttl_minutes', 15), 1);
    }

    private function frontendRedirectUrl(): string
    {
        return $this->sanitizeFrontendRedirectUrl((string) config(
            'meta.instagram.frontend_redirect_url',
            'http://localhost:5173/integrations'
        ));
    }

    private function sanitizeFrontendRedirectUrl(string $candidate): string
    {
        $fallback = 'http://localhost:5173/integrations';
        $normalized = trim($candidate);

        if ($normalized === '' || ! filter_var($normalized, FILTER_VALIDATE_URL)) {
            return $fallback;
        }

        $scheme = (string) parse_url($normalized, PHP_URL_SCHEME);
        if (! in_array(Str::lower($scheme), ['http', 'https'], true)) {
            return $fallback;
        }

        return $normalized;
    }

    private function appendQuery(string $url, array $params): string
    {
        $parsed = parse_url($url);

        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            return $url;
        }

        $existing = [];
        parse_str((string) ($parsed['query'] ?? ''), $existing);

        $filteredParams = array_filter(
            $params,
            static fn (mixed $value): bool => $value !== null && $value !== ''
        );

        $query = http_build_query(
            array_merge($existing, $filteredParams),
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path = $parsed['path'] ?? '';
        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

        return "{$parsed['scheme']}://{$parsed['host']}{$port}{$path}"
            . ($query !== '' ? "?{$query}" : '')
            . $fragment;
    }

    private function storeAvatarFromProfile(
        ?string $profilePictureUrl,
        ?string $existingPath,
        int $userId,
        string $instagramUserId
    ): array {
        if (! is_string($profilePictureUrl) || trim($profilePictureUrl) === '') {
            return [$existingPath, $this->resolveAvatarPublicUrl($existingPath)];
        }

        $disk = trim((string) config('meta.instagram.avatar_disk', 'public'));
        if ($disk === '') {
            $disk = 'public';
        }

        $directory = trim((string) config('meta.instagram.avatar_dir', 'instagram/avatars'), '/');
        if ($directory === '') {
            $directory = 'instagram/avatars';
        }

        try {
            $response = Http::timeout(15)->get($profilePictureUrl);
            if (! $response->successful()) {
                return [$existingPath, $this->resolveAvatarPublicUrl($existingPath)];
            }

            $contentType = Str::lower(trim((string) $response->header('Content-Type', '')));
            if (! Str::startsWith($contentType, 'image/')) {
                return [$existingPath, $this->resolveAvatarPublicUrl($existingPath)];
            }

            $extension = $this->extensionFromContentType($contentType);
            $fileName = $userId . '-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $instagramUserId) . '-' . Str::random(10) . '.' . $extension;
            $path = $directory . '/' . $fileName;

            Storage::disk($disk)->put($path, $response->body());

            if (is_string($existingPath) && trim($existingPath) !== '' && $existingPath !== $path) {
                Storage::disk($disk)->delete($existingPath);
            }

            return [$path, $this->resolveAvatarPublicUrl($path, $disk)];
        } catch (Throwable) {
            return [$existingPath, $this->resolveAvatarPublicUrl($existingPath)];
        }
    }

    private function resolveAvatarPublicUrl(?string $path, string $disk = 'public'): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $url = Storage::disk($disk)->url($path);

        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        return URL::to($url);
    }

    private function extensionFromContentType(string $contentType): string
    {
        return match (true) {
            Str::contains($contentType, 'png') => 'png',
            Str::contains($contentType, 'webp') => 'webp',
            Str::contains($contentType, 'gif') => 'gif',
            default => 'jpg',
        };
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function instagramTokenService(): InstagramTokenService
    {
        return app(InstagramTokenService::class);
    }

    private function publicInstagramErrorMessage(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        if ($message === '') {
            return 'Instagram integration failed. Please try again.';
        }

        return Str::limit($message, 240);
    }

    private function httpErrorMessage(string $prefix, Response $response): string
    {
        $apiError = $this->extractInstagramApiErrorMessage($response->json());

        if ($apiError !== null) {
            return $prefix.' '.$apiError;
        }

        $rawBody = trim($response->body());
        if ($rawBody !== '') {
            return $prefix.' '.Str::limit($rawBody, 200);
        }

        return $prefix.' HTTP '.$response->status().'.';
    }

    private function extractInstagramApiErrorMessage(mixed $payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        $errorMessage = $payload['error']['message'] ?? $payload['message'] ?? null;
        if (! is_string($errorMessage)) {
            return null;
        }

        $normalized = trim($errorMessage);

        return $normalized === '' ? null : $normalized;
    }
}
