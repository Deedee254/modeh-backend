<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Package;
use App\Models\Subscription;
use Illuminate\Support\Facades\Auth;
use App\Models\PaymentSetting;
use App\Services\MpesaService;
use Illuminate\Support\Facades\Log;

class PackageController extends Controller
{
    public function index()
    {
        $packages = Package::where('is_active', true)->orderBy('price')->get()->map(function ($p) {
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
            ];
        });
        return response()->json(['packages' => $packages]);
    }

    public function subscribe(Request $request, Package $package)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['ok' => false, 'message' => 'Unauthenticated'], 401);

        // If user has an active subscription, mark it as cancelled/ended so this acts as a switch
        $previous = Subscription::where('user_id', $user->id)
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
        if ((float)$pkgPrice === 0.0 || $gw === 'free') {
            $sub = Subscription::create([
                'user_id' => $user->id,
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
                $setting = PaymentSetting::where('gateway', 'mpesa')->first();
                $config = $setting ? ($setting->config ?? []) : [];
                $requiredKeys = ['consumer_key', 'consumer_secret', 'shortcode', 'passkey'];
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
                $res = $service->initiateStkPush($phone, $amount, 'Subscription-temp');
                if ($res['ok']) {
                    // Create subscription now that initiation succeeded
                    $sub = Subscription::create([
                        'user_id' => $user->id,
                        'package_id' => $package->id,
                        'status' => 'pending',
                        'gateway' => 'mpesa',
                        'gateway_meta' => ['phone' => $phone, 'tx' => $res['tx'], 'initiated_at' => now()],
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
