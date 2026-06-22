<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PayoutJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'escrow_split_id',
        'recipient_type',
        'recipient_id',
        'amount',
        'currency',
        'provider_key',
        'status',
        'provider_reference',
        'attempts',
        'last_attempted_at',
        'completed_at',
        'error_message',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'attempts' => 'integer',
        'last_attempted_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function escrowSplit(): BelongsTo
    {
        return $this->belongsTo(EscrowSplit::class);
    }
}