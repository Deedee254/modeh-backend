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
            $query->where(function ($slugQuery) use ($request, $slugParts) {
                $slugQuery->whereRaw("LOWER(REPLACE(name, ' ', '-')) LIKE LOWER(?)", ["%{$request->slug}%"])
                    ->orWhereRaw("LOWER(REPLACE(name, ' ', '-')) LIKE LOWER(?)", ["%" . implode("%", $slugParts) . "%"]);
            });
            
            $quizMasters = $query->with(['quizMasterProfile.grade', 'quizzes' => function ($q) {
                $q->where('is_approved', true)->where('visibility', 'published')->with('topic');
            }])->get();
        } else {
            $quizMasters = $query->with(['quizMasterProfile.grade', 'quizzes' => function ($q) {
                $q->where('is_approved', true)->where('visibility', 'published')->with('topic');
            }])->paginate(12);
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
            if (!$profile) {
                return null;
            }
            $subjects = Subject::whereIn('id', $profile->subjects ?? [])->get()
                ->map(function ($subject) {
                    return [
                        'id' => $subject->id,
                        'name' => $subject->name,
                    ];
                });

            $data = [
                'id' => $user->id,
                'quiz_master_id' => $profile->id,
                'name' => $user->name,
                'avatar' => $user->avatar,
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
                'slug' => strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $user->name)),
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
                ->where('quiz_master_id', $profile->id)
                ->count();

            // Add is_following for authenticated users
            if ($currentUserId) {
                $data['is_following'] = DB::table('quiz_master_follows')
                    ->where('quiz_master_id', $profile->id)
                    ->where('user_id', $currentUserId)
                    ->exists();
            }

            return $data;
        });

        $collection = $collection->filter()->values();

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
        // Only load approved+published quizzes on this public endpoint
        $user = User::with(['quizMasterProfile', 'quizzes' => function ($q) {
            $q->where('is_approved', true)->where('visibility', 'published')->with('topic');
        }])->findOrFail($id);

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
            'quiz_master_id' => $profile->id,
            'name' => $user->name,
            'avatar' => $user->avatar,
            'avatar_url' => $user->avatar_url,
            'social_avatar' => $user->social_avatar,
            'headline' => $profile->headline ?? 'An experienced quiz master',
            'bio' => $profile->bio,
            'institution' => $profile->institution ?? 'Independent Educator',
            'grade' => $profile->grade ? [
                'id' => $profile->grade->id,
                'name' => $profile->grade->name,
            ] : null,
            'subjects' => $subjects,
            'slug' => strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $user->name)),
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

        // Include wallet summary (publicly visible aggregated earnings only)
        try {
            $wallet = \App\Models\Wallet::firstOrCreate(['user_id' => $user->id], [
                'type' => \App\Models\Wallet::TYPE_QUIZ_MASTER,
                'available' => 0,
                'pending' => 0,
                'withdrawn_pending' => 0,
                'settled' => 0,
                'earned_this_month' => 0,
                'lifetime_earned' => 0,
            ]);

            $data['wallet'] = [
                'available' => (float) $wallet->available,
                'pending' => (float) $wallet->pending,
                'lifetime_earned' => (float) $wallet->lifetime_earned,
                'earned_from_quizzes' => (float) ($wallet->earned_from_quizzes ?? 0),
            ];
            // Also expose a simple top-level total earnings field for UIs
            $data['total_earnings'] = (float) $wallet->lifetime_earned;
        } catch (\Throwable $e) {
            // Don't fail the profile response on wallet lookup errors
            $data['wallet'] = null;
            $data['total_earnings'] = 0;
        }

        // Include followers count
        $data['followers_count'] = DB::table('quiz_master_follows')
            ->where('quiz_master_id', $profile->id)
            ->count();

        // Add is_following for authenticated users
        if ($request->user()) {
            $data['is_following'] = DB::table('quiz_master_follows')
                ->where('quiz_master_id', $profile->id)
                ->where('user_id', $request->user()->id)
                ->exists();
        }

        return response()->json(['data' => $data]);
    }
}
