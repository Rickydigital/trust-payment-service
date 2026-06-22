<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EscrowWallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'order_reference',
        'amount',
        'currency',
        'status',
        'held_at',
        'release_requested_at',
        'released_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'held_at' => 'datetime',
        'release_requested_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'transaction_id');
    }

    public function splits(): HasMany
    {
        return $this->hasMany(EscrowSplit::class);
    }
}