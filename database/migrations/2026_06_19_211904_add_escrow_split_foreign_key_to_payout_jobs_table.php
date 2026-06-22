<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_jobs', function (Blueprint $table) {
            $table->foreign('escrow_split_id')
                ->references('id')->on('escrow_splits');
        });
    }

    public function down(): void
    {
        Schema::table('payout_jobs', function (Blueprint $table) {
            $table->dropForeign(['escrow_split_id']);
        });
    }
};