<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('tournaments', function (Blueprint $table) {
            // Add is_featured column if it doesn't exist
            if (!Schema::hasColumn('tournaments', 'is_featured')) {
                $table->boolean('is_featured')->default(false)->after('status');
            }

            // Add timeline column if it doesn't exist
            if (!Schema::hasColumn('tournaments', 'timeline')) {
                $table->json('timeline')->nullable()->after('rules')->comment('Tournament timeline phases');
            }
        });
    }

    public function down()
    {
        Schema::table('tournaments', function (Blueprint $table) {
            if (Schema::hasColumn('tournaments', 'is_featured')) {
                $table->dropColumn('is_featured');
            }
            if (Schema::hasColumn('tournaments', 'timeline')) {
                $table->dropColumn('timeline');
            }
        });
    }
};
