<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('messages')) {
            return;
        }

        if (!Schema::hasColumn('messages', 'attachments')) {
            Schema::table('messages', function (Blueprint $table) {
                // JSON column to store simple attachment metadata
                $table->json('attachments')->nullable()->after('content');
            });
        }
    }

    public function down()
    {
        if (!Schema::hasTable('messages')) {
            return;
        }

        if (Schema::hasColumn('messages', 'attachments')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->dropColumn('attachments');
            });
        }
    }
};
