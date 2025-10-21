<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->nullable()->constrained()->onDelete('cascade'); // nullable to allow banked questions
            $table->unsignedBigInteger('created_by')->nullable(); // quiz-master id
            $table->string('type')->default('mcq'); // mcq, fill, image, audio, code, essay
            $table->text('body');
            $table->json('options')->nullable(); // for mcq
            $table->json('answers')->nullable();
            $table->string('media_path')->nullable();
            $table->integer('difficulty')->default(3); // 1-5
            $table->boolean('is_quiz-master_marked')->default(false);
            $table->boolean('is_approved')->default(false);
            // consolidated columns from later migrations
            $table->boolean('is_banked')->default(false);
            $table->string('media_type')->nullable();
            // additional metadata added here so base migration is complete

            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('topic_id')->nullable();
            $table->unsignedBigInteger('grade_id')->nullable();
            $table->string('youtube_url')->nullable();
            $table->json('media_metadata')->nullable()->comment('Store additional media information like duration, dimensions, etc.');
            $table->text('explanation')->nullable();
            $table->timestamp('approval_requested_at')->nullable();

            // indexes & foreign keys
            $table->index(['subject_id','topic_id','grade_id']);
            $table->foreign('subject_id')->references('id')->on('subjects')->onDelete('set null');
            $table->foreign('topic_id')->references('id')->on('topics')->onDelete('set null');
            $table->foreign('grade_id')->references('id')->on('grades')->onDelete('set null');

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('questions');
    }
};
