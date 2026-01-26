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
        Schema::table('battles', function (Blueprint $table) {
            $table->unsignedBigInteger('subscription_id')->nullable()->after('completed_at');
            $table->enum('subscription_type', ['personal', 'institution', 'one_off'])->nullable()->after('subscription_id');
            
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->onDelete('set null');
            $table->index(['initiator_id', 'subscription_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('battles', function (Blueprint $table) {
            $table->dropForeign(['subscription_id']);
            $table->dropIndex(['initiator_id', 'subscription_id']);
            $table->dropColumn(['subscription_id', 'subscription_type']);
        });
    }
};
