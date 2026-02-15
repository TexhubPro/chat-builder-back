<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasPageAccess
{
    public function handle(Request $request, Closure $next, string $pageKeys): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return new JsonResponse([
                'message' => 'Unauthorized.',
            ], 401);
        }

        if ($user->role !== User::ROLE_EMPLOYEE) {
            return $next($request);
        }

        $allowedPages = $this->normalizedPages($user->page_access);
        $requiredPages = $this->normalizedPages(explode(',', $pageKeys));

        if ($requiredPages === []) {
            return $next($request);
        }

        foreach ($requiredPages as $requiredPage) {
            if (in_array($requiredPage, $allowedPages, true)) {
                return $next($request);
            }
        }

        return new JsonResponse([
            'message' => 'You do not have access to this page.',
        ], 403);
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function normalizedPages(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $pages = [];

        foreach ($value as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $normalized = trim($entry);
            if ($normalized === '') {
                continue;
            }

            $pages[] = $normalized;
        }

        return array_values(array_unique($pages));
    }
}
