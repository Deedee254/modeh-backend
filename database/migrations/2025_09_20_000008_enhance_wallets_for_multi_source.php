<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Add new columns to wallets table for comprehensive tracking
        Schema::table('wallets', function (Blueprint $table) {
            // Wallet type: 'platform', 'admin', 'quiz-master', 'quizee'
            $table->string('type')->default('user')->after('user_id');
            
            // Earnings breakdown by source
            $table->decimal('earned_from_quizzes', 12, 2)->default(0)->after('lifetime_earned');
            $table->decimal('earned_from_affiliates', 12, 2)->default(0)->after('earned_from_quizzes');
            $table->decimal('earned_from_tournaments', 12, 2)->default(0)->after('earned_from_affiliates');
            $table->decimal('earned_from_battles', 12, 2)->default(0)->after('earned_from_tournaments');
            $table->decimal('earned_from_subscriptions', 12, 2)->default(0)->after('earned_from_battles');
            
            // Deductions
            $table->decimal('withdrawn', 12, 2)->default(0)->after('earned_from_subscriptions');
            $table->decimal('refunded', 12, 2)->default(0)->after('withdrawn');
            
            // Status tracking
            $table->string('status')->default('active')->after('refunded');
            $table->index('type');
            $table->index('status');
        });
    }

    public function down()
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropIndex(['status']);
            $table->dropColumn([
                'type',
                'earned_from_quizzes',
                'earned_from_affiliates',
                'earned_from_tournaments',
                'earned_from_battles',
                'earned_from_subscriptions',
                'withdrawn',
                'refunded',
                'status',
            ]);
        });
    }
};
