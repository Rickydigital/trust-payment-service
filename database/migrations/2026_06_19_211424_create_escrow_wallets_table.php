<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escrow_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('payment_transactions');
            $table->string('order_reference')->index(); // denormalized for direct lookup
            $table->decimal('amount', 14, 2);
            $table->string('currency', 8)->default('TZS');
            $table->enum('status', [
                'holding',
                'releasing',
                'released',
                'refunded',
            ])->default('holding');
            $table->timestamp('held_at')->nullable();
            $table->timestamp('release_requested_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escrow_wallets');
    }
};