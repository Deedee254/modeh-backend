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
        if (Schema::hasTable('transactions') && Schema::hasColumn('transactions', 'quiz-master_id')) {
            Schema::table('transactions', function (Blueprint $table) {
                // To safely rename a column that acts as a foreign key without breaking constraints in some DBs,
                // Laravel handles standard rename if you have doctrine/dbal or native support (Laravel 9+).
                $table->renameColumn('quiz-master_id', 'quiz_master_id');
            });
        }

        if (Schema::hasTable('withdrawal_requests') && Schema::hasColumn('withdrawal_requests', 'quiz-master_id')) {
            Schema::table('withdrawal_requests', function (Blueprint $table) {
                $table->renameColumn('quiz-master_id', 'quiz_master_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('transactions') && Schema::hasColumn('transactions', 'quiz_master_id')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->renameColumn('quiz_master_id', 'quiz-master_id');
            });
        }

        if (Schema::hasTable('withdrawal_requests') && Schema::hasColumn('withdrawal_requests', 'quiz_master_id')) {
            Schema::table('withdrawal_requests', function (Blueprint $table) {
                $table->renameColumn('quiz_master_id', 'quiz-master_id');
            });
        }
    }
};
