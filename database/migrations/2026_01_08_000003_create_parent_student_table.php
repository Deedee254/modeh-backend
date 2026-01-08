<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_student', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('parent_id');
            $table->unsignedBigInteger('quizee_id');
            $table->unsignedBigInteger('student_invitation_id')->nullable();
            $table->json('package_assignment')->nullable();
            $table->timestamp('connected_at');
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('parents')->onDelete('cascade');
            $table->foreign('quizee_id')->references('id')->on('quizees')->onDelete('cascade');
            $table->foreign('student_invitation_id')->references('id')->on('student_invitations')->onDelete('set null');
            $table->unique(['parent_id', 'quizee_id']);
            $table->index('parent_id');
            $table->index('quizee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_student');
    }
};
