<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Services\SlugService;
use App\Models\Quiz;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            if (!Schema::hasColumn('quizzes', 'slug')) {
                $table->string('slug', 191)->nullable()->after('title');
                $table->unique('slug');
                $table->index('slug');
            }
        });

        // Backfill existing quizzes with slugs
        try {
            Quiz::whereNull('slug')->orWhere('slug', '')->chunk(100, function ($quizzes) {
                foreach ($quizzes as $quiz) {
                    $slug = SlugService::makeUniqueSlug($quiz->title, Quiz::class, $quiz->id);
                    // Ensure slug is within 191 characters (MySQL utf8mb4 index limit)
                    $slug = substr($slug, 0, 191);
                    $quiz->update(['slug' => $slug]);
                }
            });
        } catch (\Exception $e) {
            \Log::warning('Error backfilling slugs: ' . $e->getMessage());
        }

        // Make slug non-nullable after backfill
        Schema::table('quizzes', function (Blueprint $table) {
            $table->string('slug', 191)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropIndex(['slug']);
            $table->dropColumn('slug');
        });
    }
};
