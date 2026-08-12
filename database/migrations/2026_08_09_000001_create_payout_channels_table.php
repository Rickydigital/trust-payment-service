<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_channels', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_key', 64)->index();
            $table->string('code', 64);
            $table->string('display_name', 120);
            $table->string('type', 40)->default('mobile_money')->index();
            $table->string('country_code', 2)->default('TZ');
            $table->string('currency', 3)->default('TZS');
            $table->string('logo_url')->nullable();
            $table->string('phone_hint', 60)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider_key', 'code'], 'payout_channels_provider_code_unique');
        });

        $now = now();
        DB::table('payout_channels')->insert([
            $this->channel('mpesa', 'M-Pesa', 1, $now),
            $this->channel('airtel_money', 'Airtel Money', 2, $now),
            $this->channel('mixx_by_yas', 'Mixx by Yas', 3, $now, ['former_name' => 'Tigo Pesa']),
            $this->channel('halo_pesa', 'HaloPesa', 4, $now),
            $this->channel('ezy_pesa', 'EzyPesa', 5, $now),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_channels');
    }

    private function channel(
        string $code,
        string $displayName,
        int $sortOrder,
        mixed $now,
        array $metadata = [],
    ): array {
        return [
            'provider_key' => 'clickpesa',
            'code' => $code,
            'display_name' => $displayName,
            'type' => 'mobile_money',
            'country_code' => 'TZ',
            'currency' => 'TZS',
            'logo_url' => null,
            'phone_hint' => '07XXXXXXXX or 2557XXXXXXXX',
            'is_active' => true,
            'sort_order' => $sortOrder,
            'metadata' => empty($metadata) ? null : json_encode($metadata),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
};
