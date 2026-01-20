<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UserResource
 *
 * Transforms a User model into a JSON response format suitable for API consumption.
 * Includes user profile information, role, avatar, and optional loaded relationships.
 * Conditionally includes sensitive data based on what's been eager-loaded.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $role
 * @property string|null $phone
 * @property string|null $bio
 * @property bool $is_profile_completed
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = [
            // Core user fields (clean, no duplicates)
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'phone' => $this->phone,
            'avatar' => $this->avatar_url,
            'bio' => $this->bio,
            'email_verified_at' => $this->email_verified_at,
            'affiliate_code' => $this->affiliate_code,
            'is_profile_completed' => (bool)$this->is_profile_completed,
            'created_at' => $this->created_at,
        ];

        // Add profile data - unified field for both Quizee and QuizMaster
        if ($this->relationLoaded('quizeeProfile') && $this->quizeeProfile) {
            $payload['profile'] = $this->quizeeProfile;
        } elseif ($this->relationLoaded('quizMasterProfile') && $this->quizMasterProfile) {
            $payload['profile'] = $this->quizMasterProfile;
        }

        // Add institutions if loaded
        if ($this->relationLoaded('institutions')) {
            $payload['institutions'] = $this->institutions;
        }

        // Add affiliate if loaded
        if ($this->relationLoaded('affiliate')) {
            $payload['affiliate'] = $this->affiliate;
        }

        // Add profile completion status
        $payload['profile_status'] = [
            'is_completed' => (bool)$this->is_profile_completed,
            'missing_fields' => $this->getMissingProfileFields(),
            'missing_messages' => $this->getMissingProfileMessages($this->getMissingProfileFields()),
        ];

        return $payload;
    }

    protected function getMissingProfileFields()
    {
        $missing = [];
        if (empty($this->role)) $missing[] = 'role';

        $hasInstitution = false;
        if ($this->relationLoaded('institutions') && $this->institutions->count() > 0) {
            $hasInstitution = true;
        }
        
        if (!$hasInstitution) {
            if ($this->role === 'quizee' && optional($this->quizeeProfile)->institution) $hasInstitution = true;
            if ($this->role === 'quiz-master' && optional($this->quizMasterProfile)->institution) $hasInstitution = true;
        }

        if (!$hasInstitution) $missing[] = 'institution';

        if ($this->role === 'quizee') {
            if (!optional($this->quizeeProfile)->grade_id) $missing[] = 'grade';
        }

        if ($this->role === 'quiz-master') {
            $subjects = optional($this->quizMasterProfile)->subjects;
            if (!$subjects || (is_array($subjects) && count($subjects) === 0)) $missing[] = 'subjects';
        }

        return $missing;
    }

    protected function getMissingProfileMessages(array $missing)
    {
        $messages = [];
        $map = [
            'role' => 'Account role (quizee or quiz-master) is missing',
            'institution' => 'Please select or confirm your institution/school',
            'grade' => 'Please select your grade',
            'subjects' => 'Please select at least one subject specialization',
        ];
        foreach ($missing as $k) {
            $messages[$k] = $map[$k] ?? 'Please complete: ' . $k;
        }
        return $messages;
    }
}
