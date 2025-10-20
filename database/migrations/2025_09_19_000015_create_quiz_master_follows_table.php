<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Create the follow pivot table for quiz masters
        Schema::create('quiz_master_follows', function (Blueprint $table) {
            $table->unsignedBigInteger('quiz_master_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->timestamps();
            $table->primary(['quiz_master_id', 'user_id']);

            // Foreign key constraints (added when possible)
            // Use raw foreign keys to avoid errors if related tables aren't present yet during initial migrations
            $table->foreign('quiz_master_id')->references('id')->on('quiz_masters')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // Ensure quiz_masters has a cached followers_count column
        if (Schema::hasTable('quiz_masters') && ! Schema::hasColumn('quiz_masters', 'followers_count')) {
            Schema::table('quiz_masters', function (Blueprint $table) {
                $table->unsignedInteger('followers_count')->default(0);
            });
        }
    }

    public function down()
    {
        // Drop followers_count if it exists
        if (Schema::hasTable('quiz_masters') && Schema::hasColumn('quiz_masters', 'followers_count')) {
            Schema::table('quiz_masters', function (Blueprint $table) {
                $table->dropColumn('followers_count');
            });
        }

        Schema::dropIfExists('quiz_master_follows');
    }
};
