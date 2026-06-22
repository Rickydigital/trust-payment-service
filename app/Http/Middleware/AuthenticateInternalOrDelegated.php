<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateInternalOrDelegated
{
    /**
     * /initiate is called by the main platform server-to-server
     * (X-Internal-Key) per the original spec. This middleware checks
     * that first, since it's the primary/expected caller. If no internal
     * key is present, it falls back to delegated JWT auth, in case a
     * client ever needs to call this directly in the future.
     *
     * Exactly one of $request->attributes 'auth_user' (delegated) or
     * 'is_internal_call' (internal key) will be set on success, so
     * downstream controllers can tell which path was used.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $internalKey = (string) $request->header('X-Internal-Key', '');
        $expectedInternalKey = (string) config('services.main_platform.internal_key');

        if ($internalKey && $expectedInternalKey && hash_equals($expectedInternalKey, $internalKey)) {
            $request->attributes->set('is_internal_call', true);
            return $next($request);
        }

        $token = $request->bearerToken();

        if ($token) {
            $user = $this->resolveUser($token);

            if ($user) {
                $request->attributes->set('auth_user', $user);
                return $next($request);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Unauthenticated.',
        ], 401);
    }

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