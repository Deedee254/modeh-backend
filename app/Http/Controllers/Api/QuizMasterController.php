<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuizMasterController extends Controller
{
    /**
     * Display a listing of public quiz master profiles.
     */
    public function index()
    {
        $request = request();
        
        // Start building the query for users with a quiz master profile
        $query = User::query()->whereHas('quizMasterProfile');

        // Apply filters based on request parameters
        $query->whereHas('quizMasterProfile', function ($q) use ($request) {
            if ($request->has('grade_id') && $request->grade_id) {
                $q->where('grade_id', $request->grade_id);
            }
            if ($request->has('subject_id') && $request->subject_id) {
                // Assumes 'subjects' is a JSON array of IDs in the profile
                $q->whereJsonContains('subjects', (int)$request->subject_id);
            }
        });

        // If slug is provided, filter by slug - extract ID from slug pattern (e.g., "quiz-master-one" -> find by name/pattern)
        if ($request->has('slug') && $request->slug) {
            // The slug format is typically something like "quiz-master-one"
            // We'll search by user name containing parts of the slug
            $slugParts = explode('-', $request->slug);
            // Filter users whose name matches the slug pattern
            $query->whereRaw("LOWER(REPLACE(name, ' ', '-')) LIKE LOWER(?)", ["%{$request->slug}%"])
                  ->orWhereRaw("LOWER(REPLACE(name, ' ', '-')) LIKE LOWER(?)", ["%".implode("%", $slugParts)."%"]);
            
            $quizMasters = $query->with(['quizMasterProfile.grade', 'quizzes.topic'])->get();
        } else {
            $quizMasters = $query->with(['quizMasterProfile.grade'])->paginate(12);
        }

        // Get current user for following checks
        $currentUserId = $request->user()?->id;

        // Check if results are paginated or collection
        $isPaginated = $quizMasters instanceof \Illuminate\Pagination\AbstractPaginator;
        
        // Transform the collection for the frontend.
        if ($isPaginated) {
            $collection = $quizMasters->getCollection();
        } else {
            $collection = $quizMasters;
        }
        
        $collection->transform(function ($user) use ($currentUserId) {
            $profile = $user->quizMasterProfile;
            $subjects = Subject::whereIn('id', $profile->subjects ?? [])->get()
                ->map(function ($subject) {
                    return [
                        'id' => $subject->id,
                        'name' => $subject->name,
                    ];
                });

            $data = [
                'id' => $user->id,
                'name' => $user->name,
                // Prefer explicit uploaded avatar_url then fall back to social_avatar
                'avatar' => $user->avatar_url ?? $user->social_avatar,
                'avatar_url' => $user->avatar_url ?? $user->social_avatar,
                'headline' => $profile->headline ?: 'An experienced quiz master',
                'bio' => $profile->bio ?? '',
                'institution' => $profile->institution ?: '',
                'level' => $profile->level ? [
                    'id' => $profile->level->id,
                    'name' => $profile->level->name,
                ] : null,
                'grade' => $profile->grade ? [
                    'id' => $profile->grade->id,
                    'name' => $profile->grade->name,
                ] : null,
                'subjects' => $subjects,
            ];

            // Include quizzes if they were eagerly loaded
            if ($user->relationLoaded('quizzes')) {
                $data['quizzes'] = $user->quizzes->map(function ($quiz) {
                    // Resolve topic name defensively: topic may be object/array/string/null
                    $topicName = null;
                    try {
                        if (isset($quiz->topic)) {
                            if (is_object($quiz->topic) && isset($quiz->topic->name)) {
                                $topicName = $quiz->topic->name;
                            } elseif (is_array($quiz->topic) && isset($quiz->topic['name'])) {
                                $topicName = $quiz->topic['name'];
                            } elseif (is_string($quiz->topic) && trim($quiz->topic) !== '') {
                                $topicName = $quiz->topic;
                            }
                        }
                    } catch (\Throwable $_) {
                        $topicName = null;
                    }

                    return [
                        'id' => $quiz->id,
                        'slug' => $quiz->slug,
                        'title' => $quiz->title,
                        'topic_name' => $topicName,
                    ];
                });
            }

            // Include followers count
            $data['followers_count'] = DB::table('quiz_master_follows')
                ->where('quiz_master_id', $user->id)
                ->count();

            // Add is_following for authenticated users
            if ($currentUserId) {
                $data['is_following'] = DB::table('quiz_master_follows')
                    ->where('quiz_master_id', $user->id)
                    ->where('user_id', $currentUserId)
                    ->exists();
            }

            return $data;
        });

        // If it's a paginated collection, reconstruct it; otherwise return wrapped result
        if ($isPaginated) {
            $quizMasters->setCollection($collection);
            return response()->json($quizMasters);
        } else {
            return response()->json(['data' => $collection->values()]);
        }
    }

    /**
     * Display a single public quiz master profile.
     */
    public function show(Request $request, string $id)
    {
        // Find the user and eager-load all necessary relationships.
        $user = User::with(['quizMasterProfile', 'quizzes.topic'])->findOrFail($id);

        // Ensure the user has a quiz master profile.
        if (!$user->quizMasterProfile) {
            return response()->json(['message' => 'Quiz master not found'], 404);
        }

        $profile = $user->quizMasterProfile;
        $subjects = $profile->subjectModels->map(function ($subject) {
            return [
                'id' => $subject->id,
                'name' => $subject->name,
            ];
        });

        $data = [
            'id' => $user->id,
            'name' => $user->name,
            'avatar' => $user->avatar_url ?? $user->social_avatar,
            'headline' => $profile->headline ?? 'An experienced quiz master',
            'bio' => $profile->bio,
            'institution' => $profile->institution ?? 'Independent Educator',
            'grade' => $profile->grade ? [
                'id' => $profile->grade->id,
                'name' => $profile->grade->name,
            ] : null,
            'subjects' => $subjects,
            'quizzes' => $user->quizzes->map(function ($quiz) {
                // Resolve topic name defensively
                $topicName = null;
                try {
                    if (isset($quiz->topic)) {
                        if (is_object($quiz->topic) && isset($quiz->topic->name)) {
                            $topicName = $quiz->topic->name;
                        } elseif (is_array($quiz->topic) && isset($quiz->topic['name'])) {
                            $topicName = $quiz->topic['name'];
                        } elseif (is_string($quiz->topic) && trim($quiz->topic) !== '') {
                            $topicName = $quiz->topic;
                        }
                    }
                } catch (\Throwable $_) {
                    $topicName = null;
                }

                return [
                    'id' => $quiz->id,
                    'title' => $quiz->title,
                    'topic_name' => $topicName,
                ];
            }),
        ];

        // Add is_following for authenticated users
        if ($request->user()) {
            $data['is_following'] = DB::table('quiz_master_follows')
                ->where('quiz_master_id', $user->id)
                ->where('user_id', $request->user()->id)
                ->exists();
        }

        return response()->json(['data' => $data]);
    }
}
