<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('quizees', function (Blueprint $table) {
            $table->string('institution')->nullable();
            $table->json('subjects')->nullable(); // Store array of subject IDs
        });
    }

    public function down()
    {
        Schema::table('quizees', function (Blueprint $table) {
            $table->dropColumn(['institution', 'subjects']);
        });
    }
};