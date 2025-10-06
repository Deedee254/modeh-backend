<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        Schema::create('chat_metrics_settings', function (Blueprint $table) {
            $table->id();
            $table->integer('retention_days')->default(30)->unsigned();
            $table->timestamps();
        });

        // insert default row
        DB::table('chat_metrics_settings')->insert([
            'retention_days' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down()
    {
        Schema::dropIfExists('chat_metrics_settings');
    }
};
