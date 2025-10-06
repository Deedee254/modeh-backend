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
        Schema::create('user_onboarding', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->boolean('profile_completed')->default(false);
            $table->boolean('institution_added')->default(false);
            $table->boolean('role_selected')->default(false);
            $table->boolean('subject_selected')->default(false); // For tutors
            $table->boolean('grade_selected')->default(false); // For students
            $table->json('completed_steps')->nullable();
            $table->timestamp('last_step_completed_at')->nullable();
            $table->timestamps();
            
            $table->index(['user_id', 'profile_completed']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_onboarding');
    }
};
