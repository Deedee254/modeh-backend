<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('tutor_follows', function (Blueprint $table) {
            $table->unsignedBigInteger('tutor_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->timestamps();
            $table->primary(['tutor_id', 'user_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('tutor_follows');
    }
};
