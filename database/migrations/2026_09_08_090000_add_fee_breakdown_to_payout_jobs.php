<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_jobs', function (Blueprint $table) {
            $table->decimal('requested_amount', 14, 2)->nullable()->after('recipient_id');
            $table->decimal('payout_fee_percent', 5, 2)->default(0)->after('requested_amount');
            $table->decimal('payout_fee_amount', 14, 2)->default(0)->after('payout_fee_percent');
            $table->decimal('net_amount', 14, 2)->nullable()->after('payout_fee_amount');
        });

        DB::table('payout_jobs')->whereNull('requested_amount')->update([
            'requested_amount' => DB::raw('amount'),
            'net_amount' => DB::raw('amount'),
        ]);
    }

    public function down(): void
    {
        Schema::table('payout_jobs', function (Blueprint $table) {
            $table->dropColumn([
                'requested_amount',
                'payout_fee_percent',
                'payout_fee_amount',
                'net_amount',
            ]);
        });
    }
};
