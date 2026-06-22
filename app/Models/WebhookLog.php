<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookLog extends Model
{
    use HasFactory;

    const UPDATED_AT = null; // append-only log, no updated_at column

    protected $fillable = [
        'provider',
        'endpoint',
        'payload',
        'signature_valid',
        'matched_transaction_id',
        'processing_status',
        'error_message',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'signature_valid' => 'boolean',
        'processed_at' => 'datetime',
    ];

    public function matchedTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'matched_transaction_id');
    }
}