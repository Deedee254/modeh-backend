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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique(); // INV-2026-0001
            $table->unsignedBigInteger('user_id');
            $table->string('invoiceable_type'); // Subscription, OneOffPurchase, Transaction
            $table->unsignedBigInteger('invoiceable_id');
            $table->decimal('amount', 10, 2);
            $table->string('currency')->default('KES');
            $table->string('description'); // e.g., "Premium Subscription - 30 days"
            $table->enum('status', ['draft', 'pending', 'paid', 'cancelled'])->default('draft');
            $table->string('payment_method')->nullable(); // mpesa, card, etc.
            $table->string('transaction_id')->nullable(); // M-PESA tx ID
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->json('meta')->nullable(); // subscription end date, item details, etc.
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'status']);
            $table->index(['invoiceable_type', 'invoiceable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
