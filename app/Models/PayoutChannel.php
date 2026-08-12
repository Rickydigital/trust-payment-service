<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayoutChannel extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_key',
        'code',
        'display_name',
        'type',
        'country_code',
        'currency',
        'logo_url',
        'phone_hint',
        'is_active',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];
}
