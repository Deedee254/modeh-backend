<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Use a raw statement to avoid requiring doctrine/dbal for change()
        // This will work on MySQL. If you use another DB, adjust accordingly.
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('users', function ($table) {
                $table->string('password')->nullable()->change();
            });
        } else {
            DB::statement('ALTER TABLE `users` MODIFY `password` VARCHAR(255) NULL;');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Make password NOT NULL again. Down migration may fail if NULL values exist.
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('users', function ($table) {
                $table->string('password')->nullable(false)->change();
            });
        } else {
            DB::statement('ALTER TABLE `users` MODIFY `password` VARCHAR(255) NOT NULL;');
        }
    }
};
