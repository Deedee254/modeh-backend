<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Services\SlugService;
use App\Models\Grade;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure the slug column exists and is nullable so we can backfill safely
        Schema::table('grades', function (Blueprint $table) {
            // Add the column if it doesn't exist. On some DB drivers change() or addColumn checks
            // may fail if column already present; this migration is intended to run once.
            if (!Schema::hasColumn('grades', 'slug')) {
                $table->string('slug', 191)->nullable();
            }
        });

        // Backfill grades with slugs using DB queries to avoid touching model scopes/events
        $baseQuery = DB::table('grades')->select('id', 'name');

        $baseQuery->orderBy('id')->chunk(100, function ($grades) {
            foreach ($grades as $grade) {
                // Create a simple slug from the grade name
                $baseSlug = \Illuminate\Support\Str::slug($grade->name);
                $baseSlug = substr($baseSlug, 0, 180); // Leave room for suffix if needed

                $slug = $baseSlug;
                $count = 1;

                // Ensure uniqueness using DB queries
                while (DB::table('grades')->where('slug', $slug)->where('id', '!=', $grade->id)->exists()) {
                    $slug = $baseSlug . '-' . $count;
                    $count++;
                }

                DB::table('grades')->where('id', $grade->id)->update(['slug' => $slug]);
            }
        });

        // Make slug NOT NULL (unique constraint already exists)
        Schema::table('grades', function (Blueprint $table) {
            $table->string('slug', 191)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->string('slug', 191)->nullable()->change();
        });
    }
};
