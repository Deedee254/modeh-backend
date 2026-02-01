<?php

namespace App\Jobs;

use App\Models\MpesaTransaction;
use App\Services\MpesaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconsileMpesaTransaction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $transactionId;
    protected $mpesaService;

    public function __construct(int $transactionId)
    {
        $this->transactionId = $transactionId;
    }

    /**
     * Execute the job: query Daraja and reconcile the transaction.
     */
    public function handle(MpesaService $mpesaService): void
    {
        $transaction = MpesaTransaction::find($this->transactionId);

        if (!$transaction) {
            Log::warning('[MPESA Job] Transaction not found', ['transaction_id' => $this->transactionId]);
            return;
        }

        // Skip if already final
        if (in_array($transaction->status, ['success', 'failed', 'cancelled'])) {
            Log::info('[MPESA Job] Transaction already final, skipping', [
                'transaction_id' => $transaction->id,
                'status' => $transaction->status,
            ]);
            return;
        }

        // Max 5 retries
        if ($transaction->retry_count >= 5) {
            Log::warning('[MPESA Job] Max retries exceeded, marking failed', ['transaction_id' => $transaction->id]);
            $transaction->markFailed(null, 'Max retries exceeded without resolution');
            return;
        }

        // Query Daraja
        $queryResult = $mpesaService->queryStkPush($transaction->checkout_request_id);

        if (!$queryResult['ok']) {
            Log::warning('[MPESA Job] Query failed, will retry', [
                'transaction_id' => $transaction->id,
                'error' => $queryResult['message'],
            ]);
            $transaction->scheduleRetry();
            return;
        }

        // Process result
        try {
            DB::beginTransaction();

            $transaction->update([
                'raw_response' => $queryResult['raw'] ?? null,
                'result_code' => $queryResult['result_code'],
                'result_desc' => $queryResult['result_desc'],
            ]);

            $newStatus = $queryResult['status'];

            if ($newStatus === 'success') {
                // Check for duplicate receipt
                $existingReceipt = !empty($queryResult['mpesa_receipt'])
                    ? MpesaTransaction::where('mpesa_receipt', $queryResult['mpesa_receipt'])
                        ->where('id', '!=', $transaction->id)
                        ->where('status', 'success')
                        ->exists()
                    : false;

                if (!$existingReceipt) {
                    $transaction->markSuccess(
                        $queryResult['mpesa_receipt'],
                        $queryResult['result_desc']
                    );

                    // Process billable
                    if ($transaction->billable && method_exists($transaction->billable, 'markActive')) {
                        $transaction->billable->markActive();
                    }

                    Log::info('[MPESA Job] Transaction reconciled as success', [
                        'transaction_id' => $transaction->id,
                        'receipt' => $queryResult['mpesa_receipt'],
                    ]);
                } else {
                    Log::warning('[MPESA Job] Duplicate receipt detected', [
                        'transaction_id' => $transaction->id,
                        'receipt' => $queryResult['mpesa_receipt'],
                    ]);
                }
            } elseif (in_array($newStatus, ['failed', 'cancelled'])) {
                $transaction->markFailed(
                    $queryResult['result_code'],
                    $queryResult['result_desc']
                );
                Log::warning('[MPESA Job] Transaction marked failed', [
                    'transaction_id' => $transaction->id,
                    'result_code' => $queryResult['result_code'],
                ]);
            } else {
                // Still pending
                $transaction->scheduleRetry();
                Log::info('[MPESA Job] Still pending, scheduled retry', [
                    'transaction_id' => $transaction->id,
                    'next_retry_at' => $transaction->next_retry_at,
                ]);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[MPESA Job] Exception during reconciliation', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);
            $transaction->scheduleRetry();
        }
    }
}
