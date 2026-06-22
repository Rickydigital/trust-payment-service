<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateViaMainPlatform
{
    /**
     * Delegated authentication: this service has no users table.
     * It forwards the incoming Bearer token to the main platform's
     * GET /api/auth/me and trusts whatever comes back.
     *
     * IMPORTANT: /api/auth/me returns HTTP 200 even when unauthenticated,
     * with "user": null in the body. A non-2xx status alone is NOT a
     * reliable signal — we must also check that "user" is present.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $user = $this->resolveUser($token);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Attach the resolved user payload (a plain array/object from the
        // main platform, NOT an Eloquent model — this service has no
        // users table) so controllers can read it via $request->attributes.
        $request->attributes->set('auth_user', $user);

        return $next($request);
    }

    /**
     * Resolve the user for a given bearer token by calling the main
     * platform. Cached briefly per-token so a buyer polling
     * GET /status/{ref} every 5 seconds (per spec) doesn't hammer
     * the main platform with a fresh introspection call every time.
     */
    private function resolveUser(string $token): ?array
    {
        $cacheKey = 'auth_introspect_' . md5($token);

        return Cache::remember($cacheKey, now()->addSeconds(30), function () use ($token) {
            $baseUrl = rtrim((string) config('services.main_platform.url'), '/');

            try {
                $res = Http::withToken($token)
                    ->acceptJson()
                    ->timeout(5)
                    ->get($baseUrl . '/api/auth/me');
            } catch (\Throwable $e) {
                return null;
            }

            if (! $res->successful()) {
                return null;
            }

            $user = $res->json('user');

            if (! is_array($user) || empty($user['id'])) {
                return null;
            }

            return $user;
        }) ?: null;
    }
}