<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Services\SlugService;
use App\Models\Subject;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure the slug column exists and is nullable so we can backfill safely
        Schema::table('subjects', function (Blueprint $table) {
            if (!Schema::hasColumn('subjects', 'slug')) {
                $table->string('slug', 191)->nullable();
            }
        });

        // Backfill subjects with slugs using DB queries to avoid touching model scopes/events
        $baseQuery = DB::table('subjects')->select('id', 'name');

        $baseQuery->orderBy('id')->chunk(100, function ($subjects) {
            foreach ($subjects as $subject) {
                // Create a simple slug from the subject name
                $baseSlug = \Illuminate\Support\Str::slug($subject->name);
                $baseSlug = substr($baseSlug, 0, 180); // Leave room for suffix if needed

                $slug = $baseSlug;
                $count = 1;

                // Ensure uniqueness using DB queries
                while (DB::table('subjects')->where('slug', $slug)->where('id', '!=', $subject->id)->exists()) {
                    $slug = $baseSlug . '-' . $count;
                    $count++;
                }

                DB::table('subjects')->where('id', $subject->id)->update(['slug' => $slug]);
            }
        });

        // Make slug NOT NULL (unique constraint already exists)
        Schema::table('subjects', function (Blueprint $table) {
            $table->string('slug', 191)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->string('slug', 191)->nullable()->change();
        });
    }
};
