<?php

namespace Database\Seeders;

use App\Models\FeeSetting;
use Illuminate\Database\Seeder;

class FeeSettingSeeder extends Seeder
{
    public function run(): void
    {
        // Same default values as the main platform's TrustFeeSettingSeeder
        // ('default' provider row: buyer_fee_percent=1.00, seller_fee_percent=2.00).
        FeeSetting::updateOrCreate(
            ['key' => 'default'],
            [
                'buyer_fee_percent' => 1.00,
                'seller_fee_percent' => 2.00,
                'is_active' => true,
                'metadata' => [
                    'description' => 'Default platform fee setting, migrated from main platform TrustFeeSetting.',
                ],
            ]
        );

        $this->command->info('Fee settings seeded: 1 default row.');
    }
}