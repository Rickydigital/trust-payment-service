<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_settings', function (Blueprint $table) {
            $table->decimal('payout_fee_percent', 5, 2)->default(5)->after('seller_fee_percent');
            $table->decimal('payout_minimum_amount', 14, 2)->default(10000)->after('payout_fee_percent');
            $table->decimal('payout_maximum_amount', 14, 2)->default(5000000)->after('payout_minimum_amount');
        });

        DB::table('fee_settings')->where('key', 'default')->update([
            'payout_fee_percent' => (float) config('fees.payout_fee_percent', 5),
            'payout_minimum_amount' => (float) config('fees.payout_minimum_amount', 10000),
            'payout_maximum_amount' => (float) config('fees.payout_maximum_amount', 5000000),
        ]);
    }

    public function down(): void
    {
        Schema::table('fee_settings', function (Blueprint $table) {
            $table->dropColumn([
                'payout_fee_percent',
                'payout_minimum_amount',
                'payout_maximum_amount',
            ]);
        });
    }
};
