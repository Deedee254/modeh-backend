<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('battles', function (Blueprint $table) {
            if (!Schema::hasColumn('battles', 'settings')) {
                // place after opponent_id which exists on the original table
                $table->json('settings')->nullable()->after('opponent_id');
            }
        });
    }

    public function down()
    {
        Schema::table('battles', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
