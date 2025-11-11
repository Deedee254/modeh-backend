<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // For MySQL, we need to modify the ENUM to include 'waiting'
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE battles MODIFY status ENUM('pending', 'active', 'completed', 'cancelled', 'waiting') DEFAULT 'pending'");
        } else {
            // For SQLite and other databases, ENUM is handled differently
            // This migration is primarily for MySQL
            Schema::table('battles', function (Blueprint $table) {
                // SQLite doesn't support ENUM, so no action needed
            });
        }
    }

    public function down()
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE battles MODIFY status ENUM('pending', 'active', 'completed', 'cancelled') DEFAULT 'pending'");
        }
    }
};
