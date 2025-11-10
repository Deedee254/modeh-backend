<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('tournaments')) {
            return;
        }

        Schema::table('tournaments', function (Blueprint $table) {
            if (! Schema::hasColumn('tournaments', 'level_id')) {
                $table->foreignId('level_id')->nullable()->constrained('levels')->nullOnDelete()->after('grade_id');
            }
        });
    }

    public function down()
    {
        if (! Schema::hasTable('tournaments')) {
            return;
        }

        Schema::table('tournaments', function (Blueprint $table) {
            if (Schema::hasColumn('tournaments', 'level_id')) {
                $table->dropConstrainedForeignId('level_id');
            }
        });
    }
};
