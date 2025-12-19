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

        // Backfill existing quizzes with slugs using DB queries to avoid touching model scopes/events
        try {
            DB::table('quizzes')->select('id', 'title')->orderBy('id')->chunk(100, function ($quizzes) {
                foreach ($quizzes as $quiz) {
                    // Use SlugService.generateSlug to produce a base slug (no DB queries)
                    $baseSlug = \App\Services\SlugService::generateSlug($quiz->title ?? '');
                    if (empty($baseSlug)) $baseSlug = 'quiz';
                    $baseSlug = substr($baseSlug, 0, 180);

                    $slug = $baseSlug;
                    $count = 1;

                    // Ensure uniqueness using DB checks
                    while (DB::table('quizzes')->where('slug', $slug)->where('id', '!=', $quiz->id)->exists()) {
                        $slug = $baseSlug . '-' . $count;
                        $count++;
                    }

                    $slug = substr($slug, 0, 191);
                    DB::table('quizzes')->where('id', $quiz->id)->update(['slug' => $slug]);
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
