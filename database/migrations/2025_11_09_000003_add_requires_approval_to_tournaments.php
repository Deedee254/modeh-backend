<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('tournaments', function (Blueprint $table) {
            if (! Schema::hasColumn('tournaments', 'requires_approval')) {
                $table->boolean('requires_approval')->default(false)->after('requires_premium');
            }
        });
    }

    public function down()
    {
        Schema::table('tournaments', function (Blueprint $table) {
            if (Schema::hasColumn('tournaments', 'requires_approval')) {
                $table->dropColumn('requires_approval');
            }
        });
    }
};
