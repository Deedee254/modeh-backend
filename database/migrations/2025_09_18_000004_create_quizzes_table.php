<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('created_by')->nullable(); // quiz-master id
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('youtube_url')->nullable();
            $table->string('cover_image')->nullable();
            $table->boolean('is_paid')->default(false);
            $table->integer('timer_seconds')->nullable();
            $table->float('difficulty')->default(0); // average difficulty
            $table->boolean('is_approved')->default(false);
            // consolidated columns from later migrations
            $table->timestamp('approval_requested_at')->nullable();
            $table->boolean('is_draft')->default(false);
            $table->integer('attempts_allowed')->nullable(); // null = unlimited
            $table->boolean('shuffle_questions')->default(false);
            $table->boolean('shuffle_answers')->default(false);
            $table->string('visibility')->default('published'); // draft|published|scheduled
            $table->timestamp('scheduled_at')->nullable();

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('quizzes');
    }
};
