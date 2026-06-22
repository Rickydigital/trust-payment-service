<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateInternalService
{
    /**
     * Server-to-server auth: the main platform sends a shared secret in
     * X-Internal-Key. Both services have the same value in their .env
     * under INTERNAL_SERVICE_KEY. No JWT, no user — this is platform-to-
     * platform trust, not buyer auth.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $provided = (string) $request->header('X-Internal-Key', '');
        $expected = (string) config('services.main_platform.internal_key');

        if (! $expected || ! $provided || ! hash_equals($expected, $provided)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        return $next($request);
    }
}