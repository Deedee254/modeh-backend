<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('tx_id')->nullable(); // external tx id
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // payer (quizee)
            $table->foreignId('quiz-master_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('quiz_id')->nullable()->constrained()->onDelete('set null');
            $table->decimal('amount', 12, 2);
            $table->decimal('quiz-master_share', 12, 2)->default(0);
            $table->decimal('platform_share', 12, 2)->default(0);
            $table->string('gateway')->nullable();
            $table->json('meta')->nullable();
            $table->string('status')->default('pending'); // pending, confirmed, refunded
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('transactions');
    }
};
