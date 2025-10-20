<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('echo_server_presence_channels', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('user_id');
            $table->string('socket_id');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('echo_server_presence_channels');
    }
};