<?php

namespace App\Models;

use App\Contracts\PaymentDriverInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_provider_id',
        'provider_key',
        'display_name',
        'logo_url',
        'type',
        'driver_class',
        'config',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'payment_provider_id' => 'integer',
        'config' => 'encrypted:array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Fields that must never be serialized out to API responses.
     * GET /methods explicitly excludes config and driver_class.
     */
    protected $hidden = [
        'config',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class, 'payment_provider_id');
    }

    public function driver(): PaymentDriverInterface
    {
        $class = $this->driver_class;

        return app($class, ['config' => $this->config ?? []]);
    }
}
