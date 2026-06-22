<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            [
                'provider_key' => 'clickpesa',
                'display_name' => 'Mobile Money (ClickPesa)',
                'logo_url' => null,
                'type' => 'mobile_money',
                'driver_class' => \App\Drivers\ClickPesaDriver::class,
                'config' => [
                    'base_url' => config('services.clickpesa.base_url'),
                    'api_key' => config('services.clickpesa.api_key'),
                    'client_id' => config('services.clickpesa.client_id'),
                    'checksum_key' => config('services.clickpesa.checksum_key'),
                ],
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'provider_key' => 'azampesa',
                'display_name' => 'AzamPesa',
                'logo_url' => null,
                'type' => 'mobile_money',
                // Placeholder — driver not built yet. Stays inactive until
                // App\Drivers\AzamPesaDriver exists and is verified.
                'driver_class' => \App\Drivers\AzamPesaDriver::class,
                'config' => [],
                'is_active' => false,
                'sort_order' => 2,
            ],
            [
                'provider_key' => 'nala',
                'display_name' => 'NALA',
                'logo_url' => null,
                'type' => 'wallet',
                // Placeholder — driver not built yet.
                'driver_class' => \App\Drivers\NalaDriver::class,
                'config' => [],
                'is_active' => false,
                'sort_order' => 3,
            ],
            [
                'provider_key' => 'bank',
                'display_name' => 'Bank Transfer',
                'logo_url' => null,
                'type' => 'bank',
                // Placeholder — driver not built yet.
                'driver_class' => \App\Drivers\BankDriver::class,
                'config' => [],
                'is_active' => false,
                'sort_order' => 4,
            ],
            [
                'provider_key' => 'card',
                'display_name' => 'Debit / Credit Card',
                'logo_url' => null,
                'type' => 'card',
                // Placeholder — driver not built yet.
                'driver_class' => \App\Drivers\CardDriver::class,
                'config' => [],
                'is_active' => false,
                'sort_order' => 5,
            ],
        ];

        foreach ($methods as $method) {
            PaymentMethod::updateOrCreate(
                ['provider_key' => $method['provider_key']],
                $method
            );
        }

        $this->command->info('Payment methods seeded: ' . count($methods) . ' rows (1 active: clickpesa).');
    }
}