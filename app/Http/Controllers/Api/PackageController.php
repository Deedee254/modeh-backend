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

        // Create pending subscription
        $sub = Subscription::create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'status' => 'pending',
            'gateway' => $request->gateway ?? 'mpesa',
            'gateway_meta' => ['phone' => $request->phone ?? $user->phone ?? null],
        ]);

        // If gateway is mpesa, attempt to initiate STK Push
        if (($request->gateway ?? 'mpesa') === 'mpesa') {
            try {
                $setting = PaymentSetting::where('gateway', 'mpesa')->first();
                $config = $setting ? ($setting->config ?? []) : [];
                $service = new MpesaService($config);
                $amount = $package->price ?? 0;
                $phone = $request->phone ?? ($sub->gateway_meta['phone'] ?? null) ?? ($user->phone ?? null);
                $res = $service->initiateStkPush($phone, $amount, 'Subscription-'.$sub->id);
                if ($res['ok']) {
                    $sub->gateway_meta = array_merge($sub->gateway_meta ?? [], ['tx' => $res['tx'], 'initiated_at' => now()]);
                    $sub->save();
                    return response()->json(['ok' => true, 'subscription' => $sub, 'tx' => $res['tx'], 'message' => $res['message']]);
                }
                // initiation failed — return subscription but indicate failure
                return response()->json(['ok' => false, 'subscription' => $sub, 'message' => 'failed to initiate mpesa'], 500);
            } catch (\Throwable $e) {
                try { Log::error('Mpesa initiate error: '.$e->getMessage()); } catch (\Throwable $_) {}
                return response()->json(['ok' => false, 'subscription' => $sub, 'message' => 'mpesa initiation error'], 500);
            }
        }

        // For non-mpesa gateways just return subscription
        return response()->json(['ok' => true, 'subscription' => $sub]);
    }
}
