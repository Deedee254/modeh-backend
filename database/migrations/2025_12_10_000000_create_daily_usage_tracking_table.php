<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_usage_tracking', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->date('tracking_date');
            $table->string('usage_type')->default('reveals'); // reveals, attempts, etc.
            $table->integer('used')->default(0); // how many used today
            $table->timestamps();
            
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->onDelete('set null');
            $table->unique(['user_id', 'tracking_date', 'usage_type']);
            $table->index(['user_id', 'tracking_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_usage_tracking');
    }
};
