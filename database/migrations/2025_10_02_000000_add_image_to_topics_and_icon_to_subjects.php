<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('topics', function (Blueprint $table) {
            if (!Schema::hasColumn('topics', 'image')) {
                $table->string('image')->nullable()->after('description');
            }
        });

        Schema::table('subjects', function (Blueprint $table) {
            if (!Schema::hasColumn('subjects', 'icon')) {
                $table->string('icon')->nullable()->after('description');
            }
        });
    }

    public function down()
    {
        Schema::table('topics', function (Blueprint $table) {
            if (Schema::hasColumn('topics', 'image')) {
                $table->dropColumn('image');
            }
        });

        Schema::table('subjects', function (Blueprint $table) {
            if (Schema::hasColumn('subjects', 'icon')) {
                $table->dropColumn('icon');
            }
        });
    }
};
