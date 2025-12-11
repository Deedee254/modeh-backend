<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->unsignedBigInteger('subscription_id')->nullable()->after('quiz_id');
            $table->enum('subscription_type', ['personal', 'institution', 'one_off'])->nullable()->after('subscription_id');
            
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->onDelete('set null');
            $table->index(['user_id', 'subscription_id']);
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropForeign(['subscription_id']);
            $table->dropIndex(['user_id', 'subscription_id']);
            $table->dropColumn(['subscription_id', 'subscription_type']);
        });
    }
};
