<?php

namespace App\Drivers;

use App\Contracts\PaymentDriverInterface;
use Illuminate\Support\Str;

class ManualPayoutDriver implements PaymentDriverInterface
{
    public function __construct(protected array $config = []) {}

    public function initiate(array $payload): array
    {
        return [
            'ok' => false,
            'providerReference' => null,
            'status' => 'failed',
            'message' => 'This method is only available for payouts.',
            'raw' => [],
        ];
    }

    public function queryStatus(string $providerReference): array
    {
        return [
            'ok' => true,
            'status' => 'pending',
            'raw' => [
                'providerReference' => $providerReference,
                'manual_review' => true,
            ],
        ];
    }

    public function refund(string $providerReference, float $amount): array
    {
        return [
            'ok' => false,
            'message' => 'Manual payout channels do not support automatic refunds.',
            'raw' => [
                'providerReference' => $providerReference,
                'amount' => $amount,
            ],
        ];
    }

    public function verifyWebhook(array $payload, string $signatureHeader): bool
    {
        return false;
    }

    public function normalizeWebhookPayload(array $payload): array
    {
        return [
            'orderReference' => $payload['orderReference'] ?? $payload['order_reference'] ?? null,
            'status' => $payload['status'] ?? 'pending',
            'providerReference' => $payload['providerReference'] ?? $payload['provider_reference'] ?? null,
            'amount' => isset($payload['amount']) ? (float) $payload['amount'] : null,
            'currency' => $payload['currency'] ?? null,
            'metadata' => $payload,
        ];
    }

    public function payout(array $payload): array
    {
        $channel = $this->config['channel'] ?? 'manual';
        $reference = 'MANUAL-' . strtoupper($channel) . '-' . Str::upper(Str::random(8));

        return [
            'ok' => true,
            'providerReference' => $reference,
            'status' => 'manual_review',
            'message' => 'Payout recorded for manual processing.',
            'manual_review' => true,
            'raw' => [
                'channel' => $channel,
                'amount' => $payload['amount'] ?? null,
                'currency' => $payload['currency'] ?? null,
                'orderReference' => $payload['orderReference'] ?? null,
            ],
        ];
    }
}
