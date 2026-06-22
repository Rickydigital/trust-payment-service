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
        'currency',
        'payout_job_id',
    ];

    protected $casts = [
        'recipient_account' => 'array',
        'amount' => 'decimal:2',
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