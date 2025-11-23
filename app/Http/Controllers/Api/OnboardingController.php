<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\OnboardingService;

class OnboardingController extends Controller
{
    protected $service;

    public function __construct(OnboardingService $service)
    {
        $this->service = $service;
    }

    /**
     * Mark a single onboarding step complete.
     * Payload: { step: string, data?: object }
     */
    public function completeStep(Request $request)
    {
        $data = $request->all();
        $rules = [
            'step' => 'required|string',
            'data' => 'sometimes|array',
        ];

        $validator = \Validator::make($data, $rules);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $step = $data['step'];
        $payload = $data['data'] ?? [];

        $onboarding = $this->service->completeStep($user, $step, $payload);

        return response()->json(['onboarding' => $onboarding]);
    }

    /**
     * Explicitly mark onboarding/profile complete.
     */
    public function finalize(Request $request)
    {
        $user = $request->user();

        // NOTE: Removed email verified requirement — onboarding finalize
        // now completes regardless of email verification status. Email
        // verification is reserved for invite flows and not required for
        // general onboarding in this deployment.
        $onboarding = $this->service->completeStep($user, 'profile_complete');
        return response()->json(['onboarding' => $onboarding, 'user' => $user->fresh()]);
    }
}
