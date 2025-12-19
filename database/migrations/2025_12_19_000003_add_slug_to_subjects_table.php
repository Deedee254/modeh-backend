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
        // Backfill subjects with slugs using simple Str::slug
        Subject::whereNull('slug')->orWhere('slug', '')->orWhere('slug', '0')->chunk(100, function ($subjects) {
            foreach ($subjects as $subject) {
                // Create a simple slug from the subject name
                $baseSlug = \Illuminate\Support\Str::slug($subject->name);
                $baseSlug = substr($baseSlug, 0, 180); // Leave room for suffix if needed
                
                $slug = $baseSlug;
                $count = 1;
                
                // Ensure uniqueness
                while (Subject::where('slug', $slug)->where('id', '!=', $subject->id)->exists()) {
                    $slug = $baseSlug . '-' . $count;
                    $count++;
                }
                
                $subject->update(['slug' => $slug]);
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
