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
        Schema::table('institution_user', function (Blueprint $table) {
            $table->string('invitation_token')->nullable()->unique();
            $table->timestamp('invitation_expires_at')->nullable();
            $table->enum('invitation_status', ['active', 'invited', 'pending', 'expired'])->default('active');
            $table->string('invited_email')->nullable();
            $table->index('invitation_token');
            $table->index('invitation_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('institution_user', function (Blueprint $table) {
            $table->dropIndex('institution_user_invitation_token_unique');
            $table->dropIndex('institution_user_invitation_token_index');
            $table->dropIndex('institution_user_invitation_status_index');
            $table->dropColumn('invitation_token');
            $table->dropColumn('invitation_expires_at');
            $table->dropColumn('invitation_status');
            $table->dropColumn('invited_email');
        });
    }
};
