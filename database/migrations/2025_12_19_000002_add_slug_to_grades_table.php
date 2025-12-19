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
        // Backfill grades with slugs using simple Str::slug
        Grade::whereNull('slug')->orWhere('slug', '')->orWhere('slug', '0')->chunk(100, function ($grades) {
            foreach ($grades as $grade) {
                // Create a simple slug from the grade name
                $baseSlug = \Illuminate\Support\Str::slug($grade->name);
                $baseSlug = substr($baseSlug, 0, 180); // Leave room for suffix if needed
                
                $slug = $baseSlug;
                $count = 1;
                
                // Ensure uniqueness
                while (Grade::where('slug', $slug)->where('id', '!=', $grade->id)->exists()) {
                    $slug = $baseSlug . '-' . $count;
                    $count++;
                }
                
                $grade->update(['slug' => $slug]);
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
