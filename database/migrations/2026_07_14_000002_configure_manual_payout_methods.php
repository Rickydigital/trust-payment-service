<?php

use App\Drivers\ManualPayoutDriver;
use App\Models\PaymentMethod;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'bank' => ['display_name' => 'Bank Transfer', 'type' => 'bank', 'sort_order' => 4],
            'nala' => ['display_name' => 'NALA', 'type' => 'wallet', 'sort_order' => 3],
        ] as $providerKey => $details) {
            PaymentMethod::query()->updateOrCreate(
                ['provider_key' => $providerKey],
                [
                    'display_name' => $details['display_name'],
                    'logo_url' => null,
                    'type' => $details['type'],
                    'driver_class' => ManualPayoutDriver::class,
                    'config' => ['channel' => $providerKey],
                    'is_active' => false,
                    'sort_order' => $details['sort_order'],
                ]
            );
        }
    }

    public function down(): void
    {
        PaymentMethod::query()
            ->where('provider_key', 'bank')
            ->update([
                'driver_class' => 'App\\Drivers\\BankDriver',
                'config' => [],
            ]);

        PaymentMethod::query()
            ->where('provider_key', 'nala')
            ->update([
                'driver_class' => 'App\\Drivers\\NalaDriver',
                'config' => [],
            ]);
    }
};
