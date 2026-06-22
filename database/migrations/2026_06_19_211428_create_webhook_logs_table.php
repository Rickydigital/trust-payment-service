<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('endpoint'); // which URL received it
            $table->json('payload');
            $table->boolean('signature_valid')->default(false);
            $table->foreignId('matched_transaction_id')
                ->nullable()
                ->constrained('payment_transactions');
            $table->enum('processing_status', [
                'received',
                'processed',
                'ignored',
                'failed',
            ])->default('received');
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};