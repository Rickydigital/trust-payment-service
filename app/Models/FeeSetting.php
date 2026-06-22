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
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'buyer_fee_percent' => 'decimal:2',
        'seller_fee_percent' => 'decimal:2',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public static function current(): self
    {
        return static::query()
            ->where('key', 'default')
            ->where('is_active', true)
            ->firstOrFail();
    }
}