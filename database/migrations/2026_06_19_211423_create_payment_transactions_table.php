<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique(); // PAY-YYYYMMDD-XXXXXX
            $table->string('order_reference')->index(); // ORD-XXXXXX, shared id — not a FK
            $table->foreignId('payment_method_id')->constrained('payment_methods');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 8)->default('TZS');
            $table->enum('status', [
                'initiated',
                'pending',
                'confirmed',
                'failed',
                'cancelled',
                'refunded',
            ])->default('initiated');
            $table->string('provider_reference')->nullable()->index();
            $table->string('phone')->nullable();
            $table->string('payer_name')->nullable();
            $table->string('callback_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};