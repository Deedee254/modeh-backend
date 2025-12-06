<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('tournaments', function (Blueprint $table) {
            // Number of days for qualifier phase (if set, end_date may be computed)
            $table->integer('qualifier_days')->nullable()->after('timeline');

            // Number of days delay between rounds / battles
            $table->integer('round_delay_days')->nullable()->after('qualifier_days');
        });
    }

    public function down()
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn(['qualifier_days', 'round_delay_days']);
        });
    }
};
