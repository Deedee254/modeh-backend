<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Update wallets table
        Schema::table('wallets', function (Blueprint $table) {
            // Only drop if column exists
            if (Schema::hasColumn('wallets', 'pending')) {
                $table->dropColumn('pending');
            }
        });

        Schema::table('wallets', function (Blueprint $table) {
            // Add new balance states if they don't exist
            if (!Schema::hasColumn('wallets', 'withdrawn_pending')) {
                $table->decimal('withdrawn_pending', 15, 2)->default(0)->after('available')->comment('Awaiting admin approval');
            }
            if (!Schema::hasColumn('wallets', 'settled')) {
                $table->decimal('settled', 15, 2)->default(0)->after('withdrawn_pending')->comment('Admin confirmed payout');
            }
            if (!Schema::hasColumn('wallets', 'earned_this_month')) {
                $table->decimal('earned_this_month', 15, 2)->default(0)->after('lifetime_earned')->comment('Monthly earnings');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            if (!Schema::hasColumn('wallets', 'pending')) {
                $table->decimal('pending', 15, 2)->default(0)->after('available');
            }
        });

        Schema::table('wallets', function (Blueprint $table) {
            if (Schema::hasColumn('wallets', 'withdrawn_pending')) {
                $table->dropColumn('withdrawn_pending');
            }
            if (Schema::hasColumn('wallets', 'settled')) {
                $table->dropColumn('settled');
            }
            if (Schema::hasColumn('wallets', 'earned_this_month')) {
                $table->dropColumn('earned_this_month');
            }
        });
    }
};
