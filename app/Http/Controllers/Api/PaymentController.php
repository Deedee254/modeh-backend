<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Subscription;
use App\Models\PaymentSetting;
use App\Models\Package;
use App\Models\MpesaTransaction;
use App\Models\OneOffPurchase;
use App\Services\MpesaService;
use App\Services\WalletService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function __construct(private WalletService $walletService) {}

    // Initiate an Mpesa STK Push
    public function initiateMpesa(Request $request, Subscription $subscription)
    {
        $user = Auth::user();
        if (!$user || $subscription->user_id !== $user->id) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        $amount = $subscription->package->price ?? 0;
        $phone = $request->phone ?? ($subscription->gateway_meta['phone'] ?? null) ?? ($subscription->user->phone ?? null);

        if (!$phone || !is_string($phone) || trim($phone) === '') {
            return response()->json(['ok' => false, 'message' => 'Phone number required for mpesa payments'], 422);
        }

        // Use MpesaService with config from env
        $service = new MpesaService(config('services.mpesa'));
        $res = $service->initiateStkPush($phone, $amount, 'Subscription-'.$subscription->id);

        if ($res['ok']) {
            $checkoutRequestId = $res['tx'];

            $subscription->update([
                'gateway_meta' => array_merge($subscription->gateway_meta ?? [], [
                    'tx' => $checkoutRequestId,
                    'checkout_request_id' => $checkoutRequestId,
                    'initiated_at' => now(),
                ])
            ]);

            // Create MpesaTransaction record for reconciliation
            MpesaTransaction::create([
                'user_id' => $user->id,
                'checkout_request_id' => $checkoutRequestId,
                'merchant_request_id' => $res['body']['MerchantRequestID'] ?? null,
                'amount' => $amount,
                'phone' => $phone,
                'status' => 'pending',
                'billable_type' => Subscription::class,
                'billable_id' => $subscription->id,
                'raw_response' => json_encode($res['body'] ?? []),
            ]);

            return response()->json([
                'ok' => true,
                'tx' => $checkoutRequestId,
                'checkout_request_id' => $checkoutRequestId,
                'message' => $res['message'] ?? null,
            ]);
        }

        return response()->json(['ok' => false, 'message' => $res['message'] ?? 'failed to initiate payment'], 500);
    }

    // Webhook/callback from Mpesa
    public function mpesaCallback(Request $request)
    {
        $payload = $request->all();

        // Best-effort parsing for Daraja callbacks. Support STK callback structure.
        $txId = $request->input('tx');
        $status = $request->input('status', 'success');
        $resultCode = null;
        $resultDesc = null;

        // If Daraja STK callback structure is present, extract meaningful fields
        if (isset($payload['Body']['stkCallback'])) {
            $stk = $payload['Body']['stkCallback'];
            // prefer CheckoutRequestID (commonly stored as tx) then MerchantRequestID
            $txId = $stk['CheckoutRequestID'] ?? $stk['MerchantRequestID'] ?? $txId;
            $resultCode = $stk['ResultCode'] ?? null;
            $resultDesc = $stk['ResultDesc'] ?? null;
            $status = (isset($stk['ResultCode']) && (int)$stk['ResultCode'] === 0) ? 'success' : 'failed';

            // Extract callback metadata items (Amount, MpesaReceiptNumber/TransactionID, PhoneNumber, TransactionDate)
            $metaItems = $stk['CallbackMetadata']['Item'] ?? [];
            $callbackMeta = [];
            foreach ($metaItems as $item) {
                if (!isset($item['Name'])) continue;
                $name = $item['Name'];
                // Some items may not have a Value (e.g., Balance)
                $value = $item['Value'] ?? null;
                $callbackMeta[$name] = $value;
            }

            // Normalize common names
            $mpesaReceipt = $callbackMeta['MpesaReceiptNumber'] ?? $callbackMeta['TransactionID'] ?? null;
            $amount = $callbackMeta['Amount'] ?? null;
            $phone = $callbackMeta['PhoneNumber'] ?? null;
            $transactionDate = $callbackMeta['TransactionDate'] ?? null;

            // Attach parsed metadata into a top-level key so we can persist later
            $payload['parsed_mpesa'] = [
                'checkout_request_id' => $stk['CheckoutRequestID'] ?? null,
                'merchant_request_id' => $stk['MerchantRequestID'] ?? null,
                'result_code' => $stk['ResultCode'] ?? null,
                'result_desc' => $stk['ResultDesc'] ?? null,
                'mpesa_receipt' => $mpesaReceipt,
                'amount' => $amount,
                'phone' => $phone,
                'transaction_date' => $transactionDate,
                'raw' => $stk,
            ];
        }

        $status = $this->normalizeMpesaStatus($status, $resultCode);

        $checkoutId = $payload['parsed_mpesa']['checkout_request_id'] ?? $txId;
        if (!$checkoutId) {
            return response()->json(['ok' => false, 'message' => 'Missing checkout_request_id'], 400);
        }

        // Log the incoming webhook for auditing (avoid logging sensitive PII)
        Log::info('[Payment] MPESA callback received', [
            'tx' => $checkoutId,
            'status' => $status,
            'request_ip' => $request->ip(),
            'timestamp' => now(),
        ]);

        $mpesaTx = MpesaTransaction::where('checkout_request_id', $checkoutId)->first();
        $sub = null;
        $purchase = null;

        if (!$mpesaTx) {
            // Attempt to find subscription by stored gateway_meta.tx or checkout_request_id (initial purchase)
            $sub = Subscription::where('gateway_meta->tx', $checkoutId)
                ->orWhere('gateway_meta->checkout_request_id', $checkoutId)
                ->first();
            
            // If not found, check for renewal transaction
            if (!$sub) {
                $sub = Subscription::where('gateway_meta->renewal_tx', $checkoutId)->first();
                if ($sub) {
                    // Mark this as a renewal so handler knows to extend dates instead of creating new
                    $request->merge(['is_renewal' => true]);
                }
            }

            if (!$sub) {
                // Try one-off purchases
                $purchase = OneOffPurchase::where('gateway_meta->tx', $checkoutId)
                    ->orWhere('gateway_meta->checkout_request_id', $checkoutId)
                    ->first();
            }

            if ($sub) {
                $mpesaTx = MpesaTransaction::firstOrCreate(
                    ['checkout_request_id' => $checkoutId],
                    [
                        'user_id' => $sub->user_id,
                        'merchant_request_id' => $payload['parsed_mpesa']['merchant_request_id'] ?? null,
                        'amount' => $payload['parsed_mpesa']['amount'] ?? ($sub->package->price ?? null),
                        'phone' => $payload['parsed_mpesa']['phone'] ?? ($sub->gateway_meta['phone'] ?? null),
                        'status' => 'pending',
                        'billable_type' => Subscription::class,
                        'billable_id' => $sub->id,
                        'raw_response' => $payload['parsed_mpesa']['raw'] ?? $payload,
                    ]
                );
                if (!$mpesaTx->billable_type || !$mpesaTx->billable_id) {
                    $mpesaTx->update([
                        'billable_type' => Subscription::class,
                        'billable_id' => $sub->id,
                    ]);
                }
            } elseif ($purchase) {
                $mpesaTx = MpesaTransaction::firstOrCreate(
                    ['checkout_request_id' => $checkoutId],
                    [
                        'user_id' => $purchase->user_id,
                        'merchant_request_id' => $payload['parsed_mpesa']['merchant_request_id'] ?? null,
                        'amount' => $payload['parsed_mpesa']['amount'] ?? $purchase->amount,
                        'phone' => $payload['parsed_mpesa']['phone'] ?? ($purchase->gateway_meta['phone'] ?? null),
                        'status' => 'pending',
                        'billable_type' => OneOffPurchase::class,
                        'billable_id' => $purchase->id,
                        'raw_response' => $payload['parsed_mpesa']['raw'] ?? $payload,
                    ]
                );
                if (!$mpesaTx->billable_type || !$mpesaTx->billable_id) {
                    $mpesaTx->update([
                        'billable_type' => OneOffPurchase::class,
                        'billable_id' => $purchase->id,
                    ]);
                }
            }
        }

        if ($mpesaTx) {
            $parsed = $payload['parsed_mpesa'] ?? [];
            $receipt = $parsed['mpesa_receipt'] ?? null;

            $expectedAmount = null;
            if ($sub) {
                $expectedAmount = (float) ($sub->package->price ?? 0);
            } elseif ($purchase) {
                $expectedAmount = (float) ($purchase->amount ?? 0);
            }

            if ($expectedAmount !== null && $expectedAmount > 0 && isset($parsed['amount'])) {
                $received = (float) $parsed['amount'];
                if ($received > 0 && abs($received - $expectedAmount) > 0.01) {
                    Log::warning('[Payment] Amount mismatch on callback', [
                        'checkout_request_id' => $checkoutId,
                        'expected' => $expectedAmount,
                        'received' => $received,
                    ]);
                    if ($mpesaTx) {
                        $mpesaTx->update([
                            'result_desc' => 'Amount mismatch',
                            'status' => 'failed',
                        ]);
                    }
                    return response()->json(['ok' => false, 'message' => 'Amount mismatch'], 409);
                }
            }

            if (!empty($receipt)) {
                $dup = MpesaTransaction::where('mpesa_receipt', $receipt)
                    ->where('id', '!=', $mpesaTx->id)
                    ->where('status', 'success')
                    ->exists();
                if ($dup) {
                    Log::warning('[Payment] Duplicate M-PESA receipt detected', [
                        'receipt' => $receipt,
                        'checkout_request_id' => $checkoutId,
                    ]);
                    return response()->json(['ok' => false, 'message' => 'Duplicate receipt'], 409);
                }
            }

            $mpesaTx->update([
                'result_code' => $parsed['result_code'] ?? $resultCode,
                'result_desc' => $parsed['result_desc'] ?? $resultDesc,
                'mpesa_receipt' => $receipt,
                'transaction_date' => MpesaTransaction::parseTransactionDate($parsed['transaction_date'] ?? null),
                'raw_response' => $parsed['raw'] ?? $payload,
                'status' => $status === 'success' ? 'success' : ($status === 'cancelled' ? 'cancelled' : 'failed'),
            ]);

            if (!$sub && !$purchase) {
                $billable = $mpesaTx->billable;
                if ($billable instanceof Subscription) {
                    $sub = $billable;
                } elseif ($billable instanceof OneOffPurchase) {
                    $purchase = $billable;
                }
            }
        }

        if (!$sub && !$purchase) {
            Log::warning('[Payment] Callback TX not found in subscriptions or purchases', [
                'tx' => $checkoutId,
                'status' => $status,
            ]);
            return response()->json(['ok' => false, 'message' => 'subscription or purchase not found'], 404);
        }

        if ($purchase) {
            Log::info('[Payment] One-off purchase callback matched', [
                'purchase_id' => $purchase->id,
                'tx' => $checkoutId,
                'status' => $status,
            ]);

            // Persist parsed mpesa details to purchase.gateway_meta if available
            if (!empty($payload['parsed_mpesa'])) {
                $purchase->gateway_meta = array_merge($purchase->gateway_meta ?? [], [
                    'mpesa' => $payload['parsed_mpesa'],
                    'mpesa_tx' => $checkoutId,
                ]);
                $purchase->save();
            }

            // Handle one-off purchase
            return $this->handleOneOffPurchase($purchase, $checkoutId, $status);
        }

        Log::info('[Payment] Subscription callback matched', [
            'subscription_id' => $sub->id,
            'user_id' => $sub->user_id,
            'tx' => $checkoutId,
            'status' => $status,
        ]);

        // Persist parsed mpesa details to subscription.gateway_meta if available
        if (!empty($payload['parsed_mpesa'])) {
            $sub->gateway_meta = array_merge($sub->gateway_meta ?? [], [
                'mpesa' => $payload['parsed_mpesa'],
                'mpesa_tx' => $checkoutId,
                // Backwards compatible top-level tx key
                'tx' => $checkoutId,
                'mpesa_receipt' => $payload['parsed_mpesa']['mpesa_receipt'] ?? null,
                'amount' => $payload['parsed_mpesa']['amount'] ?? null,
                'phone' => $payload['parsed_mpesa']['phone'] ?? null,
            ]);
            $sub->save();
        }

        // Handle subscription payment
        return $this->handleSubscription($sub, $checkoutId, $status, $request);
    }

    /**
     * Handle one-off purchase completion or cancellation.
     */
    private function handleOneOffPurchase(\App\Models\OneOffPurchase $purchase, string $txId, string $status)
    {
        if ($status === 'success') {
            if ($purchase->status === 'confirmed' && \App\Models\Transaction::where('tx_id', $txId)->exists()) {
                return response()->json(['ok' => true, 'skipped' => true]);
            }
        }

        if ($status !== 'success') {
            $purchase->status = 'cancelled';
            $purchase->save();
            return response()->json(['ok' => false]);
        }

        // Mark purchase as confirmed
        $purchase->status = 'confirmed';
        $purchase->gateway_meta = array_merge($purchase->gateway_meta ?? [], ['completed_at' => now()]);
        $purchase->save();

        // Provision institution package subscription when package purchases are confirmed.
        $this->provisionInstitutionPackageFromPurchase($purchase, $txId);

        $amount = $purchase->amount ?? 0;
        $platformSharePct = $this->getPlatformSharePercentage();
        $totalQuizMasterShare = round(($amount * (100.0 - $platformSharePct)) / 100.0, 2);
        $platformShare = round($amount - $totalQuizMasterShare, 2);

        // Prevent duplicate transactions
        if (\App\Models\Transaction::where('tx_id', $txId)->exists()) {
            return response()->json(['ok' => true]);
        }

        // Dispatch based on item type
        match ($purchase->item_type) {
            'quiz' => $this->createTransactionForQuiz($purchase, $txId, $amount, $totalQuizMasterShare, $platformShare),
            'battle' => $this->createTransactionForBattle($purchase, $txId, $amount, $totalQuizMasterShare, $platformShare),
            default => $this->createGenericTransaction($purchase, $txId, $amount, $totalQuizMasterShare, $platformShare),
        };

        // Update tournament participant if applicable
        if ($purchase->item_type === 'tournament') {
            $this->updateTournamentParticipant($purchase);
        }

        // Create invoice for one-off purchase
        try {
            $itemType = ucfirst($purchase->item_type); // 'Quiz', 'Battle', 'Tournament', etc.
            $invoice = \App\Models\Invoice::create([
                'invoice_number' => \App\Models\Invoice::generateInvoiceNumber(),
                'user_id' => $purchase->user_id,
                'invoiceable_type' => \App\Models\OneOffPurchase::class,
                'invoiceable_id' => $purchase->id,
                'amount' => $amount,
                'currency' => 'KES',
                'description' => "{$itemType} Unlock - Item #{$purchase->item_id}",
                'status' => 'paid',
                'paid_at' => now(),
                'payment_method' => 'mpesa',
                'transaction_id' => $txId,
                'meta' => [
                    'item_type' => $purchase->item_type,
                    'item_id' => $purchase->item_id,
                    'gateway_meta' => $purchase->gateway_meta,
                ],
            ]);
            
            // Send email with invoice
            $purchase->user->notify(new \App\Notifications\InvoiceGeneratedNotification($invoice));
            
            Log::info('[Payment] One-off purchase invoice created and email sent', [
                'invoice_id' => $invoice->id,
                'purchase_id' => $purchase->id,
                'user_id' => $purchase->user_id,
                'amount' => $amount,
                'item_type' => $purchase->item_type,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Payment] One-off purchase invoice creation failed', [
                'purchase_id' => $purchase->id,
                'error' => $e->getMessage(),
            ]);
            // Don't fail the payment if invoice generation fails
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Create/refresh an institution subscription when a package one-off purchase succeeds.
     */
    private function provisionInstitutionPackageFromPurchase(\App\Models\OneOffPurchase $purchase, string $txId): void
    {
        if ($purchase->item_type !== 'package') {
            return;
        }

        try {
            $package = Package::find($purchase->item_id);
            if (!$package || ($package->audience ?? 'quizee') !== 'institution') {
                Log::warning('[Payment] Package purchase ignored: invalid institution package', [
                    'purchase_id' => $purchase->id,
                    'package_id' => $purchase->item_id,
                ]);
                return;
            }

            $meta = is_array($purchase->gateway_meta) ? $purchase->gateway_meta : [];
            $institutionId = $meta['institution_id'] ?? null;
            if (!$institutionId) {
                Log::warning('[Payment] Package purchase ignored: missing institution_id', [
                    'purchase_id' => $purchase->id,
                ]);
                return;
            }

            $institution = \App\Models\Institution::find($institutionId);
            if (!$institution) {
                Log::warning('[Payment] Package purchase ignored: institution not found', [
                    'purchase_id' => $purchase->id,
                    'institution_id' => $institutionId,
                ]);
                return;
            }

            $existingProvisioned = Subscription::where('owner_type', \App\Models\Institution::class)
                ->where('owner_id', $institution->id)
                ->where('gateway_meta->purchase_id', $purchase->id)
                ->first();
            if ($existingProvisioned) {
                return;
            }

            Subscription::where('owner_type', \App\Models\Institution::class)
                ->where('owner_id', $institution->id)
                ->where('status', 'active')
                ->update([
                    'status' => 'cancelled',
                    'ends_at' => now(),
                ]);

            Subscription::create([
                'user_id' => $purchase->user_id,
                'owner_type' => \App\Models\Institution::class,
                'owner_id' => $institution->id,
                'package_id' => $package->id,
                'status' => 'active',
                'gateway' => 'one_off_purchase',
                'gateway_meta' => array_merge($meta, [
                    'purchase_id' => $purchase->id,
                    'tx' => $txId,
                    'activated_at' => now()->toDateTimeString(),
                ]),
                'started_at' => now(),
                'ends_at' => $package->duration_days ? now()->addDays($package->duration_days) : null,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Payment] Failed provisioning institution package from purchase', [
                'purchase_id' => $purchase->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle subscription payment completion or cancellation.
     */
    private function handleSubscription(Subscription $sub, string $txId, string $status, Request $request)
    {
        if ($status === 'success') {
            Log::info('[Payment] Subscription payment successful, completing subscription', [
                'subscription_id' => $sub->id,
                'user_id' => $sub->user_id,
                'tx' => $txId,
                'is_renewal' => $request->input('is_renewal', false),
            ]);
            return $this->completeSubscription($sub, $txId, $request);
        }

        // Subscription cancelled or failed
        Log::warning('[Payment] Subscription payment failed/cancelled', [
            'subscription_id' => $sub->id,
            'user_id' => $sub->user_id,
            'tx' => $txId,
            'status' => $status,
        ]);

        $isRenewal = $request->input('is_renewal', false);
        if (!$isRenewal) {
            $gwMeta = is_array($sub->gateway_meta) ? $sub->gateway_meta : [];
            if (($gwMeta['renewal_tx'] ?? null) === $txId) {
                $isRenewal = true;
            }
        }

        if ($isRenewal) {
            $sub->gateway_meta = array_merge($sub->gateway_meta ?? [], [
                'renewal_failed_at' => now(),
                'renewal_failure_status' => $status,
            ]);
            $sub->save();

            Log::warning('[Payment] Renewal payment failed; keeping active subscription', [
                'subscription_id' => $sub->id,
                'tx' => $txId,
                'status' => $status,
            ]);

            return response()->json(['ok' => false, 'renewal_failed' => true]);
        }

        $sub->status = 'cancelled';
        $sub->gateway_meta = array_merge($sub->gateway_meta ?? [], [
            'failed_at' => now(),
            'failure_status' => $status,
        ]);
        $sub->save();

        try {
            $user = $sub->user;
            $user->notify(new \App\Notifications\SubscriptionStatusNotification($sub, 'Subscription cancelled'));
            event(new \App\Events\SubscriptionUpdated($user->id, $sub, $txId));
            
            Log::info('[Payment] Subscription cancellation notification sent', [
                'subscription_id' => $sub->id,
                'user_id' => $user->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Payment] Subscription cancellation notification failed', [
                'subscription_id' => $sub->id,
                'user_id' => $sub->user_id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['ok' => false]);
    }

    /**
     * Complete subscription activation, create transaction, and credit wallet.
     * For renewals, extends the ends_at date instead of creating new dates.
     */
    private function completeSubscription(Subscription $sub, string $txId, Request $request)
    {
        $pkg = $sub->package;
        $days = $pkg->duration_days ?? 30;
        $isRenewal = $request->input('is_renewal', false);

        Log::info('[Payment] Completing subscription activation', [
            'subscription_id' => $sub->id,
            'user_id' => $sub->user_id,
            'package_id' => $pkg->id,
            'tx' => $txId,
            'duration_days' => $days,
            'is_renewal' => $isRenewal,
        ]);

        // Prevent duplicate transaction processing
        if (\App\Models\Transaction::where('tx_id', $txId)->exists()) {
            Log::warning('[Payment] Duplicate transaction attempt prevented', [
                'subscription_id' => $sub->id,
                'tx' => $txId,
            ]);
            return response()->json(['ok' => true, 'skipped' => true]);
        }

        $existingMeta = is_array($sub->gateway_meta) ? $sub->gateway_meta : [];
        $existingTx = $existingMeta['mpesa_tx'] ?? ($existingMeta['tx'] ?? null);
        if ($existingTx && $existingTx === $txId && $sub->status === 'active' && !empty($existingMeta['completed_at'])) {
            return response()->json(['ok' => true, 'skipped' => true]);
        }

        // Mark subscription active
        $sub->status = 'active';
        
        if ($isRenewal) {
            // For renewal: extend existing ends_at date by duration_days
            $sub->ends_at = \Carbon\Carbon::make($sub->ends_at)->addDays($days);
            Log::info('[Renewal] Extending subscription end date', [
                'subscription_id' => $sub->id,
                'new_ends_at' => $sub->ends_at,
            ]);
        } else {
            // For new subscription: set dates now
            $sub->started_at = now();
            $sub->ends_at = now()->addDays($days);
        }
        
        $sub->gateway_meta = array_merge($sub->gateway_meta ?? [], ['completed_at' => now()]);
        $sub->save();

        $amount = $pkg->price ?? 0;
        $platformSharePct = $this->getPlatformSharePercentage();
        $quizMasterPercent = 100.0 - $platformSharePct;
        $quizMasterShare = round(($amount * $quizMasterPercent) / 100.0, 2);
        $platformShare = round($amount - $quizMasterShare, 2);

        // Determine quiz-master and quiz IDs from meta
        $meta = $request->input('meta', []);
        if (empty($meta) && is_array($sub->gateway_meta)) {
            $meta = array_merge($meta, $sub->gateway_meta ?? []);
        }
        $quizId = $meta['quiz_id'] ?? null;
        $quizMasterId = $meta['quiz_master_id'] ?? null;

        if (!$quizMasterId && $quizId) {
            $quiz = \App\Models\Quiz::find($quizId);
            if ($quiz) {
                $quizMasterId = $quiz->user_id ?? ($quiz->created_by ?? null);
            }
        }

        // Create transaction
        $transaction = \App\Models\Transaction::create([
            'tx_id' => $txId,
            'user_id' => $sub->user_id,
            'quiz_master_id' => $quizMasterId,
            'quiz_id' => $quizId,
            'amount' => $amount,
            'quiz_master_share' => $quizMasterShare,
            'platform_share' => $platformShare,
            'gateway' => 'mpesa',
            'meta' => $meta,
            'status' => 'confirmed',
        ]);

        Log::info('[Payment] Transaction created', [
            'transaction_id' => $transaction->id,
            'tx' => $txId,
            'amount' => $amount,
            'quiz_master_share' => $quizMasterShare,
            'platform_share' => $platformShare,
        ]);

        // Notify and broadcast subscription update
        try {
            $user = $sub->user;
            $user->notify(new \App\Notifications\SubscriptionStatusNotification($sub, 'Subscription activated'));
            event(new \App\Events\SubscriptionUpdated($user->id, $sub, $txId));
            
            Log::info('[Payment] Subscription activation notification sent', [
                'subscription_id' => $sub->id,
                'user_id' => $user->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Payment] Subscription activation notification failed', [
                'subscription_id' => $sub->id,
                'user_id' => $sub->user_id,
                'error' => $e->getMessage(),
            ]);
        }

        // Credit quiz-master wallet
        if ($quizMasterId) {
            try {
                $this->creditWallet($quizMasterId, $quizMasterShare);
                Log::info('[Payment] Quiz master wallet credited', [
                    'quiz_master_id' => $quizMasterId,
                    'amount' => $quizMasterShare,
                    'tx' => $txId,
                ]);
            } catch (\Throwable $e) {
                Log::error('[Payment] Quiz master wallet credit failed', [
                    'quiz_master_id' => $quizMasterId,
                    'amount' => $quizMasterShare,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Handle affiliate commission if user was referred (only for new subscriptions, not renewals)
        if (!$isRenewal) {
            $this->handleAffiliateCommission($sub->user_id, $amount, $txId);
        }

        // Create invoice and send email notification
        try {
            $invoiceService = app(\App\Services\InvoiceService::class);
            
            if ($isRenewal) {
                $description = "Renewal: {$pkg->name} - {$days} days";
                Log::info('[Renewal] Creating renewal invoice', [
                    'subscription_id' => $sub->id,
                    'user_id' => $sub->user_id,
                    'description' => $description,
                ]);
            } else {
                $description = "Subscription: {$pkg->name} - {$days} days";
            }
            
            $invoice = $invoiceService->createForSubscription($sub, $description);
            
            // Mark invoice as paid with transaction details
            $invoiceService->markAsPaid($invoice, $txId, 'mpesa');
            
            // Send email with invoice attached
            $sub->user->notify(new \App\Notifications\InvoiceGeneratedNotification($invoice));
            
            Log::info('[Payment] Invoice created and email sent', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'subscription_id' => $sub->id,
                'user_id' => $sub->user_id,
                'is_renewal' => $isRenewal,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Payment] Invoice creation or email failed', [
                'subscription_id' => $sub->id,
                'user_id' => $sub->user_id,
                'is_renewal' => $isRenewal,
                'error' => $e->getMessage(),
            ]);
            // Don't fail the payment if invoice generation fails
        }

        Log::info('[Payment] Subscription activation completed successfully', [
            'subscription_id' => $sub->id,
            'user_id' => $sub->user_id,
            'tx' => $txId,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Create transaction for quiz one-off purchase and credit quiz master.
     */
    private function createTransactionForQuiz(\App\Models\OneOffPurchase $purchase, string $txId, float $amount, float $quizMasterShare, float $platformShare)
    {
        $quizMasterId = null;
        $quiz = \App\Models\Quiz::find($purchase->item_id);
        if ($quiz) {
            $quizMasterId = $quiz->user_id ?? null;
        }

        \App\Models\Transaction::create([
            'tx_id' => $txId,
            'user_id' => $purchase->user_id,
            'quiz_master_id' => $quizMasterId,
            'quiz_id' => $purchase->item_id,
            'amount' => $amount,
            'quiz_master_share' => $quizMasterShare,
            'platform_share' => $platformShare,
            'gateway' => 'mpesa',
            'meta' => ['one_off' => true, 'item_type' => 'quiz', 'item_id' => $purchase->item_id],
            'status' => 'confirmed',
        ]);

        if ($quizMasterId) {
            $this->creditWallet($quizMasterId, $quizMasterShare);
        }

        // Mark quiz attempt as paid if one was specified in the purchase
        if (!empty($purchase->meta['attempt_id'])) {
            $attempt = \App\Models\QuizAttempt::find($purchase->meta['attempt_id']);
            if ($attempt && $attempt->user_id === $purchase->user_id && $attempt->quiz_id === $purchase->item_id) {
                $attempt->update(['paid_for' => true]);
                Log::info('[Payment] Quiz attempt marked as paid', [
                    'attempt_id' => $attempt->id,
                    'user_id' => $purchase->user_id,
                    'quiz_id' => $purchase->item_id,
                    'purchase_id' => $purchase->id,
                ]);
            }
        }

        // Handle affiliate commission if user was referred
        $this->handleAffiliateCommission($purchase->user_id, $amount, $txId);
    }

    /**
     * Create transaction for battle one-off purchase with distributed quiz-master shares.
     */
    private function createTransactionForBattle(\App\Models\OneOffPurchase $purchase, string $txId, float $amount, float $totalQuizMasterShare, float $platformShare)
    {
        $battle = \App\Models\Battle::with('questions')->find($purchase->item_id);
        if (!$battle) {
            return;
        }

        $questionOwners = $this->distributeQuizMasterShare($battle, $totalQuizMasterShare);
        if (empty($questionOwners)) {
            return;
        }

        // Create transaction per owner
        foreach ($questionOwners as $ownerId => $ownerShare) {
            \App\Models\Transaction::create([
                'tx_id' => $txId,
                'user_id' => $purchase->user_id,
                'quiz_master_id' => $ownerId,
                'quiz_id' => null,
                'amount' => $amount,
                'quiz_master_share' => $ownerShare,
                'platform_share' => round($platformShare * ($ownerShare / max(0.0001, $totalQuizMasterShare)), 2),
                'gateway' => 'mpesa',
                'meta' => ['one_off' => true, 'item_type' => 'battle', 'item_id' => $purchase->item_id, 'question_owner_breakdown' => array_keys($questionOwners)],
                'status' => 'confirmed',
            ]);

            $this->creditWallet($ownerId, $ownerShare);
        }

        // Handle affiliate commission if user was referred
        $this->handleAffiliateCommission($purchase->user_id, $amount, $txId);
    }

    /**
     * Create generic transaction for unknown item types.
     */
    private function createGenericTransaction(\App\Models\OneOffPurchase $purchase, string $txId, float $amount, float $quizMasterShare, float $platformShare)
    {
        \App\Models\Transaction::create([
            'tx_id' => $txId,
            'user_id' => $purchase->user_id,
            'quiz_master_id' => null,
            'quiz_id' => null,
            'amount' => $amount,
            'quiz_master_share' => $quizMasterShare,
            'platform_share' => $platformShare,
            'gateway' => 'mpesa',
            'meta' => ['one_off' => true, 'item_type' => $purchase->item_type, 'item_id' => $purchase->item_id],
            'status' => 'confirmed',
        ]);

        // Handle affiliate commission if user was referred
        $this->handleAffiliateCommission($purchase->user_id, $amount, $txId);
    }

    /**
     * Distribute quiz-master share across question owners for a battle.
     * Returns array of [owner_id => share_amount].
     */
    private function distributeQuizMasterShare(\App\Models\Battle $battle, float $totalShare): array
    {
        $questions = $battle->questions;
        if ($questions->isEmpty()) {
            return [];
        }

        $questionOwners = [];
        $perQuestion = $totalShare / $questions->count();

        foreach ($questions as $question) {
            $owner = $question->created_by ?? null;
            if (!$owner) {
                continue;
            }

            if (!isset($questionOwners[$owner])) {
                $questionOwners[$owner] = 0;
            }
            $questionOwners[$owner] = round($questionOwners[$owner] + $perQuestion, 2);
        }

        // Fix rounding errors
        if (!empty($questionOwners)) {
            $assigned = array_sum($questionOwners);
            $diff = round($totalShare - $assigned, 2);
            if ($diff !== 0.0) {
                $firstOwner = array_key_first($questionOwners);
                $questionOwners[$firstOwner] = round($questionOwners[$firstOwner] + $diff, 2);
            }
        }

        return $questionOwners;
    }

    /**
     * Credit a user's wallet with the given amount.
     * Delegates to WalletService for consistency.
     */
    private function creditWallet(int $userId, float $amount)
    {
        $this->walletService->credit($userId, $amount);
    }

    /**
     * Handle affiliate commission for a user purchase.
     * Checks if user was referred by an affiliate and pays commission.
     */
    private function handleAffiliateCommission(int $userId, float $amount, string $txId)
    {
        try {
            // Find active referral for this user
            $referral = \App\Models\AffiliateReferral::where('user_id', $userId)
                ->where('status', 'active')
                ->first();

            if (!$referral) {
                return;
            }

            $affiliate = $referral->affiliate;
            if (!$affiliate || !$affiliate->isActive()) {
                return;
            }

            // Calculate commission
            $commissionAmount = round($amount * ($affiliate->commission_rate / 100.0), 2);
            if ($commissionAmount <= 0) {
                return;
            }

            // Prevent duplicate commission transactions
            if (\App\Models\Transaction::where('tx_id', 'aff_' . $txId)->exists()) {
                return;
            }

            // Create commission transaction
            \App\Models\Transaction::create([
                'tx_id' => 'aff_' . $txId,
                'user_id' => $affiliate->user_id, // Commission goes to affiliate owner
                'quiz_master_id' => null,
                'quiz_id' => null,
                'amount' => $amount,
                'quiz_master_share' => $commissionAmount,
                'platform_share' => 0,
                'gateway' => 'mpesa',
                'meta' => [
                    'commission_type' => 'affiliate',
                    'referred_user_id' => $userId,
                    'referral_id' => $referral->id,
                    'affiliate_id' => $affiliate->id,
                    'commission_rate' => $affiliate->commission_rate,
                ],
                'status' => 'confirmed',
            ]);

            // Credit affiliate wallet
            $this->creditWallet($affiliate->user_id, $commissionAmount);

            // Update referral earnings
            $referral->increment('earnings', $commissionAmount);

            // Update affiliate total earnings
            $affiliate->increment('total_earnings', $commissionAmount);

            Log::info('Affiliate commission processed', [
                'affiliate_id' => $affiliate->id,
                'affiliate_user_id' => $affiliate->user_id,
                'referred_user_id' => $userId,
                'original_amount' => $amount,
                'commission_rate' => $affiliate->commission_rate,
                'commission_amount' => $commissionAmount,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to process affiliate commission', [
                'user_id' => $userId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update tournament participant status after payment confirmation.
     */
    private function updateTournamentParticipant(\App\Models\OneOffPurchase $purchase)
    {
        try {
            DB::table('tournament_participants')
                ->where('tournament_id', $purchase->item_id)
                ->where('user_id', $purchase->user_id)
                ->where('status', 'pending_payment')
                ->update([
                    'status' => 'paid',
                    'approved_at' => now(), // repurposed as paid_at
                    'updated_at' => now(),
                ]);

            // Notify user
            $user = \App\Models\User::find($purchase->user_id);
            if ($user) {
                $tournament = \App\Models\Tournament::find($purchase->item_id);
                if ($tournament) {
                    $user->notify(new \App\Notifications\TournamentRegistrationStatusChanged($tournament, 'paid'));
                }
            }
        } catch (\Throwable $e) {
            try { Log::warning('Failed to promote tournament participant: ' . $e->getMessage()); } catch (\Throwable $_) {}
        }
    }

    /**
     * Get platform revenue share percentage from payment settings.
     */
    private function getPlatformSharePercentage(): float
    {
        $setting = PaymentSetting::where('gateway', 'mpesa')->first();
        if ($setting && $setting->revenue_share !== null) {
            return (float) $setting->revenue_share;
        }
        return 60.0; // default: 60% to platform
    }

    /**
     * Normalize M-PESA status for internal processing.
     */
    private function normalizeMpesaStatus(string $status, $resultCode = null): string
    {
        $s = strtolower(trim($status));
        if ($resultCode !== null && $resultCode !== '') {
            return ((int) $resultCode === 0) ? 'success' : 'failed';
        }
        if (in_array($s, ['success', 'succeeded', 'ok'])) return 'success';
        if (in_array($s, ['cancelled', 'canceled'])) return 'cancelled';
        if (in_array($s, ['failed', 'failure', 'error'])) return 'failed';
        return $s ?: 'failed';
    }

    /**
     * Process a reconciled MpesaTransaction and update its billable.
     */
    public function processMpesaBillable(MpesaTransaction $transaction, string $status, array $payload = [])
    {
        $status = $this->normalizeMpesaStatus($status, $transaction->result_code ?? null);
        $txId = $transaction->checkout_request_id;
        $request = new Request($payload);

        $billable = $transaction->billable;
        if ($billable instanceof Subscription) {
            return $this->handleSubscription($billable, $txId, $status, $request);
        }
        if ($billable instanceof OneOffPurchase) {
            return $this->handleOneOffPurchase($billable, $txId, $status);
        }

        return response()->json(['ok' => false, 'message' => 'Unsupported billable type'], 422);
    }


    private function expectedAmountForBillable($billable): ?float
    {
        if ($billable instanceof Subscription) {
            return (float) ($billable->package->price ?? 0);
        }
        if ($billable instanceof OneOffPurchase) {
            return (float) ($billable->amount ?? 0);
        }
        return null;
    }

}
