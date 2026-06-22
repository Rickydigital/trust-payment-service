<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escrow_splits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escrow_wallet_id')->constrained('escrow_wallets');
            $table->enum('recipient_type', ['seller', 'delivery_service', 'platform']);
            $table->string('recipient_id')->nullable(); // seller profile id, delivery service id, or null for platform
            $table->json('recipient_account')->nullable(); // payout account details
            $table->decimal('amount', 14, 2);
            $table->string('currency', 8)->default('TZS');
            $table->foreignId('payout_job_id')->nullable()->constrained('payout_jobs');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escrow_splits');
    }
};