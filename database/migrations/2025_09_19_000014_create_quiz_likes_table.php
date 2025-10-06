<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('quiz_likes', function (Blueprint $table) {
            $table->unsignedBigInteger('quiz_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->timestamps();
            $table->primary(['quiz_id', 'user_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('quiz_likes');
    }
};
