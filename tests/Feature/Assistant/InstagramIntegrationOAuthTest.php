<?php

use App\Models\Assistant;
use App\Models\AssistantChannel;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use TexHub\Meta\Models\InstagramIntegration;

uses(RefreshDatabase::class);

function instagramOauthTestContext(): array
{
    $user = User::factory()->create([
        'status' => true,
        'email_verified_at' => now(),
    ]);

    $company = Company::query()->create([
        'user_id' => $user->id,
        'name' => 'Instagram OAuth Company',
        'slug' => 'instagram-oauth-company-'.$user->id,
        'status' => Company::STATUS_ACTIVE,
    ]);

    $plan = SubscriptionPlan::query()->create([
        'code' => 'instagram-oauth-plan-'.$user->id,
        'name' => 'Instagram OAuth Plan',
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
        'chat_count_current_period' => 0,
        'chat_period_started_at' => now()->subDay(),
        'chat_period_ends_at' => now()->addDays(29),
    ]);

    $assistant = Assistant::query()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'name' => 'Instagram Assistant',
        'is_active' => true,
    ]);

    $token = $user->createToken('frontend')->plainTextToken;

    return [$user, $company, $assistant, $token];
}

test('instagram oauth start returns authorization url with state', function () {
    [, , $assistant, $token] = instagramOauthTestContext();

    config()->set('meta.instagram.auth_url', 'https://www.instagram.com/oauth/authorize');
    config()->set('meta.instagram.app_id', 'test-app-id');
    config()->set('meta.instagram.scopes', 'instagram_basic,instagram_manage_messages');
    config()->set('meta.instagram.redirect_uri', 'http://localhost:8000/api/integrations/instagram/callback');

    $response = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('User-Agent', 'Pest Browser Agent')
        ->postJson('/api/assistant-channels/'.$assistant->id.'/instagram/connect')
        ->assertOk()
        ->json();

    $authorizationUrl = (string) ($response['authorization_url'] ?? '');
    parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);

    expect($authorizationUrl)->toContain('https://www.instagram.com/oauth/authorize');
    expect((string) ($query['client_id'] ?? ''))->toBe('test-app-id');
    expect((string) ($query['redirect_uri'] ?? ''))->toBe('http://localhost:8000/api/integrations/instagram/callback');
    expect((string) ($query['response_type'] ?? ''))->toBe('code');
    expect((string) ($query['scope'] ?? ''))->toBe('instagram_basic,instagram_manage_messages');
    expect((string) ($query['state'] ?? ''))->not->toBe('');
});

test('instagram oauth start supports full auth url template from config', function () {
    [, , $assistant, $token] = instagramOauthTestContext();

    config()->set(
        'meta.instagram.auth_url',
        'https://www.instagram.com/oauth/authorize?force_reauth=true&client_id=881014378180078&redirect_uri=https%3A%2F%2Fhominine-elsa-nonaspirated.ngrok-free.dev%2Fcallback&response_type=code&scope=instagram_business_basic%2Cinstagram_business_manage_messages%2Cinstagram_business_manage_comments%2Cinstagram_business_content_publish%2Cinstagram_business_manage_insights'
    );
    config()->set('meta.instagram.redirect_uri', 'https://hominine-elsa-nonaspirated.ngrok-free.dev/callback');
    config()->set('meta.instagram.app_id', '');
    config()->set('meta.instagram.scopes', '');

    $response = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('User-Agent', 'Pest Browser Agent')
        ->postJson('/api/assistant-channels/'.$assistant->id.'/instagram/connect')
        ->assertOk()
        ->json();

    $authorizationUrl = (string) ($response['authorization_url'] ?? '');
    parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);

    expect($authorizationUrl)->toContain('https://www.instagram.com/oauth/authorize');
    expect((string) ($query['force_reauth'] ?? ''))->toBe('true');
    expect((string) ($query['client_id'] ?? ''))->toBe('881014378180078');
    expect((string) ($query['redirect_uri'] ?? ''))->toBe('https://hominine-elsa-nonaspirated.ngrok-free.dev/callback');
    expect((string) ($query['response_type'] ?? ''))->toBe('code');
    expect((string) ($query['scope'] ?? ''))->toContain('instagram_business_manage_messages');
    expect((string) ($query['state'] ?? ''))->not->toBe('');
});

test('instagram oauth callback stores integration and links assistant channel', function () {
    [$user, , $assistant, $token] = instagramOauthTestContext();

    config()->set('meta.instagram.app_id', 'test-app-id');
    config()->set('meta.instagram.app_secret', 'test-app-secret');
    config()->set('meta.instagram.redirect_uri', 'http://localhost:8000/api/integrations/instagram/callback');
    config()->set('meta.instagram.frontend_redirect_url', 'http://localhost:5173/integrations');
    config()->set('meta.instagram.graph_base', 'https://graph.instagram.com');
    config()->set('meta.instagram.api_version', 'v23.0');
    config()->set('meta.instagram.avatar_disk', 'public');
    config()->set('meta.instagram.avatar_dir', 'instagram/avatars');
    config()->set('meta.instagram.subscribe_after_connect', true);
    config()->set('meta.instagram.subscribed_fields', 'messages');

    Storage::fake('public');

    Http::fake([
        'https://api.instagram.com/oauth/access_token' => Http::response([
            'access_token' => 'short_token_123',
            'user_id' => '178900000000',
            'expires_in' => 3600,
        ], 200),
        'https://graph.instagram.com/access_token*' => Http::response([
            'access_token' => 'long_token_123',
            'expires_in' => 5184000,
        ], 200),
        'https://graph.instagram.com/me*' => Http::response([
            'id' => '178900000000',
            'username' => 'safe_trade',
            'profile_picture_url' => 'https://cdn.example.com/avatar.jpg',
        ], 200),
        'https://graph.instagram.com/v23.0/178900000000/subscribed_apps*' => Http::response([
            'success' => true,
        ], 200),
        'https://cdn.example.com/avatar.jpg' => Http::response(
            'fake-image',
            200,
            ['Content-Type' => 'image/jpeg']
        ),
    ]);

    $startPayload = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('User-Agent', 'Pest Browser Agent')
        ->postJson('/api/assistant-channels/'.$assistant->id.'/instagram/connect')
        ->assertOk()
        ->json();

    $authorizationUrl = (string) ($startPayload['authorization_url'] ?? '');
    parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);
    $state = (string) ($query['state'] ?? '');

    $response = $this
        ->withHeader('User-Agent', 'Pest Browser Agent')
        ->get('/api/integrations/instagram/callback?code=test_code&state='.urlencode($state));

    $response->assertRedirect();

    $location = (string) $response->headers->get('Location');
    expect($location)->toContain('instagram_status=connected');
    expect($location)->toContain('assistant_id='.$assistant->id);

    $assistantChannel = AssistantChannel::query()
        ->where('assistant_id', $assistant->id)
        ->where('channel', 'instagram')
        ->first();

    expect($assistantChannel)->not->toBeNull();
    expect($assistantChannel?->is_active)->toBeTrue();
    expect($assistantChannel?->external_account_id)->toBe('178900000000');
    expect((string) data_get($assistantChannel?->metadata, 'username'))->toBe('safe_trade');
    expect((bool) data_get($assistantChannel?->metadata, 'subscribed_apps_payload.success'))->toBeTrue();

    $integration = InstagramIntegration::query()
        ->where('user_id', $user->id)
        ->where('instagram_user_id', '178900000000')
        ->first();

    expect($integration)->not->toBeNull();
    expect((string) $integration?->receiver_id)->toBe('178900000000');
    expect((bool) $integration?->is_active)->toBeTrue();
    expect((string) $integration?->access_token)->toBe('long_token_123');
    expect($integration?->token_expires_at)->not->toBeNull();
    expect($integration?->token_expires_at?->greaterThan(now()->addDays(55)))->toBeTrue();
    expect((string) $integration?->avatar_path)->not->toBe('');

    Storage::disk('public')->assertExists((string) $integration?->avatar_path);

    Http::assertSent(function ($request): bool {
        return str_starts_with($request->url(), 'https://graph.instagram.com/access_token')
            && $request['grant_type'] === 'ig_exchange_token'
            && $request['client_secret'] === 'test-app-secret'
            && $request['access_token'] === 'short_token_123';
    });

    Http::assertSent(function ($request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'POST'
            && str_starts_with($request->url(), 'https://graph.instagram.com/v23.0/178900000000/subscribed_apps')
            && (string) ($query['subscribed_fields'] ?? '') === 'messages'
            && (string) ($query['access_token'] ?? '') === 'long_token_123';
    });
});

test('instagram oauth callback stores different receiver id when webhook subscription resolves business recipient id', function () {
    [$user, , $assistant, $token] = instagramOauthTestContext();

    config()->set('meta.instagram.app_id', 'test-app-id');
    config()->set('meta.instagram.app_secret', 'test-app-secret');
    config()->set('meta.instagram.redirect_uri', 'http://localhost:8000/api/integrations/instagram/callback');
    config()->set('meta.instagram.frontend_redirect_url', 'http://localhost:5173/integrations');
    config()->set('meta.instagram.graph_base', 'https://graph.instagram.com');
    config()->set('meta.instagram.api_version', 'v23.0');
    config()->set('meta.instagram.avatar_disk', 'public');
    config()->set('meta.instagram.avatar_dir', 'instagram/avatars');
    config()->set('meta.instagram.subscribe_after_connect', true);
    config()->set('meta.instagram.subscribed_fields', 'messages');

    Storage::fake('public');

    Http::fake(function ($request) {
        $url = $request->url();

        if ($url === 'https://api.instagram.com/oauth/access_token') {
            return Http::response([
                'access_token' => 'short_token_456',
                'user_id' => '24498714506445566',
                'expires_in' => 3600,
            ], 200);
        }

        if (str_starts_with($url, 'https://graph.instagram.com/access_token')) {
            return Http::response([
                'access_token' => 'long_token_456',
                'expires_in' => 5184000,
            ], 200);
        }

        if (str_starts_with($url, 'https://graph.instagram.com/me')) {
            return Http::response([
                'id' => '17841444241066914',
                'user_id' => '24498714506445566',
                'username' => 'graphic.jungle',
                'profile_picture_url' => 'https://cdn.example.com/avatar-2.jpg',
            ], 200);
        }

        if (str_starts_with($url, 'https://graph.instagram.com/v23.0/17841444241066914/subscribed_apps')) {
            return Http::response([
                'success' => true,
            ], 200);
        }

        if (str_starts_with($url, 'https://graph.instagram.com/v23.0/24498714506445566/subscribed_apps')) {
            return Http::response([
                'error' => [
                    'message' => 'Unsupported get request',
                    'type' => 'OAuthException',
                    'code' => 100,
                ],
            ], 400);
        }

        if ($url === 'https://cdn.example.com/avatar-2.jpg') {
            return Http::response('fake-image-2', 200, ['Content-Type' => 'image/jpeg']);
        }

        return Http::response([], 404);
    });

    $startPayload = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('User-Agent', 'Pest Browser Agent')
        ->postJson('/api/assistant-channels/'.$assistant->id.'/instagram/connect')
        ->assertOk()
        ->json();

    $authorizationUrl = (string) ($startPayload['authorization_url'] ?? '');
    parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);
    $state = (string) ($query['state'] ?? '');

    $response = $this
        ->withHeader('User-Agent', 'Pest Browser Agent')
        ->get('/api/integrations/instagram/callback?code=test_code_2&state='.urlencode($state));

    $response->assertRedirect();

    $location = (string) $response->headers->get('Location');
    expect($location)->toContain('instagram_status=connected');
    expect($location)->toContain('assistant_id='.$assistant->id);

    $assistantChannel = AssistantChannel::query()
        ->where('assistant_id', $assistant->id)
        ->where('channel', 'instagram')
        ->first();

    expect($assistantChannel)->not->toBeNull();
    expect($assistantChannel?->external_account_id)->toBe('17841444241066914');

    $integration = InstagramIntegration::query()
        ->where('user_id', $user->id)
        ->where('instagram_user_id', '24498714506445566')
        ->first();

    expect($integration)->not->toBeNull();
    expect((string) $integration?->receiver_id)->toBe('17841444241066914');

    Http::assertSent(function ($request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'POST'
            && str_starts_with($request->url(), 'https://graph.instagram.com/v23.0/17841444241066914/subscribed_apps')
            && (string) ($query['subscribed_fields'] ?? '') === 'messages'
            && (string) ($query['access_token'] ?? '') === 'long_token_456';
    });
});

test('instagram oauth callback prefers profile id over oauth user id when both candidates can subscribe', function () {
    [$user, , $assistant, $token] = instagramOauthTestContext();

    config()->set('meta.instagram.app_id', 'test-app-id');
    config()->set('meta.instagram.app_secret', 'test-app-secret');
    config()->set('meta.instagram.redirect_uri', 'http://localhost:8000/api/integrations/instagram/callback');
    config()->set('meta.instagram.frontend_redirect_url', 'http://localhost:5173/integrations');
    config()->set('meta.instagram.graph_base', 'https://graph.instagram.com');
    config()->set('meta.instagram.api_version', 'v23.0');
    config()->set('meta.instagram.subscribe_after_connect', true);
    config()->set('meta.instagram.subscribed_fields', 'messages');

    Http::fake(function ($request) {
        $url = $request->url();

        if ($url === 'https://api.instagram.com/oauth/access_token') {
            return Http::response([
                'access_token' => 'short_token_pref',
                'user_id' => '24498714506445566',
                'expires_in' => 3600,
            ], 200);
        }

        if (str_starts_with($url, 'https://graph.instagram.com/access_token')) {
            return Http::response([
                'access_token' => 'long_token_pref',
                'expires_in' => 5184000,
            ], 200);
        }

        if (
            str_starts_with($url, 'https://graph.instagram.com/me')
            || str_starts_with($url, 'https://graph.instagram.com/v23.0/me')
        ) {
            return Http::response([
                'id' => '34773679555564224',
                'user_id' => '24498714506445566',
                'username' => 'graphic.jungle',
            ], 200);
        }

        if (str_starts_with($url, 'https://graph.instagram.com/v23.0/34773679555564224/subscribed_apps')) {
            return Http::response([
                'success' => true,
            ], 200);
        }

        if (str_starts_with($url, 'https://graph.instagram.com/v23.0/24498714506445566/subscribed_apps')) {
            return Http::response([
                'success' => true,
            ], 200);
        }

        return Http::response([], 404);
    });

    $startPayload = $this
        ->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('User-Agent', 'Pest Browser Agent')
        ->postJson('/api/assistant-channels/'.$assistant->id.'/instagram/connect')
        ->assertOk()
        ->json();

    $authorizationUrl = (string) ($startPayload['authorization_url'] ?? '');
    parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);
    $state = (string) ($query['state'] ?? '');

    $response = $this
        ->withHeader('User-Agent', 'Pest Browser Agent')
        ->get('/api/integrations/instagram/callback?code=test_code_pref&state='.urlencode($state));

    $response->assertRedirect();

    $assistantChannel = AssistantChannel::query()
        ->where('assistant_id', $assistant->id)
        ->where('channel', 'instagram')
        ->first();

    expect($assistantChannel)->not->toBeNull();
    expect((string) $assistantChannel?->external_account_id)->toBe('34773679555564224');

    $integration = InstagramIntegration::query()
        ->where('user_id', $user->id)
        ->where('instagram_user_id', '24498714506445566')
        ->first();

    expect($integration)->not->toBeNull();
    expect((string) $integration?->receiver_id)->toBe('34773679555564224');

    Http::assertSent(function ($request): bool {
        return $request->method() === 'POST'
            && str_starts_with($request->url(), 'https://graph.instagram.com/v23.0/34773679555564224/subscribed_apps');
    });

    Http::assertNotSent(function ($request): bool {
        return $request->method() === 'POST'
            && str_starts_with($request->url(), 'https://graph.instagram.com/v23.0/24498714506445566/subscribed_apps');
    });
});

test('instagram oauth callback rejects invalid state and redirects with error', function () {
    config()->set('meta.instagram.frontend_redirect_url', 'http://localhost:5173/integrations');

    $response = $this->get('/api/integrations/instagram/callback?code=any&state=invalid-state');

    $response->assertRedirect();

    $location = (string) $response->headers->get('Location');
    expect($location)->toContain('instagram_status=error');
    expect($location)->toContain('instagram_error=');
});

test('instagram oauth callback also works through /callback route', function () {
    config()->set('meta.instagram.frontend_redirect_url', 'http://localhost:5173/integrations');

    $response = $this->get('/callback?code=any&state=invalid-state');

    $response->assertRedirect();

    $location = (string) $response->headers->get('Location');
    expect($location)->toContain('instagram_status=error');
    expect($location)->toContain('instagram_error=');
});
