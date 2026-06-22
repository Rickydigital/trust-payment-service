<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('provider_key')->unique();
            $table->string('display_name');
            $table->string('logo_url')->nullable();
            $table->enum('type', ['mobile_money', 'bank', 'card', 'wallet']);
            $table->string('driver_class');
            $table->text('config')->nullable(); // JSON, encrypted at rest via model cast
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};