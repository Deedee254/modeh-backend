<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'payment_status')) {
                $table->enum('payment_status', [
                    'paid',
                    'pending_payment',
                    'payment_overdue',
                    'refunded',
                    'disputed'
                ])->default('paid')->after('status');
            }

            if (!Schema::hasColumn('transactions', 'pending_payment_id')) {
                $table->foreignId('pending_payment_id')
                    ->nullable()
                    ->constrained('pending_quiz_payments')
                    ->setOnDelete('set null')
                    ->after('payment_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'pending_payment_id')) {
                $table->dropForeign(['pending_payment_id']);
                $table->dropColumn('pending_payment_id');
            }
            if (Schema::hasColumn('transactions', 'payment_status')) {
                $table->dropColumn('payment_status');
            }
        });
    }
};
