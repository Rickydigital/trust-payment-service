<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_reference',
        'payment_transaction_id',
        'attempt_reference',
        'provider_key',
        'status',
        'amount',
        'currency',
        'payer_phone',
        'failure_reason',
        'initiated_at',
        'confirmed_at',
        'failed_at',
        'cancelled_at',
        'metadata',
    ];

    protected $casts = [
        'amount'        => 'decimal:2',
        'metadata'      => 'array',
        'initiated_at'  => 'datetime',
        'confirmed_at'  => 'datetime',
        'failed_at'     => 'datetime',
        'cancelled_at'  => 'datetime',
    ];

    // Statuses
    public const STATUS_INITIATED = 'initiated';
    public const STATUS_PENDING   = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public function transaction()
    {
        return $this->belongsTo(
            PaymentTransaction::class,
            'payment_transaction_id'
        );
    }

    public function isPending(): bool
    {
        return in_array($this->status, [
            self::STATUS_INITIATED,
            self::STATUS_PENDING,
        ]);
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public static function generateReference(): string
    {
        return 'ATT-' .
            now()->format('Ymd') .
            '-' .
            strtoupper(substr(md5(uniqid()), 0, 8));
    }
}