<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('tournament_participants', function (Blueprint $table) {
            if (! Schema::hasColumn('tournament_participants', 'status')) {
                $table->string('status')->default('approved')->after('score');
            }
            if (! Schema::hasColumn('tournament_participants', 'requested_at')) {
                $table->timestamp('requested_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('tournament_participants', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('requested_at');
            }
            if (! Schema::hasColumn('tournament_participants', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('approved_at');
            }
        });
    }

    public function down()
    {
        Schema::table('tournament_participants', function (Blueprint $table) {
            if (Schema::hasColumn('tournament_participants', 'approved_by')) {
                $table->dropColumn('approved_by');
            }
            if (Schema::hasColumn('tournament_participants', 'approved_at')) {
                $table->dropColumn('approved_at');
            }
            if (Schema::hasColumn('tournament_participants', 'requested_at')) {
                $table->dropColumn('requested_at');
            }
            if (Schema::hasColumn('tournament_participants', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
