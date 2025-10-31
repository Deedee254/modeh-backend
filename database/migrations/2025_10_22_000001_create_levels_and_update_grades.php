<?php

use Illuminate\Database\Migrations\Migration;

// This migration was superseded by an earlier-dated migration (moved to 2025_09_18_000004_...)
// Keep a no-op migration here so running migrations in environments that already have this file
// will not attempt to recreate the `levels` table.
return new class extends Migration
{
    public function up()
    {
        // no-op: levels creation moved to an earlier migration file
    }

    public function down()
    {
        // no-op
    }
};
