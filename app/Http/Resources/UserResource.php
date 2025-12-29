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
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'phone' => $this->phone,
            'avatar' => $this->avatar_url,
            'bio' => $this->bio,
            'is_profile_completed' => (bool)$this->is_profile_completed,
            'email_verified_at' => $this->email_verified_at,
            'affiliate_code' => $this->affiliate_code,
            'created_at' => $this->created_at,
        ];

        if ($this->relationLoaded('affiliate')) {
            $payload['affiliate'] = $this->affiliate;
        }

        if ($this->relationLoaded('institutions')) {
            $payload['institutions'] = $this->institutions;
        }

        if ($this->role === 'quizee' && $this->relationLoaded('quizeeProfile')) {
            $payload['quizee_profile'] = $this->quizeeProfile;
        }

        if ($this->role === 'quiz-master' && $this->relationLoaded('quizMasterProfile')) {
            $payload['quiz_master_profile'] = $this->quizMasterProfile;
        }

        // Add computed helper fields for frontend
        $payload['missing_profile_fields'] = $this->getMissingProfileFields();
        $payload['missing_profile_messages'] = $this->getMissingProfileMessages($payload['missing_profile_fields']);

        if ($this->relationLoaded('onboarding')) {
            $payload['onboarding'] = $this->onboarding;
        }

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
