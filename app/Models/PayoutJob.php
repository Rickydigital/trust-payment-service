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
        'provider_message',
        'provider_request',
        'provider_response',
        'attempts',
        'last_attempted_at',
        'completed_at',
        'error_message',
        'held_reason',
        'held_by',
        'held_at',
        'released_by',
        'released_at',
        'manually_completed_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'provider_request' => 'array',
        'provider_response' => 'array',
        'attempts' => 'integer',
        'last_attempted_at' => 'datetime',
        'completed_at' => 'datetime',
        'held_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function escrowSplit(): BelongsTo
    {
        return $this->belongsTo(EscrowSplit::class);
    }
}
