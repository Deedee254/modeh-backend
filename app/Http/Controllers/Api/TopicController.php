<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Topic;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use App\Models\Quiz;

class TopicController extends Controller
{
    public function __construct()
    {
        // Protect most endpoints but allow public listing and show (index, show)
        $this->middleware('auth:sanctum')->except(['index', 'show']);
    }

    // quiz-master uploads an image for a topic
    public function uploadImage(Request $request, Topic $topic)
    {
        $user = $request->user();
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);
        if ($topic->created_by !== $user->id && empty($user->is_admin)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $v = Validator::make($request->all(), [
            'image' => 'required|file|image|max:5120'
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $path = $request->file('image')->store('topics', 'public');
        $topic->image = $path;
        $topic->save();

        return response()->json(['topic' => $topic]);
    }

    // List topics; optionally filter approved only via ?approved=1
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Topic::query()->with('subject')->withCount('quizzes');

        if ($q = $request->get('q')) {
            $query->where('name', 'like', "%{$q}%");
        }

        if (!is_null($request->get('approved'))) {
            $query->where('is_approved', (bool)$request->get('approved'));
        }

        // anonymous users see only approved topics.
        // Authenticated non-admin users should see approved topics plus any topics they created.
        if (!$user) {
            $query->where('is_approved', true);
        } else {
            if (empty($user->is_admin) || !$user->is_admin) {
                $query->where(function ($q) use ($user) {
                    $q->where('is_approved', true)
                      ->orWhere('created_by', $user->id);
                });
            }
            // admins see all topics (no additional where)
        }

        $perPage = max(1, (int)$request->get('per_page', 10));
        $data = $query->paginate($perPage);
            // Attach an image for each topic if available: topic.image or representative quiz cover
            $data->getCollection()->transform(function ($t) {
                $orig = $t->getAttribute('image') ?? null;
                $t->image = null;
                if (!empty($orig)) {
                    try { $t->image = Storage::url($orig); } catch (\Exception $e) { $t->image = null; }
                }
                if (empty($t->image)) {
                    $quiz = Quiz::where('topic_id', $t->id)->whereNotNull('cover_image')->orderBy('created_at', 'desc')->first();
                    if ($quiz && $quiz->cover_image) {
                        try { $t->image = Storage::url($quiz->cover_image); } catch (\Exception $e) { $t->image = null; }
                    }
                }
                return $t;
            });

            return response()->json(['topics' => $data]);
    }

    // Show a single topic (public-safe view)
    public function show(Topic $topic)
    {
        $topic->load('subject');
        // Attach image url if present
        $orig = $topic->getAttribute('image') ?? null;
        $topic->image = null;
        if (!empty($orig)) {
            try { $topic->image = Storage::url($orig); } catch (\Exception $e) { $topic->image = null; }
        }
        // quiz count
        $topic->quizzes_count = Quiz::where('topic_id', $topic->id)->count();
        return response()->json(['topic' => $topic]);
    }

    // quiz-master creates a topic under a subject (subject must be approved)
    public function store(Request $request)
    {
        $v = Validator::make($request->all(), [
            'subject_id' => 'required|exists:subjects,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $subject = Subject::find($request->subject_id);
        if (!$subject->is_approved) {
            // If subject is not approved and auto_approve is false, block
            return response()->json(['message' => 'Subject is not approved'], 403);
        }

        $user = $request->user();

        $topic = Topic::create([
            'subject_id' => $subject->id,
            'created_by' => $user->id,
            'name' => $request->name,
            'description' => $request->description ?? null,
            'is_approved' => false,
        ]);

        // If subject auto_approve is true, optionally auto-approve topic too
        if ($subject->auto_approve) {
            $topic->is_approved = true;
            $topic->save();
        }

        return response()->json(['topic' => $topic], 201);
    }

    // Admin approves a topic
    public function approve(Request $request, Topic $topic)
    {
        $user = $request->user();
        if (!method_exists($user, 'isAdmin') && !$user->is_admin) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $topic->is_approved = true;
        $topic->save();

        return response()->json(['topic' => $topic]);
    }
}
