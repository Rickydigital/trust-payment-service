<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PaymentTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'order_reference',
        'payment_method_id',
        'amount',
        'currency',
        'status',
        'provider_reference',
        'phone',
        'payer_name',
        'callback_url',
        'metadata',
        'confirmed_at',
        'failed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'metadata' => 'array',
        'confirmed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function escrowWallet(): HasOne
    {
        return $this->hasOne(EscrowWallet::class, 'transaction_id');
    }
}