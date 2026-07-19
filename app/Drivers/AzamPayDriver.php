<?php

namespace App\Drivers;

use App\Contracts\PaymentDriverInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AzamPayDriver implements PaymentDriverInterface
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Initiate AzamPay mobile-money checkout.
     */
    public function initiate(array $payload): array
    {
        foreach (
            ['amount', 'currency', 'orderReference', 'payerPhone', 'provider']
            as $key
        ) {
            if (! isset($payload[$key]) || $payload[$key] === '') {
                return [
                    'ok' => false,
                    'providerReference' => null,
                    'status' => 'failed',
                    'message' => "AzamPay payload missing: {$key}",
                    'raw' => [],
                ];
            }
        }

        $externalId = $this->sanitizeReference(
            (string) $payload['orderReference']
        );

        $body = [
            'accountNumber' => $this->normalizePhone(
                (string) $payload['payerPhone']
            ),
            'amount' => (string) $payload['amount'],
            'currency' => strtoupper((string) $payload['currency']),
            'provider' => $this->normalizeProvider(
                (string) $payload['provider']
            ),
            'externalId' => $externalId,
        ];

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withToken($this->token())
                ->withHeaders([
                    'X-API-Key' => $this->apiKey(),
                ])
                ->timeout(60)
                ->retry(2, 500)
                ->post(
                    $this->checkoutBaseUrl() . '/azampay/mno/checkout',
                    $body
                );

            $responseBody = $response->json();

            Log::info('AZAMPAY_CHECKOUT_RESPONSE', [
                'request_url' => $this->checkoutBaseUrl() . '/azampay/mno/checkout',
                'request_body' => $body,
                'status_code' => $response->status(),
                'content_type' => $response->header('Content-Type'),
                'headers' => $response->headers(),
                'raw_body' => $response->body(),
                'json_body' => $responseBody,
                'external_id' => $externalId,
            ]);

            if (! is_array($responseBody)) {
                $responseBody = [
                    'raw_response' => $response->body(),
                ];
            }

            Log::info('AZAMPAY_CHECKOUT_RESPONSE', [
                'status_code' => $response->status(),
                'external_id' => $externalId,
                'response' => $responseBody,
            ]);

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'providerReference' => null,
                    'status' => 'failed',
                    'message' => $this->extractMessage(
                        $responseBody,
                        'AzamPay checkout request failed.'
                    ),
                    'raw' => $responseBody,
                ];
            }

            $providerReference =
                $responseBody['transactionId']
                ?? $responseBody['transactionID']
                ?? $responseBody['referenceId']
                ?? $responseBody['reference']
                ?? $externalId;

            $rawStatus =
                $responseBody['status']
                ?? $responseBody['transactionStatus']
                ?? null;

            return [
                'ok' => true,
                'providerReference' => (string) $providerReference,
                'status' => $this->mapStatus($rawStatus),
                'message' => $this->extractMessage(
                    $responseBody,
                    'Payment prompt initiated.'
                ),
                'raw' => $responseBody,
            ];
        } catch (\Throwable $exception) {
            Log::error('AZAMPAY_CHECKOUT_EXCEPTION', [
                'message' => $exception->getMessage(),
                'order_reference' => $payload['orderReference'] ?? null,
            ]);

            return $this->exceptionResult($exception);
        }
    }

    /**
     * AzamPay status endpoint may depend on the enabled API version.
     *
     * Configure status_path only after confirming it from your merchant
     * dashboard/API documentation.
     */
    public function queryStatus(string $providerReference): array
    {
        $statusPath = $this->config['status_path'] ?? null;

        if (! $statusPath) {
            return [
                'ok' => false,
                'status' => 'pending',
                'raw' => [
                    'message' => 'AzamPay status endpoint is not configured. Use the webhook as the primary payment confirmation.',
                    'providerReference' => $providerReference,
                ],
            ];
        }

        try {
            $url = $this->checkoutBaseUrl()
                . '/'
                . ltrim($statusPath, '/');

            $response = Http::acceptJson()
                ->withToken($this->token())
                ->withHeaders([
                    'X-API-Key' => $this->apiKey(),
                ])
                ->get($url, [
                    'transactionId' => $providerReference,
                    'reference' => $providerReference,
                ]);

            $body = $response->json();

            if (! is_array($body)) {
                $body = ['raw_response' => $response->body()];
            }

            return [
                'ok' => $response->successful(),
                'status' => $this->mapStatus(
                    $body['status']
                    ?? $body['transactionStatus']
                    ?? null
                ),
                'raw' => $body,
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'status' => 'pending',
                'raw' => [
                    'error' => $exception->getMessage(),
                ],
            ];
        }
    }

    /**
     * Refund endpoint must be confirmed for your AzamPay merchant account.
     */
    public function refund(
        string $providerReference,
        float $amount
    ): array {
        return [
            'ok' => false,
            'message' => 'AzamPay refund is not configured in this driver.',
            'raw' => [
                'providerReference' => $providerReference,
                'amount' => $amount,
            ],
        ];
    }

    /**
     * AzamPay callback verification.
     *
     * This implementation uses a private callback token configured by you.
     * Add the token to the webhook URL or a custom header.
     */
    public function verifyWebhook(
        array $payload,
        string $signatureHeader
    ): bool {
        $expectedToken = (string) (
            $this->config['webhook_secret'] ?? ''
        );

        if ($expectedToken === '' || $signatureHeader === '') {
            return false;
        }

        return hash_equals($expectedToken, $signatureHeader);
    }

    /**
     * Normalize AzamPay callback data into your internal structure.
     */
    public function normalizeWebhookPayload(array $payload): array
    {
        $data = isset($payload['data']) && is_array($payload['data'])
            ? $payload['data']
            : $payload;

        $orderReference =
            $data['externalId']
            ?? $data['externalID']
            ?? $data['referenceId']
            ?? $data['reference']
            ?? null;

        $providerReference =
            $data['transactionId']
            ?? $data['transactionID']
            ?? $data['providerReference']
            ?? $data['reference']
            ?? null;

        $rawStatus =
            $data['status']
            ?? $data['transactionStatus']
            ?? $payload['status']
            ?? null;

        $amount =
            $data['amount']
            ?? $data['transactionAmount']
            ?? null;

        return [
            'orderReference' => $orderReference
                ? (string) $orderReference
                : null,

            'status' => $this->mapStatus($rawStatus),

            'providerReference' => $providerReference
                ? (string) $providerReference
                : null,

            'amount' => is_numeric($amount)
                ? (float) $amount
                : null,

            'currency' =>
                $data['currency']
                ?? $data['transactionCurrency']
                ?? 'TZS',

            'metadata' => $payload,
        ];
    }

    /**
     * Payout support must be enabled and documented for the merchant.
     */
    public function payout(array $payload): array
    {
        return [
            'ok' => false,
            'providerReference' => null,
            'status' => 'failed',
            'message' => 'AzamPay payout is not configured in this driver.',
            'raw' => $payload,
        ];
    }

    private function token(): string
    {
        $appName = (string) ($this->config['app_name'] ?? '');
        $clientId = (string) ($this->config['client_id'] ?? '');
        $clientSecret = (string) (
            $this->config['client_secret'] ?? ''
        );

        if (
            $appName === ''
            || $clientId === ''
            || $clientSecret === ''
        ) {
            throw new \RuntimeException(
                'AzamPay authentication credentials are incomplete.'
            );
        }

        $cacheKey = 'azampay_access_token_'
            . md5($appName . $clientId);

        return Cache::remember(
            $cacheKey,
            now()->addMinutes(50),
            function () use ($appName, $clientId, $clientSecret) {
                $response = Http::acceptJson()
                    ->asJson()
                    ->timeout(30)
                    ->post(
                        $this->authBaseUrl()
                        . '/AppRegistration/GenerateToken',
                        [
                            'appName' => $appName,
                            'clientId' => $clientId,
                            'clientSecret' => $clientSecret,
                        ]
                    );

                $body = $response->json();

                if (! is_array($body)) {
                    $body = [
                        'raw_response' => $response->body(),
                    ];
                }

                $token =
                    $body['data']['accessToken']
                    ?? $body['accessToken']
                    ?? $body['token']
                    ?? null;

                if (! $response->successful() || ! $token) {
                    throw new \RuntimeException(
                        $this->extractMessage(
                            $body,
                            'AzamPay authentication failed.'
                        )
                    );
                }

                return (string) $token;
            }
        );
    }

    private function authBaseUrl(): string
    {
        return rtrim(
            (string) ($this->config['auth_base_url'] ?? ''),
            '/'
        );
    }

    private function checkoutBaseUrl(): string
    {
        return rtrim(
            (string) ($this->config['checkout_base_url'] ?? ''),
            '/'
        );
    }

    private function apiKey(): string
    {
        $apiKey = (string) ($this->config['api_key'] ?? '');

        if ($apiKey === '') {
            throw new \RuntimeException(
                'AzamPay X-API-Key is missing.'
            );
        }

        return $apiKey;
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D+/', '', $phone);

        if (str_starts_with($phone, '255')) {
            return '0' . substr($phone, 3);
        }

        if (str_starts_with($phone, '7')) {
            return '0' . $phone;
        }

        return $phone;
    }

    private function normalizeProvider(string $provider): string
    {
        $normalized = strtolower(
            preg_replace('/[^A-Za-z]/', '', $provider)
        );

        return match ($normalized) {
            'mpesa', 'vodacom' => 'Mpesa',

            'airtel', 'airtelmoney' => 'Airtel',

            'tigo',
            'tigopesa',
            'mixx',
            'mixxbyyas',
            'yas' => 'Tigo',

            'halopesa',
            'halotel' => 'Halopesa',

            'azampesa',
            'azampay' => 'Azampesa',

            default => throw new \InvalidArgumentException(
                "Unsupported AzamPay provider: {$provider}"
            ),
        };
    }

    private function sanitizeReference(string $reference): string
    {
        $clean = preg_replace(
            '/[^A-Za-z0-9]/',
            '',
            $reference
        );

        return $clean !== ''
            ? $clean
            : Str::upper(Str::random(20));
    }

    private function mapStatus(mixed $status): string
    {
        $status = strtoupper(trim((string) $status));

        return match (true) {
            in_array($status, [
                'SUCCESS',
                'SUCCESSFUL',
                'COMPLETED',
                'CONFIRMED',
                'PAID',
            ], true) => 'confirmed',

            in_array($status, [
                'FAILED',
                'FAILURE',
                'DECLINED',
                'REJECTED',
                'ERROR',
            ], true) => 'failed',

            in_array($status, [
                'CANCELLED',
                'CANCELED',
                'REVERSED',
                'REFUNDED',
            ], true) => 'cancelled',

            default => 'pending',
        };
    }

    private function extractMessage(
        array $body,
        string $fallback
    ): string {
        return (string) (
            $body['message']
            ?? $body['Message']
            ?? $body['error']
            ?? $body['data']['message']
            ?? $fallback
        );
    }

    private function exceptionResult(
        \Throwable $exception
    ): array {
        return [
            'ok' => false,
            'providerReference' => null,
            'status' => 'failed',
            'message' => $exception->getMessage(),
            'raw' => [],
        ];
    }
}