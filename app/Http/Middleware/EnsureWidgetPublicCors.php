<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWidgetPublicCors
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/widget/*')) {
            return $next($request);
        }

        if ($request->isMethod('OPTIONS')) {
            return $this->applyHeaders(response()->noContent());
        }

        return $this->applyHeaders($next($request));
    }

    private function applyHeaders(Response $response): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET,POST,OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type,Accept,Origin');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }
}
