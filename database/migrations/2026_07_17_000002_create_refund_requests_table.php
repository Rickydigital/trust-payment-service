<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('payment_transaction_id')->nullable()->constrained('payment_transactions')->nullOnDelete();
            $table->string('order_reference')->index();
            $table->string('source_service')->default('trust');
            $table->string('return_reference')->nullable()->index();
            $table->string('dispute_reference')->nullable()->index();
            $table->string('requested_by_type')->nullable();
            $table->string('requested_by_id')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 8)->default('TZS');
            $table->string('provider_key')->nullable()->index();
            $table->string('provider_reference')->nullable()->index();
            $table->string('callback_url')->nullable();
            $table->enum('status', [
                'requested',
                'approved',
                'rejected',
                'processing',
                'completed',
                'failed',
                'manual_review',
            ])->default('requested')->index();
            $table->text('reason');
            $table->text('review_note')->nullable();
            $table->json('provider_response')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('approved_by')->nullable();
            $table->string('rejected_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_requests');
    }
};
