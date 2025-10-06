<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('tournaments', function (Blueprint $table) {
            if (!Schema::hasColumn('tournaments', 'sponsor_id')) {
                $table->foreignId('sponsor_id')->nullable()->after('created_by')->constrained()->onDelete('set null');
            }
            if (!Schema::hasColumn('tournaments', 'sponsor_banner')) {
                $table->string('sponsor_banner')->nullable()->after('sponsor_id');
            }
            if (!Schema::hasColumn('tournaments', 'sponsor_details')) {
                $table->json('sponsor_details')->nullable()->after('sponsor_banner');
            }
        });
    }

    public function down()
    {
        Schema::table('tournaments', function (Blueprint $table) {
            if (Schema::hasColumn('tournaments', 'sponsor_id')) {
                $table->dropColumn(['sponsor_id', 'sponsor_banner', 'sponsor_details']);
            }
        });
    }
};