<?php

namespace App\Contracts;

interface PaymentDriverInterface
{
    /**
     * Initiate a payment collection with the external provider.
     *
     * @param array $payload {
     *     amount: float,
     *     currency: string,
     *     orderReference: string,
     *     payerPhone?: string,
     *     payerName?: string,
     *     cardDetails?: array,
     * }
     *
     * @return array {
     *     ok: bool,
     *     providerReference: ?string,
     *     status: string,   // pending|confirmed|failed
     *     message: string,
     *     raw: array,
     * }
     */
    public function initiate(array $payload): array;

    /**
     * Query the external provider for the current status of a transaction.
     *
     * @param string $providerReference
     *
     * @return array {
     *     ok: bool,
     *     status: string,   // pending|confirmed|failed|cancelled
     *     raw: array,
     * }
     */
    public function queryStatus(string $providerReference): array;

    /**
     * Initiate a refund with the external provider.
     *
     * @param string $providerReference
     * @param float  $amount
     *
     * @return array {
     *     ok: bool,
     *     message: string,
     *     raw: array,
     * }
     */
    public function refund(string $providerReference, float $amount): array;

    /**
     * Verify an incoming webhook's signature using the provider's
     * specific mechanism (HMAC, RSA, etc).
     *
     * @param array  $payload         Raw decoded webhook payload.
     * @param string $signatureHeader Raw signature header value.
     *
     * @return bool
     */
    public function verifyWebhook(array $payload, string $signatureHeader): bool;

    /**
     * Normalize a raw provider webhook payload into a standardized
     * internal shape with consistent field names.
     *
     * @param array $payload Raw decoded webhook payload.
     *
     * @return array {
     *     orderReference: ?string,
     *     status: string,   // pending|confirmed|failed|cancelled
     *     providerReference: ?string,
     *     amount: ?float,
     *     currency: ?string,
     *     metadata: array,
     * }
     */
    public function normalizeWebhookPayload(array $payload): array;
    public function payout(array $payload): array;
}