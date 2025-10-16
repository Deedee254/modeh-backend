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
use Illuminate\Support\Facades\DB;

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
        if (!$sub) {
            // Try one-off purchases
            $purchase = \App\Models\OneOffPurchase::where('gateway_meta->tx', $txId)->first();
            if (!$purchase) {
                return response()->json(['ok' => false, 'message' => 'subscription or purchase not found'], 404);
            }

            // handle one-off purchase
            if ($status === 'success') {
                $purchase->status = 'confirmed';
                $purchase->gateway_meta = array_merge($purchase->gateway_meta ?? [], ['completed_at' => now()]);
                $purchase->save();

                $amount = $purchase->amount ?? 0;
                $setting = PaymentSetting::where('gateway', 'mpesa')->first();
                // platform percentage (e.g. 60 means platform gets 60%)
                $platformSharePct = 60.0;
                if ($setting && $setting->revenue_share !== null) $platformSharePct = (float)$setting->revenue_share;
                // total to give to quiz-masters = amount * (100 - platformPct) / 100
                $totalQuizMasterShare = round(($amount * (100.0 - $platformSharePct)) / 100.0, 2);
                $platformShare = round($amount - $totalQuizMasterShare, 2);

                // Idempotency: do not create duplicate transaction(s) for same tx id
                $existing = \App\Models\Transaction::where('tx_id', $txId)->first();
                if (!$existing) {
                    // If item is a quiz, create a single transaction and credit the quiz master
                    if ($purchase->item_type === 'quiz') {
                        $quizMasterId = null;
                        $quiz = \App\Models\Quiz::find($purchase->item_id);
                        if ($quiz) $quizMasterId = $quiz->user_id ?? null;

                        \App\Models\Transaction::create([
                            'tx_id' => $txId,
                            'user_id' => $purchase->user_id,
                            'quiz_master_id' => $quizMasterId,
                            'quiz_id' => $purchase->item_type === 'quiz' ? $purchase->item_id : null,
                            'amount' => $amount,
                            'quiz_master_share' => $totalQuizMasterShare,
                            'platform_share' => $platformShare,
                            'gateway' => 'mpesa',
                            'meta' => ['one_off' => true, 'item_type' => $purchase->item_type, 'item_id' => $purchase->item_id],
                            'status' => 'confirmed',
                        ]);

                        // Credit quiz-master wallet
                        if ($quizMasterId) {
                            $wrWallet = null;
                            try {
                                DB::transaction(function () use (&$wrWallet, $quizMasterId, $totalQuizMasterShare) {
                                    $wallet = \App\Models\Wallet::where('user_id', $quizMasterId)->lockForUpdate()->first();
                                    if (!$wallet) {
                                        $wallet = \App\Models\Wallet::create(['user_id' => $quizMasterId, 'available' => 0, 'pending' => 0, 'lifetime_earned' => 0]);
                                    }
                                    $wallet->pending = bcadd($wallet->pending, $totalQuizMasterShare, 2);
                                    $wallet->lifetime_earned = bcadd($wallet->lifetime_earned, $totalQuizMasterShare, 2);
                                    $wallet->save();
                                    $wrWallet = $wallet;
                                });
                            } catch (\Throwable $e) {
                                try { Log::warning('Wallet credit for one-off failed: '.$e->getMessage()); } catch (\Throwable $_) {}
                            }
                        }

                    } elseif ($purchase->item_type === 'battle') {
                        // For battles, distribute the quiz-master share across question owners used in the battle
                        $battle = \App\Models\Battle::with('questions')->find($purchase->item_id);
                        $questionOwners = [];
                        $totalQuestions = 0;
                        if ($battle) {
                            $questions = $battle->questions;
                            $totalQuestions = $questions->count();
                            if ($totalQuestions > 0) {
                                // Compute total amount available to quiz-masters (already computed as $totalQuizMasterShare)
                                $perQuestion = $totalQuizMasterShare / max(1, $totalQuestions);
                                // Group per owner
                                foreach ($questions as $q) {
                                    $owner = $q->created_by ?? null;
                                    if (!$owner) continue;
                                    if (!isset($questionOwners[$owner])) $questionOwners[$owner] = 0;
                                    // accumulate per-question share per owner
                                    $questionOwners[$owner] = round($questionOwners[$owner] + $perQuestion, 2);
                                }

                                // Fix rounding errors by adjusting the first owner entry
                                $assigned = array_sum($questionOwners);
                                $diff = round($totalQuizMasterShare - $assigned, 2);
                                if ($diff !== 0.0 && !empty($questionOwners)) {
                                    $firstOwner = array_key_first($questionOwners);
                                    $questionOwners[$firstOwner] = round($questionOwners[$firstOwner] + $diff, 2);
                                }

                                // Create a transaction entry per owner and credit wallets
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
                                        'meta' => ['one_off' => true, 'item_type' => $purchase->item_type, 'item_id' => $purchase->item_id, 'question_owner_breakdown' => array_keys($questionOwners)],
                                        'status' => 'confirmed',
                                    ]);

                                    // credit owner wallet
                                    try {
                                        DB::transaction(function () use (&$wrWallet, $ownerId, $ownerShare) {
                                            $wallet = \App\Models\Wallet::where('user_id', $ownerId)->lockForUpdate()->first();
                                            if (!$wallet) {
                                                $wallet = \App\Models\Wallet::create(['user_id' => $ownerId, 'available' => 0, 'pending' => 0, 'lifetime_earned' => 0]);
                                            }
                                            $wallet->pending = bcadd($wallet->pending, $ownerShare, 2);
                                            $wallet->lifetime_earned = bcadd($wallet->lifetime_earned, $ownerShare, 2);
                                            $wallet->save();
                                        });
                                    } catch (\Throwable $e) {
                                        try { Log::warning('Wallet credit for battle one-off failed: '.$e->getMessage()); } catch (\Throwable $_) {}
                                    }
                                }
                            }
                        }
                    } else {
                        // Unknown item type; create a generic transaction carrying the meta
                        \App\Models\Transaction::create([
                            'tx_id' => $txId,
                            'user_id' => $purchase->user_id,
                            'quiz_master_id' => null,
                            'quiz_id' => null,
                            'amount' => $amount,
                            'quiz_master_share' => $totalQuizMasterShare,
                            'platform_share' => $platformShare,
                            'gateway' => 'mpesa',
                            'meta' => ['one_off' => true, 'item_type' => $purchase->item_type, 'item_id' => $purchase->item_id],
                            'status' => 'confirmed',
                        ]);
                    }
                }

                return response()->json(['ok' => true]);
            }

            $purchase->status = 'cancelled';
            $purchase->save();
            return response()->json(['ok' => false]);
        }

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
            $platformShare = round($amount - $quizMasterShare, 2);

            // Attempt to determine quiz-master_id and quiz_id from meta (allowing unlock-by-quiz flow)
            $meta = $request->input('meta', []);
            if (empty($meta) && is_array($sub->gateway_meta)) $meta = array_merge($meta, $sub->gateway_meta ?? []);
            $quizId = $meta['quiz_id'] ?? null;
            $quizMasterId = $meta['quiz_master_id'] ?? null;

            // If we have a quiz id, try to find its quiz-master
            if (!$quizMasterId && $quizId) {
                $quiz = \App\Models\Quiz::find($quizId);
                if ($quiz) $quizMasterId = $quiz->user_id ?? ($quiz->created_by ?? null);
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
                'quiz_master_id' => $quizMasterId,
                'quiz_id' => $quizId,
                'amount' => $amount,
                'quiz_master_share' => $quizMasterShare,
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

            // Update quiz-master wallet if quiz-master exists (use transaction + row lock to avoid races)
            if ($quizMasterId) {
                $wrWallet = null;
                try {
                    DB::transaction(function () use (&$wrWallet, $quizMasterId, $quizMasterShare) {
                        // lock the wallet row for update to prevent concurrent modifications
                        $wallet = \App\Models\Wallet::where('user_id', $quizMasterId)->lockForUpdate()->first();
                        if (!$wallet) {
                            $wallet = \App\Models\Wallet::create(['user_id' => $quizMasterId, 'available' => 0, 'pending' => 0, 'lifetime_earned' => 0]);
                        }

                        // credit to pending by default; an admin or settlement process will move to available
                        $wallet->pending = bcadd($wallet->pending, $quizMasterShare, 2);
                        // lifetime_earned tracks total earned over time
                        $wallet->lifetime_earned = bcadd($wallet->lifetime_earned, $quizMasterShare, 2);
                        $wallet->save();

                        $wrWallet = $wallet;
                    });
                } catch (\Throwable $e) {
                    try { Log::warning('Wallet credit transaction failed: '.$e->getMessage()); } catch (\Throwable $_) {}
                    // continue without failing the whole webhook - transaction recorded and notification already sent
                }

                // Broadcast wallet update after commit (if we have wallet)
                if ($wrWallet) {
                    try {
                        event(new \App\Events\WalletUpdated($wrWallet->toArray(), $quizMasterId));
                    } catch (\Throwable $e) {
                        try { Log::warning('WalletUpdated broadcast failed: '.$e->getMessage()); } catch (\Throwable $_) {}
                    }
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
