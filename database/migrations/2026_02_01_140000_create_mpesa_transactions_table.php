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
        Schema::create('mpesa_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Daraja identifiers
            $table->string('checkout_request_id')->unique()->index();
            $table->string('merchant_request_id')->nullable()->index();
            
            // Transaction details
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('phone')->nullable(); // Customer phone number
            $table->string('mpesa_receipt')->nullable()->unique(); // M-PESA receipt number (idempotency key)
            
            // Status tracking
            $table->enum('status', ['pending', 'success', 'failed', 'cancelled'])->default('pending')->index();
            $table->integer('result_code')->nullable(); // Daraja ResultCode
            $table->text('result_desc')->nullable(); // Daraja ResultDesc / error message
            
            // Reconciliation timestamps
            $table->dateTime('transaction_date')->nullable(); // M-PESA transaction timestamp
            $table->dateTime('reconciled_at')->nullable(); // When we last checked/marked final
            
            // Raw response storage for audit/debugging
            $table->json('raw_response')->nullable();
            
            // Retry tracking
            $table->integer('retry_count')->default(0);
            $table->dateTime('last_retry_at')->nullable();
            $table->dateTime('next_retry_at')->nullable();
            
            // Reference to subscription or order
            $table->morphs('billable', 'mpesa_transactions_billable'); // billable_type + billable_id
            
            $table->timestamps();
            
            // Indexes for queries
            $table->index(['user_id', 'status']);
            $table->index(['status', 'next_retry_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mpesa_transactions');
    }
};
