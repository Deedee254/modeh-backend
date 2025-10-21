<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('auto_approve_topics')->default(true);
            $table->boolean('auto_approve_quizzes')->default(true);
            $table->boolean('auto_approve_questions')->default(true);
            $table->timestamps();
        });

        // Insert default single row
        \Illuminate\Support\Facades\DB::table('site_settings')->insert([
            'auto_approve_topics' => true,
            'auto_approve_quizzes' => true,
            'auto_approve_questions' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down()
    {
        Schema::dropIfExists('site_settings');
    }
};
