<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (\DB::getDriverName() === 'sqlite') {
            return;
        }
        if (Schema::hasTable('users')) {
            \DB::statement("ALTER TABLE `users` MODIFY `role` ENUM('quizee','quiz-master','admin','institution-manager','parent','advertiser') NOT NULL DEFAULT 'quizee'");
        }
    }

    public function down(): void
    {
        if (\DB::getDriverName() === 'sqlite') {
            return;
        }
        if (Schema::hasTable('users')) {
            \DB::statement("ALTER TABLE `users` MODIFY `role` ENUM('quizee','quiz-master','admin','institution-manager','parent') NOT NULL DEFAULT 'quizee'");
        }
    }
};