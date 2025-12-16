<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds `access_type` to tournaments to control who may join:
     * - public: open to everyone (default)
     * - grade: only users matching tournament.grade_id
     * - level: only users matching tournament.level_id
     *
     * @return void
     */
    public function up()
    {
        Schema::table('tournaments', function (Blueprint $table) {
            // Add access_type at the end of the table so this migration
            // does not depend on the presence of a specific preceding column.
            $table->string('access_type')->default('public');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('access_type');
        });
    }
};
