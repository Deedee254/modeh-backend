<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\OneOffPurchase;
use App\Models\MpesaTransaction;
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
        $config = config('services.mpesa');
        $service = new MpesaService($config);
        $phone = $data['phone'] ?? ($user->phone ?? null);
        $res = $service->initiateStkPush($phone, (float)$purchase->amount, 'OneOff-'.$purchase->id);

        if ($res['ok']) {
            $checkoutRequestId = $res['tx'];  // M-PESA's CheckoutRequestID
            
            $purchase->gateway_meta = array_merge($purchase->gateway_meta ?? [], [
                'tx' => $checkoutRequestId,
                'checkout_request_id' => $checkoutRequestId,  // CheckoutRequestID from M-PESA
                'initiated_at' => now()
            ]);
            $purchase->save();
            
            // Create MpesaTransaction record for reconciliation
            MpesaTransaction::create([
                'user_id' => $user->id,
                'checkout_request_id' => $checkoutRequestId,
                'merchant_request_id' => $res['body']['MerchantRequestID'] ?? null,
                'amount' => $purchase->amount,
                'phone' => $phone,
                'status' => 'pending',
                'billable_type' => OneOffPurchase::class,
                'billable_id' => $purchase->id,
                'raw_response' => json_encode($res['body'] ?? []),
            ]);
            
            Log::info('[OneOff Purchase] STK Push initiated', [
                'user_id' => $user->id,
                'purchase_id' => $purchase->id,
                'checkout_request_id' => $checkoutRequestId,
                'amount' => $purchase->amount,
            ]);
            
            return response()->json([
                'ok' => true, 
                'purchase' => $purchase, 
                'tx' => $checkoutRequestId,
                'checkout_request_id' => $checkoutRequestId  // Return checkout_request_id to frontend
            ]);
        }

        Log::error('[OneOff Purchase] STK Push failed', [
            'user_id' => $user->id,
            'purchase_id' => $purchase->id,
            'error' => $res['message'] ?? 'unknown error',
        ]);

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
