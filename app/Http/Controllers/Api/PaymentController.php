<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Subscription;
use App\Models\PaymentSetting;
use App\Models\Package;
use App\Services\MpesaService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    // Simulate initiating an Mpesa STK Push
    public function initiateMpesa(Request $request, Subscription $subscription)
    {
        $user = Auth::user();
        if (!$user || $subscription->user_id !== $user->id) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        // Lookup payment settings
        $setting = PaymentSetting::where('gateway', 'mpesa')->first();
        $config = $setting ? ($setting->config ?? []) : [];

        // Use MpesaService
        $service = new MpesaService($config);
        $amount = $subscription->package->price ?? 0;
        $phone = $request->phone ?? ($subscription->gateway_meta['phone'] ?? null) ?? ($subscription->user->phone ?? null);
        $res = $service->initiateStkPush($phone, $amount, 'Subscription-'.$subscription->id);

        if ($res['ok']) {
            $subscription->update(['gateway_meta' => array_merge($subscription->gateway_meta ?? [], ['tx' => $res['tx'], 'initiated_at' => now()])]);
            return response()->json(['ok' => true, 'tx' => $res['tx'], 'message' => $res['message']]);
        }

        return response()->json(['ok' => false, 'message' => 'failed to initiate payment'], 500);
    }

    // Webhook/callback from Mpesa (simulate by POSTing to this endpoint in tools)
    public function mpesaCallback(Request $request)
    {
        $txId = $request->input('tx');
        $status = $request->input('status', 'success');

        // Log the incoming webhook for auditing (avoid logging sensitive PII)
        try {
            Log::info('Mpesa callback received', ['tx' => $txId, 'status' => $status]);
        } catch (\Throwable $e) {
            // ignore logging failures
        }

        // Attempt to find subscription by stored gateway_meta.tx. Use JSON path lookup or JSON contains depending on DB.
        $sub = Subscription::where('gateway_meta->tx', $txId)->first();
        if (!$sub) return response()->json(['ok' => false, 'message' => 'subscription not found'], 404);

        if ($status === 'success') {
            $pkg = $sub->package;
            $days = $pkg->duration_days ?? 30;

            // mark subscription active
            $sub->status = 'active';
            $sub->started_at = now();
            $sub->ends_at = now()->addDays($days);
            $sub->gateway_meta = array_merge($sub->gateway_meta ?? [], ['completed_at' => now()]);
            $sub->save();

            // Create a transaction record for this payment
            $amount = $pkg->price ?? 0;
            // find payment setting for gateway to get revenue_share
            $setting = PaymentSetting::where('gateway', 'mpesa')->first();
            $platformSharePct = 60.0; // default platform percent
            if ($setting && $setting->revenue_share !== null) {
                // stored revenue_share is percent to platform
                $platformSharePct = (float)$setting->revenue_share;
            }
            $quizMasterPercent = 100.0 - $platformSharePct;
            $quizMasterShare = round(($amount * $quizMasterPercent) / 100.0, 2);
            $platformShare = round($amount - $quiz-masterShare, 2);

            // Attempt to determine quiz-master_id and quiz_id from meta (allowing unlock-by-quiz flow)
            $meta = $request->input('meta', []);
            if (empty($meta) && is_array($sub->gateway_meta)) $meta = array_merge($meta, $sub->gateway_meta ?? []);
            $quizId = $meta['quiz_id'] ?? null;
            $quizMasterId = $meta['quiz-master_id'] ?? null;

            // If we have a quiz id, try to find its quiz-master
            if (!$quizMasterId && $quizId) {
                $quiz = \App\Models\Quiz::find($quizId);
                if ($quiz) $quizMasterId = $quiz->created_by ?? null;
            }

            // Idempotency: do not create duplicate transaction for same tx id
            $existing = \App\Models\Transaction::where('tx_id', $txId)->first();
            if ($existing) {
                // If transaction already exists, assume previously processed
                return response()->json(['ok' => true, 'skipped' => true]);
            }

            // create transaction
            $transaction = \App\Models\Transaction::create([
                'tx_id' => $txId,
                'user_id' => $sub->user_id,
                'quiz-master_id' => $quizMasterId,
                'quiz_id' => $quizId,
                'amount' => $amount,
                'quiz-master_share' => $quizMasterShare,
                'platform_share' => $platformShare,
                'gateway' => 'mpesa',
                'meta' => $meta,
                'status' => 'confirmed',
            ]);

            // Notify user and broadcast subscription update
            try {
                $user = $sub->user;
                // send database + broadcast notification
                $user->notify(new \App\Notifications\SubscriptionStatusNotification($sub, 'Subscription activated'));
                // fire a broadcast event for real-time UI updates
                event(new \App\Events\SubscriptionUpdated($user->id, $sub, $txId));
            } catch (\Throwable $e) {
                try { Log::warning('Subscription notification failed: '.$e->getMessage()); } catch (\Throwable $_) {}
            }

            // Update quiz-master wallet if quiz-master exists
            if ($quizMasterId) {
                $wallet = \App\Models\Wallet::firstOrCreate(['user_id' => $quizMasterId], ['available' => 0, 'pending' => 0, 'lifetime_earned' => 0]);
                $wallet->available = bcadd($wallet->available, $quizMasterShare, 2);
                $wallet->lifetime_earned = bcadd($wallet->lifetime_earned, $quizMasterShare, 2);
                $wallet->save();

                // Broadcast wallet update to quiz-master
                try {
                    event(new \App\Events\WalletUpdated($wallet->toArray(), $quiz-masterId));
                } catch (\Throwable $e) {
                    // don't block webhook on broadcast failures
                    try { Log::warning('WalletUpdated broadcast failed: '.$e->getMessage()); } catch (\Throwable $_) {}
                }
            }

            return response()->json(['ok' => true]);
        }

        // cancelled or failed
        $sub->status = 'cancelled';
        $sub->save();
        try {
            $user = $sub->user;
            $user->notify(new \App\Notifications\SubscriptionStatusNotification($sub, 'Subscription cancelled'));
            event(new \App\Events\SubscriptionUpdated($user->id, $sub, $txId));
        } catch (\Throwable $e) {
            try { Log::warning('Subscription cancellation notification failed: '.$e->getMessage()); } catch (\Throwable $_) {}
        }
        return response()->json(['ok' => false]);
    }
}
