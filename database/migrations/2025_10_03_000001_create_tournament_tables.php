<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('tournaments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description');
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->decimal('prize_pool', 10, 2)->nullable();
            $table->integer('max_participants')->nullable();
            $table->decimal('entry_fee', 10, 2)->nullable();
            $table->string('status');
            $table->json('rules')->nullable();
            $table->foreignId('subject_id')->constrained('subjects')->onDelete('cascade');
            $table->foreignId('topic_id')->nullable()->constrained('topics')->onDelete('set null');
            $table->foreignId('grade_id')->constrained('grades')->onDelete('cascade');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });

        Schema::create('tournament_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->decimal('score', 10, 2)->nullable();
            $table->integer('rank')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // use explicit short index name to avoid exceeding MySQL identifier length
            $table->unique(['tournament_id', 'user_id'], 't_participants_tournament_user_uq');
        });

        Schema::create('tournament_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->onDelete('cascade');
            $table->foreignId('question_id')->constrained()->onDelete('cascade');
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->unique(['tournament_id', 'question_id'], 't_questions_tournament_question_uq');
        });

    Schema::create('tournament_battles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->onDelete('cascade');
            $table->integer('round');
            $table->foreignId('player1_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('player2_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('winner_id')->nullable()->constrained('users')->onDelete('set null');
            $table->decimal('player1_score', 10, 2)->nullable();
            $table->decimal('player2_score', 10, 2)->nullable();
            $table->string('status');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        // Tear down tournament related tables in reverse order. The up() method
        // does not create tournament_battle_questions, so ensure safe drops.
        Schema::dropIfExists('tournament_battle_questions');
        Schema::dropIfExists('tournament_battles');
        Schema::dropIfExists('tournament_questions');
        Schema::dropIfExists('tournament_participants');
        Schema::dropIfExists('tournaments');
    }
};