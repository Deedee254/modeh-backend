<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Services\SlugService;
use App\Models\Topic;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure the slug column exists and is nullable so we can backfill safely
        Schema::table('topics', function (Blueprint $table) {
            if (!Schema::hasColumn('topics', 'slug')) {
                $table->string('slug', 191)->nullable();
            }
        });

        // Backfill topics with slugs using DB queries to avoid touching model scopes/events
        $baseQuery = DB::table('topics')->select('id', 'name');

        $baseQuery->orderBy('id')->chunk(100, function ($topics) {
            foreach ($topics as $topic) {
                // Create a simple slug from the topic name
                $baseSlug = \Illuminate\Support\Str::slug($topic->name);
                $baseSlug = substr($baseSlug, 0, 180); // Leave room for suffix if needed

                $slug = $baseSlug;
                $count = 1;

                // Ensure uniqueness using DB queries
                while (DB::table('topics')->where('slug', $slug)->where('id', '!=', $topic->id)->exists()) {
                    $slug = $baseSlug . '-' . $count;
                    $count++;
                }

                DB::table('topics')->where('id', $topic->id)->update(['slug' => $slug]);
            }
        });

        // Make slug NOT NULL (unique constraint already exists)
        Schema::table('topics', function (Blueprint $table) {
            $table->string('slug', 191)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            if (Schema::hasColumn('topics', 'slug')) {
                $table->dropUnique(['slug']);
                $table->dropColumn('slug');
            }
        });
    }
};
