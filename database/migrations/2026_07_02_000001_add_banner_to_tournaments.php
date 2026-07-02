<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('tournaments')) {
            return;
        }

        Schema::table('tournaments', function (Blueprint $table) {
            if (!Schema::hasColumn('tournaments', 'banner')) {
                $table->string('banner')->nullable()->after('description');
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('tournaments')) {
            return;
        }

        Schema::table('tournaments', function (Blueprint $table) {
            if (Schema::hasColumn('tournaments', 'banner')) {
                $table->dropColumn('banner');
            }
        });
    }
};
