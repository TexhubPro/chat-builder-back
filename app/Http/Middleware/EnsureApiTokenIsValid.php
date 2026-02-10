<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiTokenIsValid
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

        $authenticatedUser = $user->withAccessToken($accessToken);
        Auth::setUser($authenticatedUser);
        $request->setUserResolver(static fn () => $authenticatedUser);

        $accessToken->forceFill(['last_used_at' => now()])->save();

        return $next($request);
    }
}

