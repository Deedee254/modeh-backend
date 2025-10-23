<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('grades', function (Blueprint $table) {
            if (!Schema::hasColumn('grades', 'display_name')) {
                $table->string('display_name')->nullable()->after('name');
            }
            if (!Schema::hasColumn('grades', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('display_name');
            }
        });
    }

    public function down()
    {
        Schema::table('grades', function (Blueprint $table) {
            if (Schema::hasColumn('grades', 'display_name')) {
                $table->dropColumn('display_name');
            }
            if (Schema::hasColumn('grades', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }
};
