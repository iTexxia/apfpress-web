<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('owner','editor','catalog_manager','fulfillment','customer') NOT NULL DEFAULT 'customer'");
        }
    }

    public function down(): void
    {
        DB::table('users')->where('role', 'catalog_manager')->update(['role' => 'editor']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('owner','editor','fulfillment','customer') NOT NULL DEFAULT 'customer'");
        }
    }
};
