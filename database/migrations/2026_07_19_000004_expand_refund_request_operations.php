<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refund_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('refund_requests', 'provider_request')) {
                $table->json('provider_request')->nullable()->after('provider_reference');
            }

            if (! Schema::hasColumn('refund_requests', 'provider_message')) {
                $table->text('provider_message')->nullable()->after('provider_response');
            }

            if (! Schema::hasColumn('refund_requests', 'callback_status')) {
                $table->string('callback_status')->nullable()->after('callback_url');
            }

            if (! Schema::hasColumn('refund_requests', 'callback_response')) {
                $table->json('callback_response')->nullable()->after('callback_status');
            }

            if (! Schema::hasColumn('refund_requests', 'callback_error')) {
                $table->text('callback_error')->nullable()->after('callback_response');
            }

            if (! Schema::hasColumn('refund_requests', 'callback_attempted_at')) {
                $table->timestamp('callback_attempted_at')->nullable()->after('callback_error');
            }

            if (! Schema::hasColumn('refund_requests', 'manually_completed_by')) {
                $table->string('manually_completed_by')->nullable()->after('rejected_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('refund_requests', function (Blueprint $table) {
            $columns = [
                'provider_request',
                'provider_message',
                'callback_status',
                'callback_response',
                'callback_error',
                'callback_attempted_at',
                'manually_completed_by',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('refund_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
