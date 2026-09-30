<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `users` MODIFY `password` VARCHAR(255) NULL;");
        } else {
            Schema::table('users', function ($table) {
                $table->string('password')->nullable()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `users` MODIFY `password` VARCHAR(255) NOT NULL;");
        } else {
            Schema::table('users', function ($table) {
                $table->string('password')->nullable(false)->change();
            });
        }
    }
};
