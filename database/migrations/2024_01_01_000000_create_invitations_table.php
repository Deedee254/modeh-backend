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
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inviter_id')->nullable()->comment('User who sent the invitation');
            $table->foreign('inviter_id')->references('id')->on('users')->onDelete('cascade');
            
            $table->string('token', 64)->unique()->comment('Unique invitation token');
            $table->string('email', 255)->index()->comment('Email address being invited');
            $table->enum('status', ['pending', 'accepted', 'expired', 'revoked'])->default('pending')->index();
            
            $table->timestamp('accepted_at')->nullable()->comment('When invitation was accepted');
            $table->unsignedBigInteger('accepted_by_user_id')->nullable()->comment('User ID that accepted invitation');
            $table->foreign('accepted_by_user_id')->references('id')->on('users')->onDelete('set null');
            
            $table->timestamp('expires_at')->useCurrent()->comment('When invitation expires');
            
            $table->json('metadata')->nullable()->comment('Additional metadata (perks, rewards, etc)');
            
            $table->timestamps();
            
            // Indexes for common queries
            $table->index(['token', 'status']);
            $table->index(['email', 'status']);
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
