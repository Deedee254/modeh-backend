<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('institution_user', function (Blueprint $table) {
            $table->timestamp('last_activity_at')->nullable();
            $table->index(['institution_id', 'last_activity_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('institution_user', function (Blueprint $table) {
            $table->dropIndex('institution_user_institution_id_last_activity_at_index');
            $table->dropColumn('last_activity_at');
        });
    }
};
