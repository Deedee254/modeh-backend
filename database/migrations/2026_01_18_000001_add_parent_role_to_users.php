<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            return;
        }

        if (Schema::hasTable('users')) {
            // Add 'parent' to the existing ENUM values for role.
            DB::statement("ALTER TABLE `users` MODIFY `role` ENUM('quizee','quiz-master','admin','institution-manager','parent') NOT NULL DEFAULT 'quizee'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        if (Schema::hasTable('users')) {
            // Remove 'parent' from the ENUM values (revert to previous set).
            DB::statement("ALTER TABLE `users` MODIFY `role` ENUM('quizee','quiz-master','admin','institution-manager') NOT NULL DEFAULT 'quizee'");
        }
    }
};
