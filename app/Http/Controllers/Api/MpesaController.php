<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MpesaTransaction;
use App\Services\MpesaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MpesaController extends Controller
{
    protected $mpesaService;

    public function __construct(MpesaService $mpesaService)
    {
        $this->mpesaService = $mpesaService;
    }

    /**
     * Reconcile a pending M-PESA transaction by querying Daraja for current status.
     * Called manually by user/admin or by background job for retry.
     * 
     * POST /api/mpesa/reconcile
     * Body: { "checkout_request_id": "...", "source": "user|admin|worker" }
     * 
     * Returns normalized transaction state and processes idempotently.
     */
    public function reconcile(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'checkout_request_id' => 'required|string',
            'source' => 'nullable|in:user,admin,worker',
        ]);

        $checkoutId = $validated['checkout_request_id'];
        $source = $validated['source'] ?? 'user';

        Log::info('[MPESA] Reconcile requested', [
            'user_id' => $user->id,
            'checkout_request_id' => $checkoutId,
            'source' => $source,
        ]);

        // Find the transaction
        $transaction = MpesaTransaction::where('checkout_request_id', $checkoutId)
            ->where('user_id', $user->id)
            ->first();

        if (!$transaction) {
            return response()->json([
                'ok' => false,
                'message' => 'Transaction not found',
                'checkout_request_id' => $checkoutId,
            ], 404);
        }
        $traceId = $this->extractTraceId($transaction);

        // If already reconciled (success/failed), return current state (idempotent)
        if (in_array($transaction->status, ['success', 'failed', 'cancelled'])) {
            Log::info('[MPESA] Reconcile: transaction already final', [
                'trace_id' => $traceId,
                'user_id' => $user->id,
                'checkout_request_id' => $checkoutId,
                'status' => $transaction->status,
            ]);

            return response()->json([
                'ok' => true,
                'message' => 'Transaction already reconciled',
                'status' => $transaction->status,
                'transaction' => $this->formatTransaction($transaction),
            ]);
        }

        // Query Daraja for current status
        $queryResult = $this->mpesaService->queryStkPush($checkoutId);

        Log::info('[MPESA] Reconcile query response', [
            'trace_id' => $traceId,
            'user_id' => $user->id,
            'checkout_request_id' => $checkoutId,
            'ok' => (bool) ($queryResult['ok'] ?? false),
            'status' => $queryResult['status'] ?? null,
            'result_code' => $queryResult['result_code'] ?? null,
            'result_desc' => $queryResult['result_desc'] ?? null,
            'receipt' => $queryResult['mpesa_receipt'] ?? null,
        ]);

        if (!$queryResult['ok']) {
            Log::warning('[MPESA] Reconcile query failed', [
                'trace_id' => $traceId,
                'user_id' => $user->id,
                'checkout_request_id' => $checkoutId,
                'error' => $queryResult['message'],
            ]);

            // Transient error: schedule retry
            $transaction->scheduleRetry();

            return response()->json([
                'ok' => false,
                'message' => 'Failed to query Daraja; will retry',
                'status' => 'pending',
                'next_retry_at' => $transaction->next_retry_at,
            ]);
        }

        // Process the query result
        try {
            DB::beginTransaction();

            $transaction->update([
                'raw_response' => $queryResult['raw'] ?? null,
                'result_code' => $queryResult['result_code'],
                'result_desc' => $queryResult['result_desc'],
            ]);

            $expectedAmount = null;
            if ($transaction->billable instanceof \App\Models\Subscription) {
                $expectedAmount = (float) ($transaction->billable->package->price ?? 0);
            } elseif ($transaction->billable instanceof \App\Models\OneOffPurchase) {
                $expectedAmount = (float) ($transaction->billable->amount ?? 0);
            }

            if ($expectedAmount !== null && $expectedAmount > 0 && isset($queryResult['amount'])) {
                $received = (float) $queryResult['amount'];
                if ($received > 0 && abs($received - $expectedAmount) > 0.01) {
                    $transaction->markFailed(null, 'Amount mismatch');
                    Log::warning('[MPESA] Reconcile: amount mismatch', [
                        'trace_id' => $traceId,
                        'transaction_id' => $transaction->id,
                        'expected' => $expectedAmount,
                        'received' => $received,
                    ]);

                    DB::commit();
                    $transaction->refresh();
                    if ($transaction->billable) {
                        $this->processBillable($transaction);
                    }

                    return response()->json([
                        'ok' => false,
                        'message' => 'Amount mismatch',
                        'status' => 'failed',
                        'transaction' => $this->formatTransaction($transaction),
                    ], 409);
                }
            }

            $newStatus = $queryResult['status'];

            if ($newStatus === 'success') {
                // Idempotency: check if receipt already processed
                $existingReceipt = !empty($queryResult['mpesa_receipt'])
                    ? MpesaTransaction::where('mpesa_receipt', $queryResult['mpesa_receipt'])
                        ->where('id', '!=', $transaction->id)
                        ->where('status', 'success')
                        ->exists()
                    : false;

                if ($existingReceipt) {
                    Log::warning('[MPESA] Reconcile: duplicate receipt detected', [
                        'trace_id' => $traceId,
                        'receipt' => $queryResult['mpesa_receipt'],
                        'transaction_id' => $transaction->id,
                    ]);

                    DB::rollBack();

                    return response()->json([
                        'ok' => false,
                        'message' => 'Receipt already processed (duplicate)',
                        'status' => 'success',
                    ]);
                }

                // Mark success and process billable (e.g., activate subscription)
                $transaction->markSuccess(
                    $queryResult['mpesa_receipt'],
                    $queryResult['result_desc'],
                    $queryResult['transaction_date'] ?? null
                );

                Log::info('[MPESA] Reconcile: marked success', [
                    'trace_id' => $traceId,
                    'user_id' => $user->id,
                    'transaction_id' => $transaction->id,
                    'receipt' => $queryResult['mpesa_receipt'],
                ]);

            } elseif (in_array($newStatus, ['failed', 'cancelled'])) {
                $transaction->markFailed(
                    $queryResult['result_code'],
                    $queryResult['result_desc']
                );

                Log::warning('[MPESA] Reconcile: marked failed', [
                    'trace_id' => $traceId,
                    'user_id' => $user->id,
                    'transaction_id' => $transaction->id,
                    'result_code' => $queryResult['result_code'],
                ]);

            } else {
                // Still pending
                $transaction->scheduleRetry();
                Log::info('[MPESA] Reconcile: still pending, scheduled retry', [
                    'trace_id' => $traceId,
                    'user_id' => $user->id,
                    'transaction_id' => $transaction->id,
                    'next_retry_at' => $transaction->next_retry_at,
                ]);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[MPESA] Reconcile exception', [
                'trace_id' => $traceId,
                'user_id' => $user->id,
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Reconciliation failed: ' . $e->getMessage(),
            ], 500);
        }

        // Refresh and return normalized response
        $transaction->refresh();

        if (in_array($transaction->status, ['success', 'failed', 'cancelled']) && $transaction->billable) {
            $this->processBillable($transaction);
        }

        return response()->json([
            'ok' => true,
            'status' => $transaction->status,
            'transaction' => $this->formatTransaction($transaction),
        ]);
    }

    /**
     * Process the billable (e.g., activate subscription) after successful payment.
     * Override or extend this for your specific business logic.
     */
    protected function processBillable(MpesaTransaction $transaction): void
    {
        if (!$transaction->billable) {
            return;
        }

        try {
            app(\App\Http\Controllers\Api\PaymentController::class)
                ->processMpesaBillable($transaction, $transaction->status, []);
        } catch (\Throwable $e) {
            Log::error('[MPESA] Failed to process billable after reconciliation', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Format transaction for API response
     * 
     * @param  MpesaTransaction $transaction
     * @property int $id
     * @property string $checkout_request_id
     * @property string $merchant_request_id
     * @property string $status
     * @property float $amount
     * @property string $phone
     * @property ?string $mpesa_receipt
     * @property ?string $result_code
     * @property ?string $result_desc
     * @property int $retry_count
     */
    protected function formatTransaction(MpesaTransaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'trace_id' => $this->extractTraceId($transaction),
            'checkout_request_id' => $transaction->checkout_request_id,
            'merchant_request_id' => $transaction->merchant_request_id,
            'status' => $transaction->status,
            'amount' => $transaction->amount,
            'phone' => $transaction->phone,
            'mpesa_receipt' => $transaction->mpesa_receipt,
            'result_code' => $transaction->result_code,
            'result_desc' => $transaction->result_desc,
            'transaction_date' => $transaction->transaction_date?->toIso8601String(),
            'reconciled_at' => $transaction->reconciled_at?->toIso8601String(),
            'retry_count' => $transaction->retry_count,
            'next_retry_at' => $transaction->next_retry_at?->toIso8601String(),
            'created_at' => $transaction->created_at?->toIso8601String(),
        ];
    }

    protected function extractTraceId(MpesaTransaction $transaction): ?string
    {
        $raw = $transaction->raw_response;
        if (is_array($raw) && !empty($raw['trace_id'])) {
            return (string) $raw['trace_id'];
        }

        $billable = $transaction->billable;
        if ($billable && isset($billable->gateway_meta) && is_array($billable->gateway_meta) && !empty($billable->gateway_meta['trace_id'])) {
            return (string) $billable->gateway_meta['trace_id'];
        }

        return null;
    }
}
