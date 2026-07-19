<?php

namespace App\Services;

use App\Contracts\PaymentDriverInterface;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\Cache;

class PaymentRouter
{
    /**
     * Resolve the PaymentMethod row for a given provider_key.
     * Throws if not found or inactive — callers decide how to surface that
     * (404 for webhooks, 422 for initiate, etc).
     */
    public function method(string $providerKey): PaymentMethod
    {
        $method = Cache::remember(
            "payment_method_{$providerKey}",
            now()->addMinutes(10),
            fn () => PaymentMethod::query()
                ->with('provider')
                ->where('provider_key', $providerKey)
                ->first()
        );

        if (! $method) {
            throw new \RuntimeException("Unknown payment provider: {$providerKey}");
        }

        return $method;
    }

    /**
     * Resolve the driver instance for a given provider_key.
     * This is the only place a driver_class string gets turned into an
     * object — no switch/if-else on provider names anywhere else.
     */
    public function driver(string $providerKey): PaymentDriverInterface
    {
        $method = $this->method($providerKey);

        return $this->driverFor($method);
    }

    public function driverFor(PaymentMethod $method): PaymentDriverInterface
    {
        $class = $method->driver_class;

        if (! class_exists($class)) {
            throw new \RuntimeException("Driver class does not exist: {$class}");
        }

        $driver = app($class, ['config' => $method->config ?? []]);

        if (! $driver instanceof PaymentDriverInterface) {
            throw new \RuntimeException("Driver class does not implement PaymentDriverInterface: {$class}");
        }

        return $driver;
    }

    /**
     * Active, ordered list of methods for GET /methods.
     * Cached separately from the per-key lookup since this is the
     * Flutter-facing list endpoint, hit far more often.
     */
    public function activeMethods()
    {
        return Cache::remember(
            'payment_methods_active',
            now()->addMinutes(10),
            fn () => PaymentMethod::query()
                ->with('provider')
                ->where('is_active', true)
                ->where(fn ($query) => $query
                    ->whereNull('payment_provider_id')
                    ->orWhereHas('provider', fn ($providerQuery) => $providerQuery->where('is_active', true)))
                ->orderBy('sort_order')
                ->get()
        );
    }

    /**
     * Call after any payment_methods write (create/update/toggle) so
     * cached lookups don't serve stale data for up to 10 minutes.
     */
    public function forget(string $providerKey): void
    {
        Cache::forget("payment_method_{$providerKey}");
        Cache::forget('payment_methods_active');
    }
}
