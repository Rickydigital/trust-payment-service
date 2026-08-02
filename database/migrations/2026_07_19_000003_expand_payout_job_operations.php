<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE payout_jobs DROP CONSTRAINT IF EXISTS payout_jobs_status_check');
            DB::statement('ALTER TABLE payout_jobs ALTER COLUMN status TYPE VARCHAR(40)');
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE payout_jobs MODIFY status VARCHAR(40) NOT NULL DEFAULT 'queued'");
        }

        Schema::table('payout_jobs', function (Blueprint $table) {
            if (! Schema::hasColumn('payout_jobs', 'provider_message')) {
                $table->text('provider_message')->nullable()->after('provider_reference');
            }

            if (! Schema::hasColumn('payout_jobs', 'provider_request')) {
                $table->json('provider_request')->nullable()->after('provider_message');
            }

            if (! Schema::hasColumn('payout_jobs', 'provider_response')) {
                $table->json('provider_response')->nullable()->after('provider_request');
            }

            if (! Schema::hasColumn('payout_jobs', 'held_reason')) {
                $table->text('held_reason')->nullable()->after('error_message');
            }

            if (! Schema::hasColumn('payout_jobs', 'held_by')) {
                $table->string('held_by')->nullable()->after('held_reason');
            }

            if (! Schema::hasColumn('payout_jobs', 'held_at')) {
                $table->timestamp('held_at')->nullable()->after('held_by');
            }

            if (! Schema::hasColumn('payout_jobs', 'released_by')) {
                $table->string('released_by')->nullable()->after('held_at');
            }

            if (! Schema::hasColumn('payout_jobs', 'released_at')) {
                $table->timestamp('released_at')->nullable()->after('released_by');
            }

            if (! Schema::hasColumn('payout_jobs', 'manually_completed_by')) {
                $table->string('manually_completed_by')->nullable()->after('released_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payout_jobs', function (Blueprint $table) {
            foreach ([
                'provider_message',
                'provider_request',
                'provider_response',
                'held_reason',
                'held_by',
                'held_at',
                'released_by',
                'released_at',
                'manually_completed_by',
            ] as $column) {
                if (Schema::hasColumn('payout_jobs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
