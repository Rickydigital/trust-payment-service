<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('escrow_splits', function (Blueprint $table) {
            $table->string('status', 40)->default('held')->after('currency');
            $table->decimal('gross_amount', 14, 2)->default(0)->after('amount');
            $table->decimal('platform_fee', 14, 2)->default(0)->after('gross_amount');
            $table->decimal('net_amount', 14, 2)->default(0)->after('platform_fee');
            $table->timestamp('available_at')->nullable()->after('payout_job_id');
            $table->timestamp('released_at')->nullable()->after('available_at');
            $table->timestamp('paid_at')->nullable()->after('released_at');
        });

        DB::table('escrow_splits')->update([
            'gross_amount' => DB::raw('amount'),
            'net_amount' => DB::raw('amount'),
        ]);
    }

    public function down(): void
    {
        Schema::table('escrow_splits', function (Blueprint $table) {
            $table->dropColumn([
                'status',
                'gross_amount',
                'platform_fee',
                'net_amount',
                'available_at',
                'released_at',
                'paid_at',
            ]);
        });
    }
};
