<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\OneOffPurchase;
use App\Models\Quiz;
use App\Models\Battle;
use App\Models\Tournament;
use App\Models\MpesaTransaction;
use App\Models\GuestUnlockToken;
use App\Services\MpesaService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OneOffPurchaseController extends Controller
{
    public function store(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['ok' => false, 'message' => 'Unauthenticated'], 401);

        $data = $request->validate([
            'item_type' => 'required|in:quiz,battle,tournament,package',
            'item_id' => 'required',
            'amount' => 'nullable|numeric',
            'phone' => 'nullable|string',
            'gateway' => 'nullable|string',
            'institution_id' => 'nullable',
            'attempt_id' => 'nullable|integer',  // Quiz attempt ID when paying for results
        ]);

        if ($data['item_type'] === 'package') {
            $institutionValidation = $this->resolveInstitutionForPackagePurchase($request, $user);
            if (!$institutionValidation['ok']) {
                return response()->json(['ok' => false, 'message' => $institutionValidation['message']], $institutionValidation['status']);
            }
            $data['institution_id'] = $institutionValidation['institution_id'];
        }

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
            'gateway_meta' => array_filter([
                'phone' => $phone,
                'institution_id' => $data['institution_id'] ?? null,
            ], fn($v) => $v !== null && $v !== ''),
            'meta' => array_filter([
                'attempt_id' => $data['attempt_id'] ?? null,
            ], fn($v) => $v !== null),
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
        try {
            $pricingSetting = \App\Models\PricingSetting::singleton();
        } catch (\Throwable $e) {
            $pricingSetting = null;
        }

        switch ($type) {
            case 'quiz':
                $quiz = Quiz::find($itemId);
                return $quiz ? (float) ($quiz->one_off_price ?? ($pricingSetting->default_quiz_one_off_price ?? 0)) : null;
            case 'battle':
                $battle = Battle::find($itemId);
                return $battle ? (float) ($battle->one_off_price ?? ($pricingSetting->default_battle_one_off_price ?? 0)) : null;
            case 'tournament':
                $tournament = Tournament::find($itemId);
                return $tournament ? (float) ($tournament->entry_fee ?? 0) : null;
            case 'package':
                $package = \App\Models\Package::find($itemId);
                if (!$package || ($package->audience ?? 'quizee') !== 'institution') return null;
                return (float) ($package->price ?? 0);
            default:
                return null;
        }
    }

    private function resolveInstitutionForPackagePurchase(Request $request, $user): array
    {
        $institutionId = $request->input('institution_id');
        $institution = null;

        if ($institutionId) {
            $instQuery = \App\Models\Institution::query();
            if (\ctype_digit(\strval($institutionId))) {
                $instQuery->where('id', (int) $institutionId);
            } else {
                $instQuery->where('slug', $institutionId);
            }
            $institution = $instQuery->first();
        } else {
            $managed = $user->institutions()
                ->wherePivot('role', 'institution-manager')
                ->get(['institutions.id']);
            if ($managed->count() === 1) {
                $institution = \App\Models\Institution::find($managed->first()->id);
            } elseif ($managed->count() > 1) {
                return ['ok' => false, 'status' => 422, 'message' => 'institution_id is required when managing multiple institutions'];
            }
        }

        if (!$institution) {
            return ['ok' => false, 'status' => 404, 'message' => 'Institution not found'];
        }

        $isManager = $institution->users()
            ->where('users.id', $user->id)
            ->wherePivot('role', 'institution-manager')
            ->exists();
        if (!$isManager) {
            return ['ok' => false, 'status' => 403, 'message' => 'Only institution managers can purchase institution packages'];
        }

        return ['ok' => true, 'institution_id' => $institution->id];
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

    /**
     * Guest one-off purchase creation.
     * Allows a guest to pay to unlock a quiz/battle result without authentication.
     */
    public function storeGuest(Request $request)
    {
        $data = $request->validate([
            'item_type' => 'required|in:quiz,battle',
            'item_id' => 'required',
            'amount' => 'nullable|numeric',
            'phone' => 'required|string',
            'gateway' => 'nullable|string',
            'guest_identifier' => 'required|string|min:6',
            'guest_attempt_id' => 'nullable|string',
        ]);

        $resolvedAmount = $this->resolveOneOffAmount($data['item_type'], $data['item_id']);
        if ($resolvedAmount === null || $resolvedAmount <= 0) {
            return response()->json(['ok' => false, 'message' => 'Invalid item or price not configured'], 422);
        }

        if (!empty($data['amount']) && (float) $data['amount'] != (float) $resolvedAmount) {
            Log::warning('[Guest OneOff Purchase] Client amount mismatch', [
                'item_type' => $data['item_type'],
                'item_id' => $data['item_id'],
                'client_amount' => $data['amount'],
                'resolved_amount' => $resolvedAmount,
            ]);
        }

        $phone = trim((string) $data['phone']);
        if ($phone === '') {
            return response()->json(['ok' => false, 'message' => 'Phone number required for mpesa payments'], 422);
        }

        $gateway = $data['gateway'] ?? 'mpesa';
        $purchase = OneOffPurchase::create([
            'user_id' => null,
            'guest_identifier' => $data['guest_identifier'],
            'item_type' => $data['item_type'],
            'item_id' => $data['item_id'],
            'amount' => $resolvedAmount,
            'status' => 'pending',
            'gateway' => $gateway,
            'gateway_meta' => array_filter([
                'phone' => $phone,
            ], fn($v) => $v !== null && $v !== ''),
            'meta' => array_filter([
                'guest_attempt_id' => $data['guest_attempt_id'] ?? null,
            ], fn($v) => $v !== null && $v !== ''),
        ]);

        $service = new MpesaService(config('services.mpesa'));
        $res = $service->initiateStkPush($phone, (float) $purchase->amount, 'GuestOneOff-' . $purchase->id);

        if (!$res['ok']) {
            Log::error('[Guest OneOff Purchase] STK Push failed', [
                'purchase_id' => $purchase->id,
                'error' => $res['message'] ?? 'unknown error',
            ]);
            return response()->json(['ok' => false, 'message' => 'Failed to initiate payment'], 500);
        }

        $checkoutRequestId = $res['tx'];
        $purchase->gateway_meta = array_merge($purchase->gateway_meta ?? [], [
            'tx' => $checkoutRequestId,
            'checkout_request_id' => $checkoutRequestId,
            'initiated_at' => now(),
        ]);
        $purchase->save();

        MpesaTransaction::create([
            'user_id' => null,
            'checkout_request_id' => $checkoutRequestId,
            'merchant_request_id' => $res['body']['MerchantRequestID'] ?? null,
            'amount' => $purchase->amount,
            'phone' => $phone,
            'status' => 'pending',
            'billable_type' => OneOffPurchase::class,
            'billable_id' => $purchase->id,
            'raw_response' => json_encode($res['body'] ?? []),
        ]);

        return response()->json([
            'ok' => true,
            'purchase' => $purchase,
            'tx' => $checkoutRequestId,
            'checkout_request_id' => $checkoutRequestId,
        ]);
    }

    /**
     * Guest polling endpoint for purchase status and unlock token.
     */
    public function guestStatus(Request $request)
    {
        $data = $request->validate([
            'checkout_request_id' => 'required|string',
            'guest_identifier' => 'required|string',
        ]);

        $purchase = OneOffPurchase::where(function ($q) use ($data) {
            $q->where('gateway_meta->tx', $data['checkout_request_id'])
                ->orWhere('gateway_meta->checkout_request_id', $data['checkout_request_id']);
        })
            ->whereNull('user_id')
            ->where('guest_identifier', $data['guest_identifier'])
            ->first();

        if (!$purchase) {
            return response()->json(['ok' => false, 'message' => 'Purchase not found'], 404);
        }

        if ($purchase->status !== 'confirmed') {
            return response()->json([
                'ok' => true,
                'status' => $purchase->status ?: 'pending',
                'purchase_id' => $purchase->id,
            ]);
        }

        $token = GuestUnlockToken::where('purchase_id', $purchase->id)
            ->where('guest_identifier', $data['guest_identifier'])
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (!$token) {
            $token = GuestUnlockToken::create([
                'token' => Str::random(64),
                'guest_identifier' => $data['guest_identifier'],
                'item_type' => $purchase->item_type,
                'item_id' => $purchase->item_id,
                'purchase_id' => $purchase->id,
                'guest_attempt_id' => $purchase->meta['guest_attempt_id'] ?? null,
                'expires_at' => now()->addHours(24),
            ]);
        }

        return response()->json([
            'ok' => true,
            'status' => 'confirmed',
            'purchase_id' => $purchase->id,
            'unlock_token' => $token->token,
            'unlock_expires_at' => optional($token->expires_at)->toIso8601String(),
        ]);
    }
}
