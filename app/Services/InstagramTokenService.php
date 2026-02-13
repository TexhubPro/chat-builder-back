<?php

namespace App\Services;

use App\Models\AssistantChannel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use TexHub\Meta\Models\InstagramIntegration;

class InstagramTokenService
{
    public function exchangeForLongLivedToken(string $accessToken): ?array
    {
        $normalizedToken = trim($accessToken);

        if ($normalizedToken === '') {
            return null;
        }

        $query = [
            'grant_type' => 'ig_exchange_token',
            'access_token' => $normalizedToken,
        ];

        $clientSecret = $this->instagramAppSecret();
        if ($clientSecret !== '') {
            $query['client_secret'] = $clientSecret;
        }

        return $this->requestToken('/access_token', $query);
    }

    public function refreshLongLivedToken(string $accessToken): ?array
    {
        $normalizedToken = trim($accessToken);

        if ($normalizedToken === '') {
            return null;
        }

        return $this->requestToken('/refresh_access_token', [
            'grant_type' => 'ig_refresh_token',
            'access_token' => $normalizedToken,
        ]);
    }

    public function ensureTokenIsFresh(
        InstagramIntegration $integration,
        int $refreshGraceSeconds = 900,
    ): InstagramIntegration {
        $accessToken = trim((string) $integration->access_token);

        if ($accessToken === '') {
            return $integration;
        }

        $refreshThreshold = now()->addSeconds(max($refreshGraceSeconds, 0));
        $shouldRefresh = $integration->token_expires_at === null
            || $integration->token_expires_at->lte($refreshThreshold);

        if (! $shouldRefresh) {
            return $integration;
        }

        $tokenPayload = $this->refreshLongLivedToken($accessToken);

        if ($tokenPayload === null) {
            $tokenPayload = $this->exchangeForLongLivedToken($accessToken);
        }

        if (! is_array($tokenPayload)) {
            return $integration;
        }

        $freshAccessToken = trim((string) ($tokenPayload['access_token'] ?? ''));

        if ($freshAccessToken === '') {
            return $integration;
        }

        $expiresAt = $this->resolveExpiresAt($tokenPayload);

        $integration->forceFill([
            'access_token' => $freshAccessToken,
            'token_expires_at' => $expiresAt,
        ])->save();

        $integration->refresh();
        $this->syncAssistantChannelsToken($integration, $freshAccessToken, $expiresAt);

        return $integration;
    }

    public function resolveExpiresAt(?array $tokenPayload): ?Carbon
    {
        if (! is_array($tokenPayload)) {
            return null;
        }

        if (! is_numeric($tokenPayload['expires_in'] ?? null)) {
            return null;
        }

        return now()->addSeconds((int) $tokenPayload['expires_in']);
    }

    private function syncAssistantChannelsToken(
        InstagramIntegration $integration,
        string $accessToken,
        ?Carbon $expiresAt,
    ): void {
        $externalIds = array_values(array_unique(array_filter([
            trim((string) ($integration->receiver_id ?? '')),
            trim((string) ($integration->instagram_user_id ?? '')),
        ], static fn (string $value): bool => $value !== '')));

        if ($externalIds === []) {
            return;
        }

        AssistantChannel::query()
            ->where('user_id', $integration->user_id)
            ->where('channel', AssistantChannel::CHANNEL_INSTAGRAM)
            ->whereIn('external_account_id', $externalIds)
            ->get()
            ->each(function (AssistantChannel $assistantChannel) use ($accessToken, $expiresAt): void {
                $credentials = is_array($assistantChannel->credentials) ? $assistantChannel->credentials : [];
                $credentials['access_token'] = $accessToken;
                $credentials['token_expires_at'] = $expiresAt?->toIso8601String();

                $assistantChannel->forceFill([
                    'credentials' => $credentials,
                ])->save();
            });
    }

    private function requestToken(string $path, array $query): ?array
    {
        $graphBase = rtrim((string) config('meta.instagram.graph_base', 'https://graph.instagram.com'), '/');
        $response = Http::get($graphBase.$path, array_filter(
            $query,
            static fn (mixed $value): bool => $value !== null && $value !== ''
        ));

        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : null;
    }

    private function instagramAppSecret(): string
    {
        return trim((string) config('meta.instagram.app_secret', ''));
    }
}
