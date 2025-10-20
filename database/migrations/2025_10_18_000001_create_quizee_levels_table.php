<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('quizee_levels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('min_points')->unsigned();
            $table->integer('max_points')->unsigned()->nullable();
            $table->string('icon')->nullable(); // For displaying level icon
            $table->text('description')->nullable();
            $table->string('color_scheme')->nullable(); // For UI styling
            $table->integer('order')->unsigned(); // For sorting levels
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('quizee_levels');
    }
};