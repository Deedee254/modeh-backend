<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Add missing columns to transactions table
            if (!Schema::hasColumn('transactions', 'affiliate_share')) {
                $table->decimal('affiliate_share', 12, 2)->default(0)->after('quiz-master_share');
            }
            if (!Schema::hasColumn('transactions', 'type')) {
                $table->string('type')->default('payment')->after('gateway');
            }
            if (!Schema::hasColumn('transactions', 'status')) {
                $table->string('status')->default('completed')->after('type');
            }
            if (!Schema::hasColumn('transactions', 'description')) {
                $table->string('description')->nullable()->after('status');
            }
            if (!Schema::hasColumn('transactions', 'reference_id')) {
                $table->string('reference_id')->nullable()->after('description');
            }
            if (!Schema::hasColumn('transactions', 'balance_after')) {
                $table->decimal('balance_after', 12, 2)->nullable()->after('reference_id');
            }
        });
    }

    public function down()
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'affiliate_share')) {
                $table->dropColumn('affiliate_share');
            }
            if (Schema::hasColumn('transactions', 'type')) {
                $table->dropColumn('type');
            }
            if (Schema::hasColumn('transactions', 'status')) {
                $table->dropColumn('status');
            }
            if (Schema::hasColumn('transactions', 'description')) {
                $table->dropColumn('description');
            }
            if (Schema::hasColumn('transactions', 'reference_id')) {
                $table->dropColumn('reference_id');
            }
            if (Schema::hasColumn('transactions', 'balance_after')) {
                $table->dropColumn('balance_after');
            }
        });
    }
};
