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
        Schema::create('institution_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('institution_name');
            $table->string('institution_location')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->enum('profile_type', ['quizee', 'quiz-master']);
            $table->unsignedBigInteger('profile_id');  // quizee_id or quiz_master_id
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('reviewed_by')->references('id')->on('users')->onDelete('set null');
            $table->index('status');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('institution_approval_requests');
    }
};
