<?php

namespace App\Drivers;

use App\Contracts\PaymentDriverInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ClickPesaDriver implements PaymentDriverInterface
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    // ─────────────────────────────────────────────
    // PaymentDriverInterface
    // ─────────────────────────────────────────────

    public function initiate(array $payload): array
    {
        foreach (['amount', 'currency', 'orderReference', 'payerPhone'] as $key) {
            if (! array_key_exists($key, $payload)) {
                return [
                    'ok' => false,
                    'providerReference' => null,
                    'status' => 'failed',
                    'message' => "ClickPesa payload missing: {$key}",
                    'raw' => [],
                ];
            }
        }

        $body = [
            'amount' => (string) $payload['amount'],
            'currency' => (string) $payload['currency'],
            // ClickPesa requires orderReference to be alphanumeric only —
            // our internal reference format (PAY-YYYYMMDD-XXXXXX) contains
            // hyphens, which ClickPesa rejects. Strip them before sending,
            // same sanitization the old monolith's CheckoutController did.
            'orderReference' => preg_replace('/[^A-Za-z0-9]/', '', (string) $payload['orderReference']),
            'phoneNumber' => (string) $payload['payerPhone'],
        ];

        $checksum = $this->checksum($body);

        if ($checksum) {
            $body['checksum'] = $checksum;
        }

        try {
            $res = Http::withHeaders([
                'Authorization' => $this->token(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl() . '/payments/initiate-ussd-push-request', $body);

            $formatted = $this->formatResponse($res, 'Prompt sent successfully.', 'USSD push failed.');

            return [
                'ok' => $formatted['ok'],
                'providerReference' => $formatted['data']['orderReference'] ?? $payload['orderReference'],
                'status' => $formatted['ok'] ? 'pending' : 'failed',
                'message' => $formatted['message'],
                'raw' => $formatted['data'] ?? [],
            ];
        } catch (\Throwable $e) {
            return $this->exceptionResult($e);
        }
    }

    public function queryStatus(string $providerReference): array
    {
        try {
            $res = Http::withHeaders([
                'Authorization' => $this->token(),
                'Accept' => 'application/json',
            ])->get($this->baseUrl() . '/payments/' . urlencode($providerReference));

            $formatted = $this->formatResponse($res, 'Status fetched.', 'Status query failed.');

            return [
                'ok' => $formatted['ok'],
                'status' => $this->mapStatus($this->extractStatus($formatted['data'] ?? [])),
                'raw' => $formatted['data'] ?? [],
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'status' => 'pending',
                'raw' => ['error' => $e->getMessage()],
            ];
        }
    }

    public function refund(string $providerReference, float $amount): array
    {
        // ClickPesa has no documented refund endpoint at the time of writing.
        // Surface this clearly rather than pretending it succeeded.
        return [
            'ok' => false,
            'message' => 'ClickPesa refund is not supported by this driver yet.',
            'raw' => [],
        ];
    }

    public function verifyWebhook(array $payload, string $signatureHeader): bool
    {
        // Confirmed via ClickPesa's Checksum documentation:
        // - Checksum is computed over the FULL payload (not just payload.data),
        //   excluding the "checksum" and "checksumMethod" fields.
        // - Canonicalize: recursively sort all object keys alphabetically.
        // - Serialize to compact JSON (no extra whitespace).
        // - HMAC-SHA256 with the checksum key, hex digest.
        $checksumKey = $this->config['checksum_key'] ?? null;
        $providedChecksum = $payload['checksum'] ?? null;

        if (! $checksumKey || ! $providedChecksum) {
            // No checksum key configured for this provider row, or the
            // provider sent no checksum on this payload — cannot verify.
            return false;
        }

        $expected = $this->checksum($payload);

        if (! $expected) {
            return false;
        }

        return hash_equals($expected, (string) $providedChecksum);
    }

    public function normalizeWebhookPayload(array $payload): array
    {
        $event = strtoupper(trim((string) ($payload['event'] ?? '')));
        $data = $payload['data'] ?? $payload;

        return match ($event) {
            'PAYMENT RECEIVED', 'PAYMENT FAILED' => [
                'orderReference' => $data['orderReference'] ?? null,
                'status' => $this->mapStatus($data['status'] ?? null),
                'providerReference' => $data['id'] ?? $data['paymentReference'] ?? null,
                'amount' => isset($data['collectedAmount']) ? (float) $data['collectedAmount'] : null,
                'currency' => $data['collectedCurrency'] ?? null,
                'metadata' => $payload,
            ],
            'DEPOSIT RECEIVED' => [
                'orderReference' => $data['orderReference'] ?? null,
                'status' => $this->mapStatus($data['status'] ?? null),
                'providerReference' => $data['id'] ?? $data['paymentReference'] ?? null,
                'amount' => isset($data['depositAmount']) ? (float) $data['depositAmount'] : null,
                'currency' => $data['depositCurrency'] ?? null,
                'metadata' => $payload,
            ],
            'PAYOUT INITIATED', 'PAYOUT REFUNDED', 'PAYOUT REVERSED' => [
                'orderReference' => $data['orderReference'] ?? null,
                'status' => $this->mapStatus($data['status'] ?? null),
                'providerReference' => $data['id'] ?? null,
                'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
                'currency' => $data['currency'] ?? null,
                'metadata' => $payload,
            ],
            default => [
                'orderReference' => $data['orderReference'] ?? null,
                'status' => $this->mapStatus($data['status'] ?? null),
                'providerReference' => $data['id'] ?? null,
                'amount' => null,
                'currency' => null,
                'metadata' => $payload,
            ],
        };
    }

    //Payout routes

    public function payout(array $payload): array
    {
        foreach (['amount', 'currency', 'orderReference', 'phoneNumber'] as $key) {
            if (! array_key_exists($key, $payload)) {
                return [
                    'ok'      => false,
                    'message' => "Payout payload missing: {$key}",
                    'raw'     => [],
                ];
            }
        }

        $body = [
            'amount'         => (string) $payload['amount'],
            'currency'       => (string) $payload['currency'],
            'orderReference' => (string) $payload['orderReference'],
            'phoneNumber'    => (string) $payload['phoneNumber'],
        ];

        $checksum = $this->checksum($body);
        if ($checksum) $body['checksum'] = $checksum;

        try {
            $res = Http::withHeaders([
                'Authorization' => $this->token(),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ])->post($this->baseUrl() . '/payouts/create-mobile-money-payout', $body);

            $formatted = $this->formatResponse($res, 'Payout initiated.', 'Payout failed.');

            return [
                'ok'                => $formatted['ok'],
                'providerReference' => $formatted['data']['id'] ?? null,
                'status'            => $formatted['ok'] ? 'processing' : 'failed',
                'message'           => $formatted['message'],
                'raw'               => $formatted['data'] ?? [],
            ];
        } catch (\Throwable $e) {
            return $this->exceptionResult($e);
        }
    }

    // ─────────────────────────────────────────────
    // ClickPesa-specific helpers (adapted from the
    // monolith's ClickPesaService)
    // ─────────────────────────────────────────────

    private function baseUrl(): string
    {
        return rtrim((string) ($this->config['base_url'] ?? ''), '/');
    }

    private function token(): string
    {
        $apiKey = (string) ($this->config['api_key'] ?? '');
        $clientId = (string) ($this->config['client_id'] ?? '');
        $cacheKey = 'clickpesa_token_' . md5($clientId . $apiKey);

        return Cache::remember($cacheKey, now()->addMinutes(55), function () use ($apiKey, $clientId) {
            $res = Http::withHeaders([
                'api-key' => $apiKey,
                'client-id' => $clientId,
            ])->post($this->baseUrl() . '/generate-token');

            if (! $res->successful() || ! $res->json('token')) {
                throw new \RuntimeException('ClickPesa token failed.');
            }

            return (string) $res->json('token');
        });
    }

    private function checksum(array $payload): ?string
    {
        $key = $this->config['checksum_key'] ?? null;

        if (! $key) {
            return null;
        }

        unset($payload['checksum'], $payload['checksumMethod']);

        $canonical = $this->canonicalize($payload);
        $json = json_encode($canonical, JSON_UNESCAPED_SLASHES);

        return hash_hmac('sha256', (string) $json, (string) $key);
    }

    private function canonicalize($data)
    {
        if (! is_array($data)) {
            return $data;
        }

        $isAssoc = array_keys($data) !== range(0, count($data) - 1);

        if ($isAssoc) {
            ksort($data);

            foreach ($data as $key => $value) {
                $data[$key] = $this->canonicalize($value);
            }

            return $data;
        }

        return array_map(fn($value) => $this->canonicalize($value), $data);
    }

    private function formatResponse($res, string $successMessage, string $fallbackError): array
    {
        $body = $res->json();

        if (! is_array($body)) {
            $body = ['raw' => $res->body()];
        }

        if (! $res->successful()) {
            return [
                'ok' => false,
                'message' => $body['message'] ?? $body['error'] ?? $res->body() ?? $fallbackError,
                'data' => $body,
                'status' => $res->status(),
            ];
        }

        return [
            'ok' => true,
            'message' => $body['message'] ?? $successMessage,
            'data' => $body,
            'status' => $res->status(),
        ];
    }

    private function extractStatus(array $payload): ?string
    {
        foreach (['status', 'paymentStatus', 'state', 'collectionStatus'] as $key) {
            if (! empty($payload[$key])) {
                return strtoupper(trim((string) $payload[$key]));
            }
        }

        if (isset($payload[0]) && is_array($payload[0])) {
            foreach (['status', 'paymentStatus', 'state', 'collectionStatus'] as $key) {
                if (! empty($payload[0][$key])) {
                    return strtoupper(trim((string) $payload[0][$key]));
                }
            }
        }

        return null;
    }

    /**
     * Map ClickPesa's raw status vocabulary onto the driver contract's
     * standardized set: pending|confirmed|failed|cancelled.
     *
     * Confirmed by ClickPesa webhook docs: SUCCESS, FAILED, REFUNDED, REVERSED.
     * The others (SETTLED, COMPLETED, PAID, CANCELLED, REJECTED) are kept as
     * defensive fallbacks in case other endpoints use different vocabulary,
     * but are not confirmed by documentation.
     */
    private function mapStatus(?string $rawStatus): string
    {
        $rawStatus = $rawStatus ? strtoupper(trim($rawStatus)) : null;

        return match (true) {
            in_array($rawStatus, ['SUCCESS', 'SETTLED', 'COMPLETED', 'PAID'], true) => 'confirmed',
            in_array($rawStatus, ['FAILED', 'REJECTED'], true) => 'failed',
            in_array($rawStatus, ['CANCELLED', 'REVERSED', 'REFUNDED'], true) => 'cancelled',
            default => 'pending',
        };
    }

    private function exceptionResult(\Throwable $e): array
    {
        return [
            'ok' => false,
            'providerReference' => null,
            'status' => 'failed',
            'message' => $e->getMessage(),
            'raw' => [],
        ];
    }
}
