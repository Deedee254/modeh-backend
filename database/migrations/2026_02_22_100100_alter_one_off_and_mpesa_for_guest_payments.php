<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('one_off_purchases')) {
            if (!Schema::hasColumn('one_off_purchases', 'guest_identifier')) {
                Schema::table('one_off_purchases', function (Blueprint $table) {
                    $table->string('guest_identifier')->nullable()->after('user_id')->index();
                });
            }

            // Make user_id nullable for guest purchases.
            DB::statement('ALTER TABLE one_off_purchases MODIFY user_id BIGINT UNSIGNED NULL');
        }

        if (Schema::hasTable('mpesa_transactions')) {
            // Allow M-PESA transaction rows for guest purchases.
            DB::statement('ALTER TABLE mpesa_transactions MODIFY user_id BIGINT UNSIGNED NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('one_off_purchases')) {
            if (Schema::hasColumn('one_off_purchases', 'guest_identifier')) {
                Schema::table('one_off_purchases', function (Blueprint $table) {
                    $table->dropColumn('guest_identifier');
                });
            }

            DB::statement('ALTER TABLE one_off_purchases MODIFY user_id BIGINT UNSIGNED NOT NULL');
        }

        if (Schema::hasTable('mpesa_transactions')) {
            DB::statement('ALTER TABLE mpesa_transactions MODIFY user_id BIGINT UNSIGNED NOT NULL');
        }
    }
};

