<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('questions', function (Blueprint $table) {
            // Add a new field for YouTube URLs
            $table->string('youtube_url')->nullable();
            // Add metadata for media-based questions
            $table->json('media_metadata')->nullable()->comment('Store additional media information like duration, dimensions, etc.');
        });

        // Only run the column comment update for MySQL
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE questions MODIFY COLUMN type VARCHAR(255) COMMENT 'mcq, multi, short, numeric, fill_blank, image_mcq, audio_mcq, video_mcq'");
        }
    }

    public function down()
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('youtube_url');
            $table->dropColumn('media_metadata');
        });
    }
};