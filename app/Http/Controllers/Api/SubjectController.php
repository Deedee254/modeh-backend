<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use App\Models\Grade;
use App\Models\Quiz;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class SubjectController extends Controller
{
    public function __construct()
    {
        // Remove auth for public browsing
    }

    // List subjects with pagination, search and quizzes_count
    public function index(Request $request)
    {
        $query = Subject::query()->where('is_approved', true)->withCount('topics as quizzes_count');

        if ($q = $request->get('q')) {
            $query->where('name', 'like', "%{$q}%");
        }

        $query->orderBy('created_at', 'desc');
        $perPage = max(1, (int)$request->get('per_page', 50)); // More for browsing
        $data = $query->paginate($perPage);

        // Attach a representative image for each subject when available
        $data->getCollection()->transform(function ($s) {
            // preserve any original image attribute if present
            $orig = $s->getAttribute('image') ?? null;
            $s->image = null;
            if (!empty($orig)) {
                try { $s->image = Storage::url($orig); } catch (\Exception $e) { $s->image = null; }
            }
            // Otherwise, try to find a quiz under this subject that has a cover_image
            if (empty($s->image)) {
                $quiz = Quiz::whereHas('topic', function ($q) use ($s) { $q->where('subject_id', $s->id); })
                    ->whereNotNull('cover_image')
                    ->orderBy('created_at', 'desc')
                    ->first();
                if ($quiz && $quiz->cover_image) {
                    try { $s->image = Storage::url($quiz->cover_image); } catch (\Exception $e) { $s->image = null; }
                }
            }
            return $s;
        });

        return response()->json(['subjects' => $data]);
    }

    // Show a single subject with topics and representative image
    public function show(Subject $subject)
    {
        $subject->load(['topics' => function($q) { $q->where('is_approved', true)->withCount('quizzes'); }]);
        // representative image
        $orig = $subject->getAttribute('image') ?? null;
        $subject->image = null;
        if (!empty($orig)) {
            try { $subject->image = Storage::url($orig); } catch (\Exception $e) { $subject->image = null; }
        }
        return response()->json(['subject' => $subject]);
    }

    // quiz-master proposes a subject under a grade
    public function store(Request $request)
    {
        $v = Validator::make($request->all(), [
            'grade_id' => 'required|exists:grades,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'auto_approve' => 'boolean',
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $user = $request->user();

        $subject = Subject::create([
            'grade_id' => $request->grade_id,
            'created_by' => $user->id,
            'name' => $request->name,
            'description' => $request->description ?? null,
            'auto_approve' => $request->get('auto_approve', false),
            'is_approved' => false,
        ]);

        // If admin/global auto-approval exists, this could be applied here.

        return response()->json(['subject' => $subject], 201);
    }

    // Admin approves a subject
    public function approve(Request $request, Subject $subject)
    {
        $user = $request->user();
        if (!method_exists($user, 'isAdmin') && !$user->is_admin) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $subject->is_approved = true;
        $subject->save();

        return response()->json(['subject' => $subject]);
    }

    // quiz-master/admin can upload an icon for a subject
    public function uploadIcon(Request $request, Subject $subject)
    {
        $user = $request->user();
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);
        if ($subject->created_by !== $user->id && empty($user->is_admin)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $v = Validator::make($request->all(), [
            'icon' => 'required|file|image|max:2048'
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $path = $request->file('icon')->store('subjects/icons', 'public');
        $subject->icon = $path;
        $subject->save();

        return response()->json(['subject' => $subject]);
    }

    // Get topics for a specific subject
    public function topics(Request $request, Subject $subject)
    {
        $query = $subject->topics()
            ->where('is_approved', true)
            ->withCount('quizzes');

        if ($request->has('approved')) {
            $query->where('is_approved', (bool)$request->get('approved'));
        }

        $perPage = min(100, max(1, (int)$request->get('per_page', 10)));
        $data = $query->paginate($perPage);

        // Attach storage URLs for any images
        $data->getCollection()->transform(function ($topic) {
            if ($topic->image) {
                try {
                    $topic->image = Storage::url($topic->image);
                } catch (\Exception $e) {
                    $topic->image = null;
                }
            }
            return $topic;
        });

        return response()->json([
            'topics' => $data->items(),
            'meta' => [
                'current_page' => $data->currentPage(),
                'from' => $data->firstItem(),
                'last_page' => $data->lastPage(),
                'per_page' => $data->perPage(),
                'to' => $data->lastItem(),
                'total' => $data->total()
            ]
        ]);
    }
}
