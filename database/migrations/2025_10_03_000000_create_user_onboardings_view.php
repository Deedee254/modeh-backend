<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Create a view `user_onboardings` pointing to `user_onboarding` to satisfy plural queries (Filament, etc.)
     */
    public function up(): void
    {
        // Create or replace a view; fallback to creating a table copy if the DB doesn't support views
        try {
            DB::statement(/** @lang sql */ "CREATE OR REPLACE VIEW `user_onboardings` AS SELECT * FROM `user_onboarding`;");
        } catch (\Exception $e) {
            // If view creation fails (e.g., SQLite in-memory for tests), create a table alias if not exists
            try {
                if (DB::getDriverName() === 'sqlite') {
                    // For sqlite, create a table snapshot if it doesn't exist
                    DB::statement("CREATE TABLE IF NOT EXISTS user_onboardings AS SELECT * FROM user_onboarding;");
                }
            } catch (\Exception $inner) {
                // swallow
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            DB::statement('DROP VIEW IF EXISTS `user_onboardings`');
        } catch (\Exception $e) {
            try {
                if (DB::getDriverName() === 'sqlite') {
                    DB::statement('DROP TABLE IF EXISTS user_onboardings');
                }
            } catch (\Exception $inner) {
                // swallow
            }
        }
    }
};
