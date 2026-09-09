<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeeSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'buyer_fee_percent',
        'seller_fee_percent',
        'payout_fee_percent',
        'payout_minimum_amount',
        'payout_maximum_amount',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'buyer_fee_percent' => 'decimal:2',
        'seller_fee_percent' => 'decimal:2',
        'payout_fee_percent' => 'decimal:2',
        'payout_minimum_amount' => 'decimal:2',
        'payout_maximum_amount' => 'decimal:2',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['key' => 'default'],
            [
                'buyer_fee_percent' => (float) config('fees.buyer_fee_percent', 1),
                'seller_fee_percent' => (float) config('fees.seller_fee_percent', 2),
                'payout_fee_percent' => (float) config('fees.payout_fee_percent', 5),
                'payout_minimum_amount' => (float) config('fees.payout_minimum_amount', 10000),
                'payout_maximum_amount' => (float) config('fees.payout_maximum_amount', 5000000),
                'is_active' => true,
                'metadata' => ['source' => 'environment_fallback'],
            ],
        );
    }
}
