<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('quiz-master_follows', function (Blueprint $table) {
            $table->unsignedBigInteger('quiz-master_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->timestamps();
            $table->primary(['quiz-master_id', 'user_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('quiz-master_follows');
    }
};
