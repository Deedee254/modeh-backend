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
        // First, fix the varchar length to 191 if it's currently 255
        DB::statement("ALTER TABLE topics MODIFY COLUMN slug varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        // Backfill topics with slugs using simple Str::slug
        Topic::whereNull('slug')->orWhere('slug', '')->orWhere('slug', '0')->chunk(100, function ($topics) {
            foreach ($topics as $topic) {
                // Create a simple slug from the topic name
                $baseSlug = \Illuminate\Support\Str::slug($topic->name);
                $baseSlug = substr($baseSlug, 0, 180); // Leave room for suffix if needed
                
                $slug = $baseSlug;
                $count = 1;
                
                // Ensure uniqueness
                while (Topic::where('slug', $slug)->where('id', '!=', $topic->id)->exists()) {
                    $slug = $baseSlug . '-' . $count;
                    $count++;
                }
                
                $topic->update(['slug' => $slug]);
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
