<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('guest_quiz_attempts')) {
            return;
        }

        Schema::create('guest_quiz_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('quiz_id')->constrained()->onDelete('cascade');
            $table->string('guest_identifier')->index();
            $table->unsignedTinyInteger('score')->default(0);
            $table->unsignedTinyInteger('percentage')->default(0);
            $table->unsignedInteger('correct_count')->default(0);
            $table->unsignedInteger('incorrect_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('time_taken')->default(0);
            $table->json('results')->nullable();
            $table->boolean('is_locked')->default(false);
            $table->timestamp('unlocked_at')->nullable();
            $table->foreignId('unlock_purchase_id')->nullable()->constrained('one_off_purchases')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_quiz_attempts');
    }
};

