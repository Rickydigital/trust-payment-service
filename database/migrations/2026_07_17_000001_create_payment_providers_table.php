<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_providers', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('environment')->default('local');
            $table->string('base_url')->nullable();
            $table->string('status')->default('disabled');
            $table->string('health_status')->default('unknown');
            $table->boolean('supports_collection')->default(false);
            $table->boolean('supports_payout')->default(false);
            $table->boolean('supports_refund')->default(false);
            $table->boolean('supports_webhook')->default(false);
            $table->text('credentials')->nullable();
            $table->text('webhook_config')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->foreignId('payment_provider_id')
                ->nullable()
                ->after('id')
                ->constrained('payment_providers')
                ->nullOnDelete();
        });

        DB::table('payment_methods')
            ->orderBy('id')
            ->get()
            ->each(function ($method): void {
                $driverClass = (string) $method->driver_class;
                $driverExists = class_exists($driverClass);

                $providerId = DB::table('payment_providers')->insertGetId([
                    'key' => $method->provider_key,
                    'name' => $method->display_name,
                    'environment' => env('APP_ENV', 'local'),
                    'status' => $method->is_active ? 'active' : 'disabled',
                    'health_status' => $driverExists ? 'unknown' : 'driver_missing',
                    'supports_collection' => $method->type !== 'bank' && ! str_contains(strtolower($driverClass), 'manualpayout'),
                    'supports_payout' => $driverExists && method_exists($driverClass, 'payout'),
                    'supports_refund' => $driverExists && method_exists($driverClass, 'refund'),
                    'supports_webhook' => $driverExists && method_exists($driverClass, 'verifyWebhook'),
                    'is_active' => (bool) $method->is_active,
                    'metadata' => json_encode([
                        'created_from' => 'payment_methods_backfill',
                        'method_id' => $method->id,
                        'driver_class' => $driverClass,
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('payment_methods')
                    ->where('id', $method->id)
                    ->update(['payment_provider_id' => $providerId]);
            });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_provider_id');
        });

        Schema::dropIfExists('payment_providers');
    }
};
