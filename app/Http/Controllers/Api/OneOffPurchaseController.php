<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\OneOffPurchase;
use App\Models\Quiz;
use App\Models\Battle;
use App\Models\Tournament;
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
            'item_type' => 'required|in:quiz,battle,tournament',
            'item_id' => 'required',
            'amount' => 'nullable|numeric',
            'phone' => 'nullable|string',
            'gateway' => 'nullable|string',
        ]);

        $resolvedAmount = $this->resolveOneOffAmount($data['item_type'], $data['item_id']);
        if ($resolvedAmount === null || $resolvedAmount <= 0) {
            return response()->json(['ok' => false, 'message' => 'Invalid item or price not configured'], 422);
        }

        if (!empty($data['amount']) && (float)$data['amount'] != (float)$resolvedAmount) {
            Log::warning('[OneOff Purchase] Client amount mismatch', [
                'item_type' => $data['item_type'],
                'item_id' => $data['item_id'],
                'client_amount' => $data['amount'],
                'resolved_amount' => $resolvedAmount,
            ]);
        }

        $gateway = $data['gateway'] ?? 'mpesa';
        $phone = $data['phone'] ?? ($user->phone ?? null);

        if ($gateway === 'mpesa' && (!$phone || !is_string($phone) || trim($phone) === '')) {
            return response()->json([
                'ok' => false,
                'require_phone' => true,
                'message' => 'Phone number required for mpesa payments',
            ], 422);
        }

        $phone = is_string($phone) ? trim($phone) : $phone;

        $purchase = OneOffPurchase::create([
            'user_id' => $user->id,
            'item_type' => $data['item_type'],
            'item_id' => $data['item_id'],
            'amount' => $resolvedAmount,
            'status' => 'pending',
            'gateway' => $gateway,
            'gateway_meta' => ['phone' => $phone],
            'meta' => [],
        ]);

        // initiate mpesa push via MpesaService
        $config = config('services.mpesa');
        $service = new MpesaService($config);
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

    private function resolveOneOffAmount(string $type, $itemId): ?float
    {
        switch ($type) {
            case 'quiz':
                $quiz = Quiz::find($itemId);
                return $quiz ? (float) ($quiz->one_off_price ?? 0) : null;
            case 'battle':
                $battle = Battle::find($itemId);
                return $battle ? (float) ($battle->one_off_price ?? 0) : null;
            case 'tournament':
                $tournament = Tournament::find($itemId);
                return $tournament ? (float) ($tournament->entry_fee ?? 0) : null;
            default:
                return null;
        }
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
