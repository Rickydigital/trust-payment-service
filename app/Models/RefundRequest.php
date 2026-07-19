<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class RefundRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'payment_transaction_id',
        'order_reference',
        'source_service',
        'return_reference',
        'dispute_reference',
        'requested_by_type',
        'requested_by_id',
        'amount',
        'currency',
        'provider_key',
        'provider_reference',
        'callback_url',
        'status',
        'reason',
        'review_note',
        'provider_response',
        'failure_reason',
        'metadata',
        'requested_at',
        'approved_at',
        'rejected_at',
        'processed_at',
        'completed_at',
        'failed_at',
        'approved_by',
        'rejected_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'provider_response' => 'array',
        'metadata' => 'array',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'processed_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }

    public static function generateReference(): string
    {
        do {
            $candidate = 'RFD-' . now()->format('Ymd') . '-' . Str::upper(Str::random(8));
        } while (self::query()->where('reference', $candidate)->exists());

        return $candidate;
    }
}
