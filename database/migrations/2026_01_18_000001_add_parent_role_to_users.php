<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            if (\DB::getDriverName() === 'sqlite') {
                Schema::table('users', function ($table) {
                    $table->string('role')->default('quizee')->change();
                });
            } else {
                // Add 'parent' to the existing ENUM values for role.
                \DB::statement("ALTER TABLE `users` MODIFY `role` ENUM('quizee','quiz-master','admin','institution-manager','parent') NOT NULL DEFAULT 'quizee'");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('users')) {
            if (\DB::getDriverName() === 'sqlite') {
                Schema::table('users', function ($table) {
                    $table->string('role')->default('quizee')->change();
                });
            } else {
                // Remove 'parent' from the ENUM values (revert to previous set).
                \DB::statement("ALTER TABLE `users` MODIFY `role` ENUM('quizee','quiz-master','admin','institution-manager') NOT NULL DEFAULT 'quizee'");
            }
        }
    }
};
