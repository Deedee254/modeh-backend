<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\Quiz;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;

class QuestionController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request)
    {
        // Return questions. By default return questions created by user unless
        // the request explicitly asks for banked/random questions (used by
        // battles/daily-challenge). When `for_battle=1` or `random=1` or
        // `banked=1` is present we return banked/global questions and ignore
        // the created_by restriction so non-admin quizees can fetch the
        // public question bank.
        $user = $request->user();
        $query = Question::query();

    $isBankQuery = $request->boolean('random') || $request->boolean('banked');
        if (!$isBankQuery) {
            if (!isset($user->is_admin) || !$user->is_admin) {
                $query->where('created_by', $user->id);
            }
        }

        // Honor explicit banked param when hitting /api/questions
        if ($request->has('banked') && Schema::hasColumn('questions', 'is_banked')) {
            $query->where('is_banked', $request->boolean('banked') ? 1 : 0);
        }
        // Basic search by text/type
        if ($q = $request->get('q')) {
            $query->where(function($qq) use ($q) {
                $qq->where('body', 'like', "%{$q}%")
                   ->orWhere('type', 'like', "%{$q}%");
            });
        }
        // randomize when requested
        if ($request->boolean('random')) {
            $query->inRandomOrder();
        } else {
            $query->orderByDesc('id');
        }

        $perPage = max(1, (int)$request->get('per_page', 20));
        return response()->json(['questions' => $query->paginate($perPage)]);
    }

    /**
     * Public question bank endpoint: returns global banked/random questions
     * with optional filters. This keeps quiz-master listing (`index`) separate.
     */
    public function bank(Request $request)
    {
        $query = Question::query();
        // The public question bank is independent of any quiz-master-set `for_battle` flag.
        // We intentionally do not filter by `for_battle` here.
        if ($grade = $request->get('grade_id')) $query->where('grade_id', $grade);
        if ($subject = $request->get('subject_id')) $query->where('subject_id', $subject);
        if ($topic = $request->get('topic_id')) $query->where('topic_id', $topic);
        if ($difficulty = $request->get('difficulty')) $query->where('difficulty', $difficulty);

        if ($q = $request->get('q')) {
            $query->where(function($qq) use ($q) {
                $qq->where('body', 'like', "%{$q}%")
                   ->orWhere('type', 'like', "%{$q}%");
            });
        }

        // When fetching the public bank, exclude the requesting user's own
        // banked questions to match the expectations of client code and tests
        // which assume the quiz-master won't receive their own questions in this
        // public listing.
        $user = $request->user();
        if ($user) {
            $query->where('created_by', '!=', $user->id);
        }

        if ($request->boolean('random')) {
            $query->inRandomOrder();
        } else {
            $query->orderByDesc('id');
        }

        $perPage = max(1, (int)$request->get('per_page', 20));
        return response()->json(['questions' => $query->paginate($perPage)]);
    }

    public function store(Request $request)
    {
        $v = Validator::make($request->all(), [
            'quiz_id' => 'nullable|exists:quizzes,id',
            'type' => 'required|string|in:' . implode(',', array_keys(Question::getAllowedTypes())),
            'body' => 'required|string',
            'options' => 'nullable|array',
            'answers' => 'nullable|array',
            'tags' => 'nullable|array',
            'hint' => 'nullable|string',
            'solution_steps' => 'nullable|array',
            'subject_id' => 'nullable|exists:subjects,id',
            'topic_id' => 'nullable|exists:topics,id',
            'grade_id' => 'nullable|exists:grades,id',
            'for_battle' => 'nullable|boolean',
            'is_quiz-master_marked' => 'nullable|boolean',
            'difficulty' => 'nullable|integer|min:1|max:5',
            'media' => 'nullable|file|max:10240|mimes:jpeg,png,jpg,gif,mp3,wav,ogg,m4a,mp4,webm',
            'youtube_url' => 'nullable|string|regex:/^(https?:\/\/)?(www\.)?(youtube\.com|youtu\.be)\/.+$/',
            'media_metadata' => 'nullable|array',
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $user = $request->user();

        $mediaPath = null;
        $mediaType = null;
        $mediaMetadata = [];

        // Handle media file upload
        if ($request->hasFile('media')) {
            $mediaFile = $request->file('media');
            $mediaPath = Storage::disk('public')->putFile('question_media', $mediaFile);
            $mediaPath = Storage::url($mediaPath);
            
            // Determine media type
            $mimeType = $mediaFile->getMimeType();
            if (strpos($mimeType, 'image/') === 0) {
                $mediaType = 'image';
                // Get image dimensions
                try {
                    $dimensions = getimagesize($mediaFile->getPathname());
                    if ($dimensions) {
                        $mediaMetadata['width'] = $dimensions[0];
                        $mediaMetadata['height'] = $dimensions[1];
                    }
                } catch (\Exception $e) {}
            } elseif (strpos($mimeType, 'audio/') === 0) {
                $mediaType = 'audio';
                // Get audio duration if possible
                try {
                    $getID3 = new \getID3;
                    $fileInfo = $getID3->analyze($mediaFile->getPathname());
                    if (isset($fileInfo['playtime_seconds'])) {
                        $mediaMetadata['duration'] = $fileInfo['playtime_seconds'];
                    }
                } catch (\Exception $e) {}
            } elseif (strpos($mimeType, 'video/') === 0) {
                $mediaType = 'video';
                // Get video metadata if possible
                try {
                    $ffprobe = \FFMpeg\FFProbe::create();
                    $duration = $ffprobe
                        ->format($mediaFile->getPathname())
                        ->get('duration');
                    if ($duration) {
                        $mediaMetadata['duration'] = floatval($duration);
                    }
                } catch (\Exception $e) {}
            }
        }

        // Handle YouTube URLs
        $youtubeUrl = $request->get('youtube_url');
        if ($youtubeUrl) {
            $mediaType = 'youtube';
            // Extract video ID and other metadata
            if (preg_match('/(?:youtube\.com\/(?:[^\/\n\s]+\/\s*[^\/\n\s]+\/|(?:v|e(?:mbed)?)\/|\s*[^\/\n\s]+\?v=)|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $youtubeUrl, $matches)) {
                $mediaMetadata['video_id'] = $matches[1];
            }
        }

        // Expect canonical 'answers' array from frontend
        $answers = $request->get('answers');
        
        // For fill_blank type, ensure answers is an array
        if ($request->type === 'fill_blank' && !is_array($answers)) {
            $answers = [$answers];
        }

        $question = Question::create([
            'quiz_id' => $request->quiz_id,
            'created_by' => $user->id,
            'type' => $request->type,
            'body' => $request->body,
            'options' => $request->options ?? null,
            'answers' => $answers ?? null,
            'media_path' => $mediaPath,
            'media_type' => $mediaType,
            'youtube_url' => $youtubeUrl,
            'media_metadata' => $mediaMetadata,
            'difficulty' => $request->get('difficulty', 3),
            'is_quiz-master_marked' => $request->get('is_quiz-master_marked', false),
            'is_approved' => false,
            'tags' => $request->get('tags'),
            'hint' => $request->get('hint'),
            'solution_steps' => $request->get('solution_steps'),
            'subject_id' => $request->get('subject_id'),
            'topic_id' => $request->get('topic_id'),
            'grade_id' => $request->get('grade_id'),
            'for_battle' => $request->get('for_battle', true),
        ]);

        // If attached to a quiz, trigger recalc of difficulty
        if ($question->quiz_id) {
            try {
                $quiz = Quiz::find($question->quiz_id);
                if ($quiz) $quiz->recalcDifficulty();
            } catch (\Exception $e) {
                // ignore
            }
        }

        return response()->json(['question' => $question], 201);
    }

    public function update(Request $request, Question $question)
    {
        $user = $request->user();
        if ($question->created_by !== $user->id && (!isset($user->is_admin) || !$user->is_admin)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $v = Validator::make($request->all(), [
            'type' => 'nullable|string',
            'body' => 'nullable|string',
            'options' => 'nullable|array',
            'answers' => 'nullable|array',
            'correctAnswer' => 'nullable',
            'tags' => 'nullable|array',
            'hint' => 'nullable|string',
            'solution_steps' => 'nullable|array',
            'subject_id' => 'nullable|exists:subjects,id',
            'topic_id' => 'nullable|exists:topics,id',
            'grade_id' => 'nullable|exists:grades,id',
            'for_battle' => 'nullable|boolean',
            'is_quiz-master_marked' => 'nullable|boolean',
            'difficulty' => 'nullable|integer|min:1|max:5',
            'media' => 'nullable|file|max:10240',
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        if ($request->hasFile('media')) {
            $path = Storage::disk('public')->putFile('question_media', $request->file('media'));
            $question->media_path = Storage::url($path);
        }

        // Expect canonical 'answers' array from frontend
        $answers = $request->get('answers');

        $question->fill($request->only(['type', 'body', 'options', 'difficulty']));
        if (!is_null($answers)) $question->answers = $answers;
        // additional fields
        foreach (['tags','hint','solution_steps','subject_id','topic_id','grade_id','for_battle','is_quiz-master_marked'] as $f) {
            if ($request->has($f)) $question->{$f} = $request->get($f);
        }
        $question->save();

        if ($question->quiz_id) {
            try { $question->quiz->recalcDifficulty(); } catch (\Exception $e) {}
        }

        return response()->json(['question' => $question]);
    }

    /**
     * Admin approves a question
     */
    public function approve(Request $request, Question $question)
    {
        $user = $request->user();
        // Prefer a dedicated isAdmin method, fallback to role column
        $isAdmin = method_exists($user, 'isAdmin') ? $user->isAdmin() : (($user->role ?? '') === 'admin');
        if (!$isAdmin) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $question->is_approved = true;
        // clear approval request timestamp when approved
        if (Schema::hasColumn('questions', 'approval_requested_at')) {
            $question->approval_requested_at = null;
        }
        $question->save();

        return response()->json(['question' => $question]);
    }
}
