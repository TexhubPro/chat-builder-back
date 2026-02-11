<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\EmailVerificationCodeMail;
use App\Mail\TemporaryPasswordMail;
use App\Models\EmailVerificationCode;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\CompanySubscriptionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class AuthController extends Controller
{
    private const REGISTER_MAX_ATTEMPTS = 4;
    private const REGISTER_DECAY_SECONDS = 3600;
    private const LOGIN_MAX_ATTEMPTS = 4;
    private const LOGIN_DECAY_SECONDS = 3600;
    private const VERIFY_CODE_MAX_ATTEMPTS = 4;
    private const VERIFY_CODE_DECAY_SECONDS = 3600;
    private const RESEND_MAX_ATTEMPTS = 4;
    private const RESEND_DECAY_SECONDS = 3600;
    private const FORGOT_PASSWORD_MAX_ATTEMPTS = 4;
    private const FORGOT_PASSWORD_DECAY_SECONDS = 3600;
    private const OAUTH_STATE_TTL_MINUTES = 10;
    private const EMAIL_VERIFICATION_CODE_LENGTH = 6;
    private const SUPPORTED_SOCIAL_PROVIDERS = ['github', 'google'];

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'password' => [
                'required',
                'string',
                'max:255',
                Password::min(10)->letters()->mixedCase()->numbers()->symbols(),
            ],
            'avatar' => ['nullable', 'url', 'max:2048'],
        ]);

        $email = Str::lower(trim($validated['email']));
        $phone = $this->normalizePhone($validated['phone']);
        $registerThrottleKey = $this->registerThrottleKey((string) $request->ip());

        if (RateLimiter::tooManyAttempts($registerThrottleKey, self::REGISTER_MAX_ATTEMPTS)) {
            return response()->json([
                'message' => 'Too many registration attempts. Please try again later.',
                'retry_after' => RateLimiter::availableIn($registerThrottleKey),
            ], 429);
        }

        RateLimiter::hit($registerThrottleKey, self::REGISTER_DECAY_SECONDS);

        if (!$phone) {
            throw ValidationException::withMessages([
                'phone' => 'Phone number format is invalid. Use international format like +12345678900.',
            ]);
        }

        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages([
                'email' => 'The email has already been taken.',
            ]);
        }

        if (User::query()->where('phone', $phone)->exists()) {
            throw ValidationException::withMessages([
                'phone' => 'The phone has already been taken.',
            ]);
        }

        $user = DB::transaction(function () use ($validated, $email, $phone): User {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $email,
                'phone' => $phone,
                'password' => $validated['password'],
                'avatar' => $validated['avatar'] ?? null,
                'role' => User::ROLE_CUSTOMER,
                'status' => !$this->moderationEnabled(),
            ]);

            $this->subscriptionService()->provisionDefaultWorkspaceForUser($user->id, $user->name);
            $this->issueAndSendVerificationCode($user, false);

            return $user;
        });

        return response()->json([
            'message' => 'Registration created. Verification code sent to your email.',
            'requires_email_verification' => true,
            'email' => $user->email,
            'expires_in_seconds' => $this->verificationExpiresMinutes() * 60,
            'resend_available_in' => $this->verificationResendCooldownSeconds(),
        ], 201);
    }

    public function verifyEmailCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'digits:6'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $email = Str::lower(trim($validated['email']));
        $verifyThrottleKey = $this->verifyCodeThrottleKey($email, (string) $request->ip());

        if (RateLimiter::tooManyAttempts($verifyThrottleKey, self::VERIFY_CODE_MAX_ATTEMPTS)) {
            return response()->json([
                'message' => 'Too many verification attempts. Please try again later.',
                'retry_after' => RateLimiter::availableIn($verifyThrottleKey),
            ], 429);
        }

        RateLimiter::hit($verifyThrottleKey, self::VERIFY_CODE_DECAY_SECONDS);

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (!$user) {
            return response()->json([
                'message' => 'Invalid verification code.',
            ], 422);
        }

        if (!$user->status && !$this->isModerationManagedUser($user)) {
            $user->emailVerificationCode()?->delete();
            $user->tokens()->delete();

            return response()->json([
                'message' => 'User is inactive.',
            ], 403);
        }

        if ($user->email_verified_at) {
            return response()->json([
                'message' => 'Email is already verified. Please login.',
            ], 409);
        }

        $verification = $user->emailVerificationCode;

        if (!$verification) {
            return response()->json([
                'message' => 'Verification code is invalid or expired.',
            ], 422);
        }

        if ($verification->expires_at->isPast()) {
            $verification->delete();

            return response()->json([
                'message' => 'Verification code has expired. Request a new code.',
            ], 422);
        }

        if ($verification->attempts >= $verification->max_attempts) {
            $verification->delete();

            return response()->json([
                'message' => 'Verification attempts exceeded. Request a new code.',
            ], 422);
        }

        if (!Hash::check($validated['code'], $verification->code_hash)) {
            $verification->increment('attempts');
            $verification->refresh();

            $attemptsRemaining = max($verification->max_attempts - $verification->attempts, 0);

            if ($attemptsRemaining <= 0) {
                $verification->delete();

                return response()->json([
                    'message' => 'Verification attempts exceeded. Request a new code.',
                ], 422);
            }

            return response()->json([
                'message' => 'Invalid verification code.',
                'attempts_remaining' => $attemptsRemaining,
            ], 422);
        }

        $user->forceFill([
            'email_verified_at' => now(),
        ])->save();

        $verification->delete();
        $user->tokens()->delete();

        $token = $this->issueFrontendToken($user, $validated['device_name'] ?? 'frontend');
        RateLimiter::clear($verifyThrottleKey);
        $this->syncCompanyAssistantAccess($user);

        if ($this->isModerationManagedUser($user)) {
            return response()->json([
                'message' => 'Account is under moderation.',
                'requires_moderation' => true,
                'token_type' => 'Bearer',
                'token' => $token->plainTextToken,
                'token_expires_at' => $token->accessToken->expires_at,
                'user' => $user,
            ]);
        }

        return response()->json([
            'message' => 'Email verified successfully.',
            'requires_moderation' => false,
            'token_type' => 'Bearer',
            'token' => $token->plainTextToken,
            'token_expires_at' => $token->accessToken->expires_at,
            'user' => $user,
        ]);
    }

    public function resendVerificationCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = Str::lower(trim($validated['email']));
        $throttleKey = $this->resendThrottleKey($email, (string) $request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, self::RESEND_MAX_ATTEMPTS)) {
            return response()->json([
                'message' => 'Too many resend requests. Try again later.',
                'retry_after' => RateLimiter::availableIn($throttleKey),
            ], 429);
        }

        RateLimiter::hit($throttleKey, self::RESEND_DECAY_SECONDS);

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (!$user) {
            return response()->json([
                'message' => 'If the account exists, a verification code has been sent.',
            ]);
        }

        if (!$user->status && !$this->isModerationManagedUser($user)) {
            $user->tokens()->delete();

            return response()->json([
                'message' => 'User is inactive.',
            ], 403);
        }

        if ($user->email_verified_at) {
            return response()->json([
                'message' => 'Email is already verified.',
            ], 422);
        }

        $retryAfter = $this->verificationResendRetryAfter($user->emailVerificationCode);

        if ($retryAfter > 0) {
            return response()->json([
                'message' => 'Please wait before requesting a new code.',
                'retry_after' => $retryAfter,
            ], 429);
        }

        $this->issueAndSendVerificationCode($user, false);

        return response()->json([
            'message' => 'Verification code sent.',
            'expires_in_seconds' => $this->verificationExpiresMinutes() * 60,
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'login' => ['nullable', 'string', 'max:255', 'required_without_all:email,phone'],
            'email' => ['nullable', 'email', 'max:255', 'required_without_all:login,phone'],
            'phone' => ['nullable', 'string', 'max:32', 'required_without_all:login,email'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $loginIdentifier = trim((string) ($validated['login'] ?? $validated['email'] ?? $validated['phone'] ?? ''));
        $throttleKey = $this->loginThrottleKey($loginIdentifier, (string) $request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, self::LOGIN_MAX_ATTEMPTS)) {
            return response()->json([
                'message' => 'Too many login attempts. Please try again later.',
                'retry_after' => RateLimiter::availableIn($throttleKey),
            ], 429);
        }

        $user = $this->resolveUserForLogin($loginIdentifier);

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($throttleKey, self::LOGIN_DECAY_SECONDS);

            return response()->json([
                'message' => 'Invalid credentials.',
            ], 422);
        }

        if (!$user->email_verified_at) {
            RateLimiter::hit($throttleKey, self::LOGIN_DECAY_SECONDS);

            return response()->json([
                'message' => 'Email is not verified.',
                'requires_email_verification' => true,
                'resend_available_in' => $this->verificationResendRetryAfter($user->emailVerificationCode),
            ], 403);
        }

        if (!$user->status) {
            if ($this->isModerationManagedUser($user)) {
                RateLimiter::clear($throttleKey);
                $user->tokens()->delete();
                $token = $this->issueFrontendToken($user, $validated['device_name'] ?? 'frontend');
                $this->syncCompanyAssistantAccess($user);

                return response()->json([
                    'message' => 'Account is under moderation.',
                    'requires_moderation' => true,
                    'token_type' => 'Bearer',
                    'token' => $token->plainTextToken,
                    'token_expires_at' => $token->accessToken->expires_at,
                    'user' => $user,
                ], 403);
            }

            RateLimiter::hit($throttleKey, self::LOGIN_DECAY_SECONDS);
            $user->tokens()->delete();

            return response()->json([
                'message' => 'User is inactive.',
            ], 403);
        }

        RateLimiter::clear($throttleKey);

        $user->tokens()->delete();
        $token = $this->issueFrontendToken($user, $validated['device_name'] ?? 'frontend');
        $this->syncCompanyAssistantAccess($user);

        return response()->json([
            'message' => 'Login successful.',
            'token_type' => 'Bearer',
            'token' => $token->plainTextToken,
            'token_expires_at' => $token->accessToken->expires_at,
            'user' => $user,
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = Str::lower(trim($validated['email']));
        $throttleKey = $this->forgotPasswordThrottleKey($email, (string) $request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, self::FORGOT_PASSWORD_MAX_ATTEMPTS)) {
            return response()->json([
                'message' => 'Too many password reset requests. Please try again later.',
                'retry_after' => RateLimiter::availableIn($throttleKey),
            ], 429);
        }

        RateLimiter::hit($throttleKey, self::FORGOT_PASSWORD_DECAY_SECONDS);

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (!$user) {
            return response()->json([
                'message' => 'If the account exists, a temporary password has been sent.',
            ]);
        }

        $temporaryPassword = Str::password(14);

        DB::transaction(function () use ($user, $temporaryPassword): void {
            $user->forceFill([
                'password' => $temporaryPassword,
                'remember_token' => Str::random(60),
            ])->save();

            $user->tokens()->delete();

            Mail::to($user->email)->send(new TemporaryPasswordMail(
                name: $user->name,
                temporaryPassword: $temporaryPassword,
            ));
        });

        return response()->json([
            'message' => 'If the account exists, a temporary password has been sent.',
        ]);
    }

    public function socialRedirect(Request $request, string $provider): JsonResponse
    {
        $provider = $this->assertSupportedProvider($provider);
        $this->assertProviderIsConfigured($provider);

        $state = Str::random(64);

        Cache::put($this->oauthStateCacheKey($provider, $state), [
            'ip' => $request->ip(),
            'ua_hash' => hash('sha256', (string) $request->userAgent()),
        ], now()->addMinutes(self::OAUTH_STATE_TTL_MINUTES));

        $driver = Socialite::driver($provider)
            ->stateless()
            ->with(['state' => $state]);

        if ($provider === 'google') {
            $driver->scopes(['openid', 'profile', 'email'])->with(['prompt' => 'select_account']);
        } else {
            $driver->scopes(['read:user', 'user:email']);
        }

        return response()->json([
            'provider' => $provider,
            'authorization_url' => $driver->redirect()->getTargetUrl(),
            'state_ttl_seconds' => self::OAUTH_STATE_TTL_MINUTES * 60,
        ]);
    }

    public function socialCallback(Request $request, string $provider): JsonResponse
    {
        $provider = $this->assertSupportedProvider($provider);
        $this->assertProviderIsConfigured($provider);

        if ($request->filled('error')) {
            return response()->json([
                'message' => 'OAuth authorization was denied.',
            ], 422);
        }

        $this->validateOauthState($provider, (string) $request->query('state'), $request);

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();
        } catch (Throwable) {
            return response()->json([
                'message' => 'Failed to authenticate with provider.',
            ], 422);
        }

        $user = $this->resolveOrCreateSocialUser($provider, $socialUser);

        if (!$user->status) {
            $user->tokens()->delete();

            return response()->json([
                'message' => 'User is inactive.',
            ], 403);
        }

        $user->tokens()->delete();
        $token = $this->issueFrontendToken($user, "{$provider}-oauth");
        $this->syncCompanyAssistantAccess($user);

        return response()->json([
            'message' => 'Social login successful.',
            'token_type' => 'Bearer',
            'token' => $token->plainTextToken,
            'token_expires_at' => $token->accessToken->expires_at,
            'requires_phone' => $user->phone === null,
            'user' => $user,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user(),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user instanceof User) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 401);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,avif', 'max:4096'],
            'remove_avatar' => ['nullable', 'boolean'],
            'email' => ['prohibited'],
            'phone' => ['prohibited'],
            'current_password' => ['nullable', 'string', 'max:255', 'required_with:new_password'],
            'new_password' => [
                'nullable',
                'string',
                'max:255',
                'required_with:current_password',
                'confirmed',
                Password::min(10)->letters()->mixedCase()->numbers()->symbols(),
            ],
        ]);

        $normalizedName = trim((string) $validated['name']);
        $removeAvatar = (bool) ($validated['remove_avatar'] ?? false);
        $avatarFile = $request->file('avatar');

        $updates = [];

        if ($normalizedName !== $user->name) {
            $updates['name'] = $normalizedName;
        }

        if ($avatarFile instanceof UploadedFile) {
            $updates['avatar'] = $this->storeUserAvatar($avatarFile);
            $this->deleteUserAvatarFile($user->avatar);
        } elseif ($removeAvatar && $user->avatar !== null) {
            $this->deleteUserAvatarFile($user->avatar);
            $updates['avatar'] = null;
        }

        $newPassword = $validated['new_password'] ?? null;

        if (is_string($newPassword) && $newPassword !== '') {
            $currentPassword = (string) ($validated['current_password'] ?? '');

            if (!Hash::check($currentPassword, $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => 'Current password is incorrect.',
                ]);
            }

            if (Hash::check($newPassword, $user->password)) {
                throw ValidationException::withMessages([
                    'new_password' => 'The new password must be different from the current password.',
                ]);
            }

            $updates['password'] = $newPassword;
        }

        if ($updates !== []) {
            $user->forceFill($updates)->save();
        }

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => $user->fresh(),
        ]);
    }

    public function moderationStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user instanceof User) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 401);
        }

        if (!$user->email_verified_at) {
            $user->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'Email is not verified.',
            ], 403);
        }

        if (!$user->status && !$this->isModerationManagedUser($user)) {
            $user->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'User is inactive.',
            ], 403);
        }

        if ($this->isModerationManagedUser($user)) {
            return response()->json([
                'message' => 'Account is under moderation.',
                'requires_moderation' => true,
                'user' => $user,
            ]);
        }

        return response()->json([
            'message' => 'Account is active.',
            'requires_moderation' => false,
            'user' => $user,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    private function issueAndSendVerificationCode(User $user, bool $enforceCooldown = true): EmailVerificationCode
    {
        $existingCode = $user->emailVerificationCode;

        if ($enforceCooldown) {
            $retryAfter = $this->verificationResendRetryAfter($existingCode);

            if ($retryAfter > 0) {
                throw ValidationException::withMessages([
                    'email' => "Please wait {$retryAfter} seconds before requesting a new code.",
                ]);
            }
        }

        $code = $this->generateVerificationCode();
        $expiresMinutes = $this->verificationExpiresMinutes();

        $verificationCode = EmailVerificationCode::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes($expiresMinutes),
                'sent_at' => now(),
                'attempts' => 0,
                'max_attempts' => $this->verificationMaxAttempts(),
            ]
        );

        Mail::to($user->email)->send(new EmailVerificationCodeMail(
            name: $user->name,
            code: $code,
            expiresInMinutes: $expiresMinutes,
        ));

        return $verificationCode;
    }

    private function generateVerificationCode(): string
    {
        $maxNumber = (10 ** self::EMAIL_VERIFICATION_CODE_LENGTH) - 1;

        return str_pad(
            (string) random_int(0, $maxNumber),
            self::EMAIL_VERIFICATION_CODE_LENGTH,
            '0',
            STR_PAD_LEFT
        );
    }

    private function verificationExpiresMinutes(): int
    {
        return max((int) config('verification.email_code.expires_in_minutes', 10), 1);
    }

    private function verificationMaxAttempts(): int
    {
        return max((int) config('verification.email_code.max_attempts', 4), 1);
    }

    private function verificationResendCooldownSeconds(): int
    {
        return max((int) config('verification.email_code.resend_cooldown_seconds', 60), 0);
    }

    private function verificationResendRetryAfter(?EmailVerificationCode $verification): int
    {
        if (!$verification || !$verification->sent_at) {
            return 0;
        }

        $cooldownSeconds = $this->verificationResendCooldownSeconds();

        if ($cooldownSeconds <= 0) {
            return 0;
        }

        $availableAt = $verification->sent_at->copy()->addSeconds($cooldownSeconds);

        return max(now()->diffInSeconds($availableAt, false), 0);
    }

    private function moderationEnabled(): bool
    {
        return (bool) config('moderation.enabled', false);
    }

    private function isModerationManagedUser(User $user): bool
    {
        return $this->moderationEnabled() && !$user->status;
    }

    private function issueFrontendToken(User $user, string $deviceName = 'frontend'): NewAccessToken
    {
        $tokenName = Str::limit(trim($deviceName), 64, '');

        if ($tokenName === '') {
            $tokenName = 'frontend';
        }

        return $user->createToken($tokenName, ['*'], $this->resolveTokenExpiration());
    }

    private function resolveTokenExpiration(): ?Carbon
    {
        $expirationMinutes = (int) config('sanctum.expiration', 0);

        if ($expirationMinutes <= 0) {
            return null;
        }

        return now()->addMinutes($expirationMinutes);
    }

    private function resolveUserForLogin(string $loginIdentifier): ?User
    {
        if ($loginIdentifier === '') {
            return null;
        }

        if (filter_var($loginIdentifier, FILTER_VALIDATE_EMAIL)) {
            return User::query()
                ->whereRaw('LOWER(email) = ?', [Str::lower($loginIdentifier)])
                ->first();
        }

        $normalizedPhone = $this->normalizePhone($loginIdentifier);

        if (!$normalizedPhone) {
            return null;
        }

        return User::query()->where('phone', $normalizedPhone)->first();
    }

    private function loginThrottleKey(string $loginIdentifier, string $ipAddress): string
    {
        return 'auth:login:' . hash('sha256', Str::lower($loginIdentifier) . '|' . $ipAddress);
    }

    private function resendThrottleKey(string $email, string $ipAddress): string
    {
        return 'auth:verify-resend:' . hash('sha256', Str::lower($email) . '|' . $ipAddress);
    }

    private function registerThrottleKey(string $ipAddress): string
    {
        return 'auth:register:' . hash('sha256', $ipAddress);
    }

    private function verifyCodeThrottleKey(string $email, string $ipAddress): string
    {
        return 'auth:verify-code:' . hash('sha256', Str::lower($email) . '|' . $ipAddress);
    }

    private function forgotPasswordThrottleKey(string $email, string $ipAddress): string
    {
        return 'auth:forgot-password:' . hash('sha256', Str::lower($email) . '|' . $ipAddress);
    }

    private function normalizePhone(string $phone): ?string
    {
        $normalized = preg_replace('/[^\d+]/', '', trim($phone));

        if (!is_string($normalized) || $normalized === '') {
            return null;
        }

        if (Str::startsWith($normalized, '00')) {
            $normalized = '+' . substr($normalized, 2);
        }

        if (!Str::startsWith($normalized, '+')) {
            $normalized = '+' . $normalized;
        }

        if (!preg_match('/^\+[1-9]\d{7,14}$/', $normalized)) {
            return null;
        }

        return $normalized;
    }

    private function storeUserAvatar(UploadedFile $avatar): string
    {
        $path = $avatar->store('avatars', 'public');

        return URL::to(Storage::disk('public')->url($path));
    }

    private function deleteUserAvatarFile(?string $avatarUrl): void
    {
        if (!is_string($avatarUrl) || trim($avatarUrl) === '') {
            return;
        }

        $path = parse_url($avatarUrl, PHP_URL_PATH);

        if (!is_string($path) || !Str::startsWith($path, '/storage/')) {
            return;
        }

        $relativePath = trim(Str::after($path, '/storage/'), '/');

        if ($relativePath === '') {
            return;
        }

        Storage::disk('public')->delete($relativePath);
    }

    private function assertSupportedProvider(string $provider): string
    {
        $provider = Str::lower(trim($provider));

        if (!in_array($provider, self::SUPPORTED_SOCIAL_PROVIDERS, true)) {
            throw ValidationException::withMessages([
                'provider' => 'Unsupported social provider.',
            ]);
        }

        return $provider;
    }

    private function oauthStateCacheKey(string $provider, string $state): string
    {
        return 'oauth:state:' . $provider . ':' . hash('sha256', $state);
    }

    private function assertProviderIsConfigured(string $provider): void
    {
        $providerConfig = (array) config("services.{$provider}", []);

        if (
            empty($providerConfig['client_id'])
            || empty($providerConfig['client_secret'])
            || empty($providerConfig['redirect'])
        ) {
            throw ValidationException::withMessages([
                'provider' => ucfirst($provider) . ' OAuth is not configured on the server.',
            ]);
        }
    }

    private function validateOauthState(string $provider, string $state, Request $request): void
    {
        if ($state === '') {
            throw ValidationException::withMessages([
                'state' => 'OAuth state is missing.',
            ]);
        }

        $statePayload = Cache::pull($this->oauthStateCacheKey($provider, $state));

        if (!is_array($statePayload)) {
            throw ValidationException::withMessages([
                'state' => 'OAuth state is invalid or expired.',
            ]);
        }

        $ipMatches = ($statePayload['ip'] ?? null) === $request->ip();
        $uaMatches = ($statePayload['ua_hash'] ?? null) === hash('sha256', (string) $request->userAgent());

        if (!$ipMatches || !$uaMatches) {
            throw ValidationException::withMessages([
                'state' => 'OAuth state validation failed.',
            ]);
        }
    }

    private function resolveOrCreateSocialUser(string $provider, SocialiteUserContract $socialUser): User
    {
        $providerId = trim((string) $socialUser->getId());
        $providerEmail = Str::lower(trim((string) $socialUser->getEmail()));

        if ($providerId === '') {
            throw ValidationException::withMessages([
                'provider' => 'Provider user id is missing.',
            ]);
        }

        if ($providerEmail === '') {
            throw ValidationException::withMessages([
                'email' => 'Your social account must provide an email.',
            ]);
        }

        $providerAvatar = $socialUser->getAvatar();
        $providerName = trim((string) ($socialUser->getName() ?: $socialUser->getNickname() ?: 'User'));

        return DB::transaction(function () use ($provider, $providerId, $providerEmail, $providerAvatar, $providerName): User {
            $socialAccount = SocialAccount::query()
                ->where('provider', $provider)
                ->where('provider_id', $providerId)
                ->first();

            if ($socialAccount) {
                $socialAccount->forceFill([
                    'provider_email' => $providerEmail,
                    'provider_avatar' => $providerAvatar,
                ])->save();

                $user = $socialAccount->user;

                $updates = [];
                if ($providerAvatar && !$user->avatar) {
                    $updates['avatar'] = $providerAvatar;
                }
                if (!$user->email_verified_at) {
                    $updates['email_verified_at'] = now();
                }

                if ($updates !== []) {
                    $user->forceFill($updates)->save();
                }

                $this->subscriptionService()->provisionDefaultWorkspaceForUser($user->id, $user->name);

                return $user;
            }

            $user = User::query()->whereRaw('LOWER(email) = ?', [$providerEmail])->first();

            if (!$user) {
                $user = User::create([
                    'name' => $providerName === '' ? 'User' : $providerName,
                    'email' => $providerEmail,
                    'phone' => null,
                    'avatar' => $providerAvatar,
                    'role' => User::ROLE_CUSTOMER,
                    'status' => true,
                    'password' => Str::password(40),
                ]);

                $user->forceFill([
                    'email_verified_at' => now(),
                ])->save();
            } else {
                $updates = [];
                if ($providerAvatar && !$user->avatar) {
                    $updates['avatar'] = $providerAvatar;
                }
                if (!$user->email_verified_at) {
                    $updates['email_verified_at'] = now();
                }

                if ($updates !== []) {
                    $user->forceFill($updates)->save();
                }
            }

            $this->subscriptionService()->provisionDefaultWorkspaceForUser($user->id, $user->name);

            SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_id' => $providerId,
                'provider_email' => $providerEmail,
                'provider_avatar' => $providerAvatar,
            ]);

            return $user;
        });
    }

    private function syncCompanyAssistantAccess(User $user): void
    {
        $company = $user->company;

        if (!$company) {
            return;
        }

        $this->subscriptionService()->syncAssistantAccess($company);
    }

    private function subscriptionService(): CompanySubscriptionService
    {
        return app(CompanySubscriptionService::class);
    }
}
