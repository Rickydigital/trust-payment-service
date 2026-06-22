<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique(); // POUT-YYYYMMDD-XXXXXX
            $table->unsignedBigInteger('escrow_split_id'); // FK added after escrow_splits exists
            $table->enum('recipient_type', ['seller', 'delivery_service', 'platform']);
            $table->string('recipient_id')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 8)->default('TZS');
            $table->string('provider_key'); // which driver to use for this payout
            $table->enum('status', [
                'queued',
                'processing',
                'completed',
                'failed',
            ])->default('queued');
            $table->string('provider_reference')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_jobs');
    }
};