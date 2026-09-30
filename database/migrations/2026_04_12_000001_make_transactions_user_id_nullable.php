<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Guest one-off purchases distribute revenue but have no payer user row; payer_id must be nullable.
     */
    public function up(): void
    {
        if (!Schema::hasTable('transactions')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'mysql') {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE transactions MODIFY user_id BIGINT UNSIGNED NULL');
        } else {
            Schema::table('transactions', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->change();
            });
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('transactions')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'mysql') {
            return;
        }

        if (DB::table('transactions')->whereNull('user_id')->exists()) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE transactions MODIFY user_id BIGINT UNSIGNED NOT NULL');
        } else {
            Schema::table('transactions', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable(false)->change();
            });
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
