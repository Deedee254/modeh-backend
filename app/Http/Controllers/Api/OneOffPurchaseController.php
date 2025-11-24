<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\OneOffPurchase;
use App\Models\PaymentSetting;
use App\Services\MpesaService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class OneOffPurchaseController extends Controller
{
    public function store(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['ok' => false, 'message' => 'Unauthenticated'], 401);

        $data = $request->validate([
            'item_type' => 'required|string', // 'quiz' or 'battle'
            'item_id' => 'required',
            'amount' => 'required|numeric',
            'phone' => 'nullable|string',
            'gateway' => 'nullable|string',
        ]);

        $purchase = OneOffPurchase::create([
            'user_id' => $user->id,
            'item_type' => $data['item_type'],
            'item_id' => $data['item_id'],
            'amount' => $data['amount'],
            'status' => 'pending',
            'gateway' => $data['gateway'] ?? 'mpesa',
            'gateway_meta' => ['phone' => $data['phone'] ?? $user->phone ?? null],
            'meta' => [],
        ]);

        // initiate mpesa push via MpesaService
        $setting = PaymentSetting::where('gateway', 'mpesa')->first();
        $config = $setting ? ($setting->config ?? []) : [];
        $service = new MpesaService($config);
        $phone = $data['phone'] ?? ($user->phone ?? null);
        $res = $service->initiateStkPush($phone, (float)$purchase->amount, 'OneOff-'.$purchase->id);

        if ($res['ok']) {
            $purchase->gateway_meta = array_merge($purchase->gateway_meta ?? [], ['tx' => $res['tx'], 'initiated_at' => now()]);
            $purchase->save();
            return response()->json(['ok' => true, 'purchase' => $purchase, 'tx' => $res['tx']]);
        }

        return response()->json(['ok' => false, 'message' => 'Failed to initiate payment'], 500);
    }

    public function show(Request $request, $purchaseId)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['ok' => false, 'message' => 'Unauthenticated'], 401);

        $purchase = OneOffPurchase::find($purchaseId);
        if (!$purchase) return response()->json(['ok' => false, 'message' => 'Not found'], 404);

        // Only owner or admins can view
        if ($purchase->user_id !== $user->id && !(isset($user->is_admin) && $user->is_admin)) {
            return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        return response()->json(['ok' => true, 'purchase' => $purchase]);
    }
}
