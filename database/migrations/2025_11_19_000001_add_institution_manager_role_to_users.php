<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // MySQL ENUMs must be redefined via MODIFY. Keep existing values and add 'institution-manager'.
        if (Schema::hasTable('users')) {
            \DB::statement("ALTER TABLE `users` MODIFY `role` ENUM('quizee','quiz-master','admin','institution-manager') NOT NULL DEFAULT 'quizee'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('users')) {
            \DB::statement("ALTER TABLE `users` MODIFY `role` ENUM('quizee','quiz-master','admin') NOT NULL DEFAULT 'quizee'");
        }
    }
};
