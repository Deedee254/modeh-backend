<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_quiz_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_master_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('quizee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('quiz_id')->constrained('quizzes')->cascadeOnDelete();
            $table->foreignId('quiz_attempt_id')->constrained('quiz_attempts')->cascadeOnDelete();

            $table->decimal('amount', 15, 2);
            $table->enum('status', ['pending', 'paid', 'overdue', 'cancelled'])->default('pending');
            $table->enum('reminder_status', ['not_sent', 'sent_1', 'sent_2', 'sent_3'])->default('not_sent');

            $table->timestamp('attempt_at')->useCurrent();
            $table->timestamp('payment_due_at')->useCurrent();
            $table->timestamp('first_reminder_at')->nullable();
            $table->timestamp('last_reminder_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->string('recovery_status')->default('active'); // active, abandoned, recovered

            $table->timestamps();

            // Indexes for common queries
            $table->index(['quiz_master_id', 'status']);
            $table->index(['quizee_id', 'status']);
            $table->index(['status', 'last_reminder_at']);
            $table->index(['payment_due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_quiz_payments');
    }
};
