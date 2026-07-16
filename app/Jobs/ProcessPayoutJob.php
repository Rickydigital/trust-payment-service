<?php

namespace App\Jobs;

use App\Models\PayoutJob;
use App\Services\PaymentRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPayoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60; // seconds between retries

    public function __construct(public readonly int $payoutJobId) {}

    public function handle(PaymentRouter $router): void
    {
        $job = PayoutJob::find($this->payoutJobId);

        if (! $job) {
            Log::warning('[PayoutJob] Row not found', ['id' => $this->payoutJobId]);
            return;
        }

        if ($job->status === 'completed') {
            return; // already done — duplicate dispatch, safe to ignore
        }

        $job->update([
            'status' => 'processing',
            'attempts' => $job->attempts + 1,
            'last_attempted_at' => now(),
        ]);

        try {
            $driver = $router->driver($job->provider_key);

            $account = $job->escrowSplit->recipient_account ?? [];
            $phone = $account['phone']
                ?? $account['mobile_phone']
                ?? $account['account_number']
                ?? null;

            if (! $phone) {
                throw new \RuntimeException(
                    "No phone number found for payout job {$job->id}"
                );
            }

            if (! method_exists($driver, 'payout')) {
                // payout() is not part of PaymentDriverInterface — only
                // ClickPesaDriver implements it directly today. Surface
                // this clearly rather than fatally erroring with an
                // unhelpful "call to undefined method".
                throw new \RuntimeException(
                    get_class($driver) . ' does not support payouts.'
                );
            }

            $result = $driver->payout([
                'amount' => (float) $job->amount,
                'currency' => $job->currency,
                'orderReference' => $job->reference,
                'phoneNumber' => $phone,
            ]);

            if (! ($result['ok'] ?? false)) {
                throw new \RuntimeException(
                    $result['message'] ?? 'Payout driver returned failure.'
                );
            }

            if (($result['manual_review'] ?? false) || ($result['status'] ?? null) === 'manual_review') {
                $job->update([
                    'status' => 'processing',
                    'provider_reference' => $result['providerReference'] ?? null,
                    'error_message' => $result['message'] ?? 'Manual payout pending.',
                ]);

                Log::info('[PayoutJob] Waiting for manual processing', [
                    'job_id' => $job->id,
                    'reference' => $job->reference,
                ]);

                return;
            }

            $job->update([
                'status' => 'completed',
                'provider_reference' => $result['providerReference'] ?? null,
                'completed_at' => now(),
            ]);

            $job->escrowSplit?->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);

            Log::info('[PayoutJob] Completed', [
                'job_id' => $job->id,
                'reference' => $job->reference,
            ]);

            $this->checkWalletCompletion($job);
        } catch (\Throwable $e) {
            Log::error('[PayoutJob] Failed', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
                'attempt' => $job->attempts,
            ]);

            $finalFailure = $job->attempts >= $this->tries;

            $job->update([
                'status' => $finalFailure ? 'failed' : 'queued',
                'error_message' => $e->getMessage(),
            ]);

            if ($finalFailure) {
                $job->escrowSplit?->update([
                    'status' => 'failed',
                ]);
            }

            if ($job->attempts < $this->tries) {
                throw $e; // Laravel re-queues automatically with backoff
            }
        }
    }

    private function checkWalletCompletion(PayoutJob $job): void
    {
        $split = $job->escrowSplit;
        $wallet = $split?->escrowWallet;

        if (! $wallet) {
            return;
        }

        // Count splits that have a payout job and whether all are done.
        // Splits with no payout job (e.g. platform splits, or delivery
        // splits with no payout account yet) are intentionally excluded —
        // see EscrowController::release for why those are left unqueued.
        $splits = $wallet->splits()->whereNotNull('payout_job_id')->get();

        $allDone = $splits->every(
            fn ($s) => $s->payoutJob?->status === 'completed'
        );

        if ($allDone && $wallet->status === 'releasing') {
            $wallet->update([
                'status' => 'released',
                'released_at' => now(),
            ]);

            Log::info('[Escrow] Wallet fully released', [
                'wallet_id' => $wallet->id,
                'order_reference' => $wallet->order_reference,
            ]);
        }
    }
}
