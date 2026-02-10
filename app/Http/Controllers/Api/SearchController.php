<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Models\Subject;
use App\Models\Topic;
use App\Models\Question;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    /**
     * Global search across quizzes, topics, and subjects.
     * Returns questions only for authenticated users.
     */
    public function index(Request $request)
    {
        $term = trim((string) $request->get('q', ''));
        $perPage = min(20, max(1, (int) $request->get('per_page', $request->get('limit', 10))));
        $page = max(1, (int) $request->get('page', 1));
        $offset = ($page - 1) * $perPage;

        if ($term === '') {
            return response()->json([
                'quizzes' => [],
                'topics' => [],
                'subjects' => [],
                'questions' => [],
            ]);
        }

        $like = '%' . $term . '%';

        $quizBase = Quiz::query()
            ->select('id', 'slug', 'title', 'description', 'cover_image', 'difficulty', 'topic_id', 'subject_id', 'grade_id', 'level_id')
            ->where('is_approved', true)
            ->where('visibility', 'published')
            ->where('title', 'like', $like);

        $quizTotal = (clone $quizBase)->count();

        $quizzes = $quizBase
            ->withCount(['questions', 'likes'])
            ->with([
                'topic:id,name,slug,subject_id',
                'subject:id,name,slug,grade_id',
                'grade:id,name,slug',
                'level:id,name,slug',
            ])
            ->orderBy('created_at', 'desc')
            ->skip($offset)
            ->take($perPage)
            ->get();

        $topicBase = Topic::query()
            ->select('id', 'name', 'slug', 'subject_id', 'description', 'image')
            ->where('is_approved', true)
            ->where('name', 'like', $like);

        $topicTotal = (clone $topicBase)->count();

        $topics = $topicBase
            ->withCount('quizzes')
            ->with([
                'subject:id,name,slug,grade_id',
                'subject.grade:id,name,slug',
            ])
            ->orderBy('name', 'asc')
            ->skip($offset)
            ->take($perPage)
            ->get()
            ->map(function ($t) {
                $t->subject_name = $t->subject?->name;
                $t->grade_name = $t->subject?->grade?->name;
                return $t;
            })
            ->values();

        $subjectBase = Subject::query()
            ->select('id', 'name', 'slug', 'grade_id', 'description', 'icon')
            ->where('is_approved', true)
            ->where('name', 'like', $like);

        $subjectTotal = (clone $subjectBase)->count();

        $subjects = $subjectBase
            ->withCount('topics')
            ->with([
                'grade:id,name,slug,level_id',
                'topics' => function ($q) {
                    $q->select('id', 'subject_id')->withCount('quizzes');
                }
            ])
            ->orderBy('name', 'asc')
            ->skip($offset)
            ->take($perPage)
            ->get()
            ->map(function ($s) {
                $s->quizzes_count = $s->topics->sum('quizzes_count');
                unset($s->topics);
                return $s;
            })
            ->values();

        $questions = [];
        $questionTotal = 0;
        if ($request->user()) {
            $questionBase = Question::query()
                ->select('id', 'body')
                ->where('body', 'like', $like);

            $questionTotal = (clone $questionBase)->count();

            $questions = $questionBase
                ->orderBy('created_at', 'desc')
                ->skip($offset)
                ->take($perPage)
                ->get()
                ->map(function ($q) {
                    return [
                        'id' => $q->id,
                        'text' => $q->body,
                    ];
                })
                ->values();
        }

        return response()->json([
            'quizzes' => $quizzes,
            'topics' => $topics,
            'subjects' => $subjects,
            'questions' => $questions,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
            ],
            'totals' => [
                'quizzes' => $quizTotal,
                'topics' => $topicTotal,
                'subjects' => $subjectTotal,
                'questions' => $questionTotal,
            ],
        ]);
    }
}
