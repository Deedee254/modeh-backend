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
        Schema::create('one_off_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('item_type'); // 'quiz' or 'battle'
            $table->unsignedBigInteger('item_id');
            $table->decimal('amount', 10, 2);
            $table->string('status')->default('pending'); // pending, confirmed, cancelled
            $table->string('gateway')->nullable();
            $table->json('gateway_meta')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('one_off_purchases');
    }
};
