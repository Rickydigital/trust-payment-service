<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EscrowSplit extends Model
{
    use HasFactory;

    protected $fillable = [
        'escrow_wallet_id',
        'recipient_type',
        'recipient_id',
        'recipient_account',
        'amount',
        'gross_amount',
        'platform_fee',
        'net_amount',
        'currency',
        'status',
        'payout_job_id',
        'available_at',
        'released_at',
        'paid_at',
    ];

    protected $casts = [
        'recipient_account' => 'array',
        'amount' => 'decimal:2',
        'gross_amount' => 'decimal:2',
        'platform_fee' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'available_at' => 'datetime',
        'released_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function escrowWallet(): BelongsTo
    {
        return $this->belongsTo(EscrowWallet::class);
    }

    public function payoutJob(): BelongsTo
    {
        return $this->belongsTo(PayoutJob::class);
    }
}
