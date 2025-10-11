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
            $table->timestamp('approval_requested_at')->nullable();

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('questions');
    }
};
