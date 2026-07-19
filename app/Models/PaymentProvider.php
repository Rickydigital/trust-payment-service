<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentProvider extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'environment',
        'base_url',
        'status',
        'health_status',
        'supports_collection',
        'supports_payout',
        'supports_refund',
        'supports_webhook',
        'credentials',
        'webhook_config',
        'is_active',
        'last_checked_at',
        'last_success_at',
        'last_failure_at',
        'last_error',
        'metadata',
    ];

    protected $casts = [
        'supports_collection' => 'boolean',
        'supports_payout' => 'boolean',
        'supports_refund' => 'boolean',
        'supports_webhook' => 'boolean',
        'credentials' => 'encrypted:array',
        'webhook_config' => 'encrypted:array',
        'is_active' => 'boolean',
        'last_checked_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $hidden = [
        'credentials',
        'webhook_config',
    ];

    public function methods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }
}
