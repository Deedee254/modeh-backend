<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Package;
use App\Models\Subscription;
use Illuminate\Support\Facades\Auth;
use App\Services\MpesaService;
use Illuminate\Support\Facades\Log;

class PackageController extends Controller
{
    public function index()
    {
        $audience = request()->input('audience', null);
        $q = Package::where('is_active', true)->orderBy('price');
        if ($audience) {
            $q = $q->where('audience', $audience);
        }
        $packages = $q->get()->map(function ($p) {
            return [
                'id' => $p->id,
                'title' => $p->title,
                'name' => $p->name,
                'description' => $p->description,
                'short_description' => $p->short_description,
                'price' => $p->price,
                'price_display' => $p->price_display,
                'currency' => $p->currency,
                'features' => $p->features ?? [],
                'duration_days' => $p->duration_days,
                'cover_image' => $p->cover_image,
                'more_link' => $p->more_link,
                'is_active' => (bool) $p->is_active,
                'slug' => $p->slug,
                'audience' => $p->audience ?? 'quizee',
            ];
        });
        return response()->json(['packages' => $packages]);
    }

    public function subscribe(Request $request, Package $package)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['ok' => false, 'message' => 'Unauthenticated'], 401);

        // Determine owner (defaults to the authenticated user)
        $ownerType = 'App\\Models\\User';
        $ownerId = $user->id;
        if ($request->owner_type === 'institution' || ($request->owner_type && str_contains($request->owner_type, 'Institution'))) {
            // institution subscription requested
            $institutionId = $request->owner_id;
            if (!$institutionId) {
                return response()->json(['ok' => false, 'message' => 'owner_id (institution id) required for institution subscription'], 422);
            }
            // owner_id may be numeric id or slug; try to resolve either way
            $instQuery = \App\Models\Institution::query();
            if (ctype_digit(strval($institutionId))) {
                $instQuery->orWhere('id', (int)$institutionId);
            }
            $instQuery->orWhere('slug', $institutionId);
            $inst = $instQuery->first();
            if (!$inst) return response()->json(['ok' => false, 'message' => 'Institution not found'], 404);

            // Ensure the current user is an institution-manager for this institution
            $isManager = $inst->users()->where('users.id', $user->id)->wherePivot('role', 'institution-manager')->exists();
            if (!$isManager) {
                return response()->json(['ok' => false, 'message' => 'Forbidden: must be an institution-manager to subscribe on behalf of institution'], 403);
            }

            $ownerType = \App\Models\Institution::class;
            $ownerId = $inst->id;
        }

        // If owner has an active subscription, mark it as cancelled/ended so this acts as a switch
        $previous = Subscription::where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('status', 'active')
            ->orderByDesc('started_at')
            ->first();
        if ($previous) {
            try {
                $previous->status = 'cancelled';
                $previous->ends_at = now();
                $previous->save();
            } catch (\Throwable $_) {}
        }

        $pkgPrice = $package->price ?? 0;
        $gw = $request->gateway ?? 'mpesa';

        // If package is free or gateway explicitly set to 'free', create and activate immediately
        // Enforce package audience matches owner type
        if ($ownerType === \App\Models\Institution::class && ($package->audience ?? 'quizee') !== 'institution') {
            return response()->json(['ok' => false, 'message' => 'Package is not available for institutions'], 422);
        }
        if ($ownerType === \App\Models\User::class && ($package->audience ?? 'quizee') !== 'quizee') {
            return response()->json(['ok' => false, 'message' => 'Package is not available for users'], 422);
        }

        if ((float)$pkgPrice === 0.0 || $gw === 'free') {
            $sub = Subscription::create([
                'user_id' => $user->id,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'package_id' => $package->id,
                'status' => 'active',
                'gateway' => 'free',
                'gateway_meta' => ['phone' => $request->phone ?? $user->phone ?? null],
                'started_at' => now(),
                'ends_at' => !empty($package->duration_days) ? now()->addDays($package->duration_days) : null,
            ]);
            return response()->json(['ok' => true, 'subscription' => $sub, 'previous_subscription' => $previous ?? null, 'package' => [
                'id' => $package->id,
                'title' => $package->title,
                'features' => $package->features ?? [],
            ]]);
        }

        // Handle mpesa gateway: validate phone/config, initiate STK push, then create subscription only on success
        if ($gw === 'mpesa') {
            $phone = $request->phone ?? ($user->phone ?? null);
            if (!$phone || !is_string($phone) || trim($phone) === '') {
                return response()->json([
                    'ok' => false,
                    'require_phone' => true,
                    'message' => 'Phone number required for mpesa payments',
                    'package' => [
                        'id' => $package->id,
                        'title' => $package->title,
                        'price' => $package->price,
                    ],
                ], 422);
            }

            try {
                $config = config('services.mpesa');
                // In sandbox/simulate mode the passkey is not always required by the dev environment,
                // so only require it for non-sandbox (production) configurations.
                $requiredKeys = ['consumer_key', 'consumer_secret', 'shortcode'];
                $isSandbox = !empty($config['simulate']) || (isset($config['environment']) && $config['environment'] === 'sandbox');
                if (!$isSandbox) {
                    $requiredKeys[] = 'passkey';
                }
                $missing = [];
                foreach ($requiredKeys as $k) {
                    if (empty($config[$k])) $missing[] = $k;
                }
                if (!empty($missing)) {
                    try { Log::error('MpesaService: missing config keys: '.implode(',', $missing)); } catch (\Throwable $_) {}
                    return response()->json([
                        'ok' => false,
                        'code' => 'gateway_not_configured',
                        'message' => 'Payment gateway not configured',
                        'missing' => $missing,
                        'package' => [
                            'id' => $package->id,
                            'title' => $package->title,
                            'price' => $package->price,
                        ],
                    ], 500);
                }

                $service = new MpesaService($config);
                $amount = $package->price ?? 0;
                
                Log::info('[Payment] Initiating MPESA STK push', [
                    'user_id' => $user->id,
                    'package_id' => $package->id,
                    'phone' => $phone,
                    'amount' => $amount,
                ]);
                
                $res = $service->initiateStkPush($phone, $amount, 'Subscription-temp');
                
                if ($res['ok']) {
                    // Create subscription now that initiation succeeded
                    $sub = Subscription::create([
                        'user_id' => $user->id,
                        'owner_type' => $ownerType,
                        'owner_id' => $ownerId,
                        'package_id' => $package->id,
                        'status' => 'pending',
                        'gateway' => 'mpesa',
                        'gateway_meta' => ['phone' => $phone, 'tx' => $res['tx'], 'initiated_at' => now()],
                    ]);
                    
                    Log::info('[Payment] STK push initiated successfully', [
                        'subscription_id' => $sub->id,
                        'user_id' => $user->id,
                        'tx' => $res['tx'],
                        'phone' => $phone,
                    ]);
                    
                    return response()->json([
                        'ok' => true,
                        'subscription' => $sub,
                        'tx' => $res['tx'],
                        'message' => $res['message'] ?? null,
                        'previous_subscription' => $previous ?? null,
                        'package' => [
                            'id' => $package->id,
                            'title' => $package->title,
                            'features' => $package->features ?? [],
                        ]
                    ]);
                }

                Log::error('[Payment] STK push failed', [
                    'user_id' => $user->id,
                    'package_id' => $package->id,
                    'phone' => $phone,
                    'error' => $res['message'] ?? 'unknown error',
                    'response' => $res,
                ]);

                return response()->json([
                    'ok' => false,
                    'message' => 'failed to initiate mpesa',
                    'package' => [
                        'id' => $package->id,
                        'title' => $package->title,
                        'features' => $package->features ?? [],
                    ]
                ], 500);
            } catch (\Throwable $e) {
                try { Log::error('Mpesa initiate error: '.$e->getMessage()); } catch (\Throwable $_) {}
                return response()->json([
                    'ok' => false,
                    'message' => 'mpesa initiation error',
                    'package' => [
                        'id' => $package->id,
                        'title' => $package->title,
                        'features' => $package->features ?? [],
                    ]
                ], 500);
            }
        }

        // For other gateways, create pending subscription (gateway may handle initiation separately)
        $sub = Subscription::create([
            'user_id' => $user->id,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'package_id' => $package->id,
            'status' => 'pending',
            'gateway' => $gw,
            'gateway_meta' => ['phone' => $request->phone ?? $user->phone ?? null],
        ]);
        $resp = ['ok' => true, 'subscription' => $sub, 'previous_subscription' => $previous ?? null, 'package' => [
            'id' => $package->id,
            'title' => $package->title,
            'features' => $package->features ?? [],
        ]];
        return response()->json($resp);
    }
}
