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
        Schema::table('tournament_battles', function (Blueprint $table) {
            // Ensure canonical ordering is used in code (player1_id <= player2_id) before enabling
            // the unique constraint. This index prevents duplicate pair rows for the same tournament and round.
            $table->unique(['tournament_id', 'round', 'player1_id', 'player2_id'], 'tournament_round_pair_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tournament_battles', function (Blueprint $table) {
            $table->dropUnique('tournament_round_pair_unique');
        });
    }
};
