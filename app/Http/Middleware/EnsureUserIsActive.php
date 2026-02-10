<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextToken = $request->bearerToken();

        if (! $plainTextToken) {
            return new JsonResponse([
                'message' => 'Token is required.',
            ], 401);
        }

        $accessToken = PersonalAccessToken::findToken($plainTextToken);

        if (! $accessToken) {
            return new JsonResponse([
                'message' => 'Invalid token.',
            ], 401);
        }

        $user = $accessToken->tokenable;

        if (! $user instanceof User) {
            $accessToken->delete();

            return new JsonResponse([
                'message' => 'Invalid token owner.',
            ], 401);
        }

        if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
            $accessToken->delete();

            return new JsonResponse([
                'message' => 'Token has expired.',
            ], 401);
        }

        if (! $user->status) {
            if ($this->isModerationPending($user)) {
                return new JsonResponse([
                    'message' => 'Account is under moderation.',
                    'requires_moderation' => true,
                ], 403);
            }

            $accessToken->delete();

            return new JsonResponse([
                'message' => 'User is inactive.',
            ], 403);
        }

        if (! $user->email_verified_at) {
            $accessToken->delete();

            return new JsonResponse([
                'message' => 'Email is not verified.',
            ], 403);
        }

        $authenticatedUser = $user->withAccessToken($accessToken);
        Auth::setUser($authenticatedUser);
        $request->setUserResolver(static fn () => $authenticatedUser);

        $accessToken->forceFill(['last_used_at' => now()])->save();

        return $next($request);
    }

    private function moderationEnabled(): bool
    {
        return (bool) config('moderation.enabled', false);
    }

    private function isModerationPending(User $user): bool
    {
        return $this->moderationEnabled()
            && ! $user->status
            && $user->email_verified_at !== null;
    }
}
