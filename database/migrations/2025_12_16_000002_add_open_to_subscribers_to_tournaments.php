<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('tournaments', function (Blueprint $table) {
            if (!Schema::hasColumn('tournaments', 'open_to_subscribers')) {
                $table->boolean('open_to_subscribers')->default(false)->after('entry_fee');
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('tournaments')) return;
        Schema::table('tournaments', function (Blueprint $table) {
            if (Schema::hasColumn('tournaments', 'open_to_subscribers')) {
                try { $table->dropColumn('open_to_subscribers'); } catch (\Throwable $e) {}
            }
        });
    }
};
