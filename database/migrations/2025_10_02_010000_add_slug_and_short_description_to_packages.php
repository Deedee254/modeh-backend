<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('packages', function (Blueprint $table) {
            if (!Schema::hasColumn('packages', 'slug')) {
                $table->string('slug')->nullable()->after('title')->unique();
            }
            if (!Schema::hasColumn('packages', 'short_description')) {
                $table->text('short_description')->nullable()->after('description');
            }
        });
    }

    public function down()
    {
        // SQLite has limited support for dropping columns; avoid dropColumn during tests against sqlite.
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            return;
        }

        Schema::table('packages', function (Blueprint $table) {
            if (Schema::hasColumn('packages', 'slug')) {
                $table->dropColumn('slug');
            }
            if (Schema::hasColumn('packages', 'short_description')) {
                $table->dropColumn('short_description');
            }
        });
    }
};
