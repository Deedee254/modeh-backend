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
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('users', function ($table) {
                $table->string('password')->nullable()->change();
            });
        } else {
            // Use a raw statement to avoid requiring doctrine/dbal for change()
            // This will work on MySQL. If you use another DB, adjust accordingly.
            DB::statement("ALTER TABLE `users` MODIFY `password` VARCHAR(255) NULL;");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('users', function ($table) {
                $table->string('password')->nullable(false)->change();
            });
        } else {
            // Make password NOT NULL again. Down migration may fail if NULL values exist.
            DB::statement("ALTER TABLE `users` MODIFY `password` VARCHAR(255) NOT NULL;");
        }
    }
};
