<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE escrow_splits MODIFY recipient_type ENUM('seller','delivery_service','platform','user') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE escrow_splits MODIFY recipient_type ENUM('seller','delivery_service','platform') NOT NULL");
        }
    }
};
