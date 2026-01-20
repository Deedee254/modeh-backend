<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Subscription;
use App\Models\PaymentSetting;
use App\Models\Package;
use App\Services\MpesaService;
use App\Services\WalletService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function __construct(private WalletService $walletService) {}

    // Simulate initiating an Mpesa STK Push
    public function initiateMpesa(Request $request, Subscription $subscription)
    {
        $user = Auth::user();
        if (!$user || $subscription->user_id !== $user->id) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        // Use MpesaService with config from env
        $service = new MpesaService(config('services.mpesa'));
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
        Log::info('[Payment] MPESA callback received', [
            'tx' => $txId,
            'status' => $status,
            'request_ip' => $request->ip(),
            'timestamp' => now(),
        ]);

        // Attempt to find subscription by stored gateway_meta.tx
        $sub = Subscription::where('gateway_meta->tx', $txId)->first();
        if (!$sub) {
            // Try one-off purchases
            $purchase = \App\Models\OneOffPurchase::where('gateway_meta->tx', $txId)->first();
            if (!$purchase) {
                Log::warning('[Payment] Callback TX not found in subscriptions or purchases', [
                    'tx' => $txId,
                    'status' => $status,
                ]);
                return response()->json(['ok' => false, 'message' => 'subscription or purchase not found'], 404);
            }

            Log::info('[Payment] One-off purchase callback matched', [
                'purchase_id' => $purchase->id,
                'tx' => $txId,
                'status' => $status,
            ]);

            // Handle one-off purchase
            return $this->handleOneOffPurchase($purchase, $txId, $status);
        }

        Log::info('[Payment] Subscription callback matched', [
            'subscription_id' => $sub->id,
            'user_id' => $sub->user_id,
            'tx' => $txId,
            'status' => $status,
        ]);

        // Handle subscription payment
        return $this->handleSubscription($sub, $txId, $status, $request);
    }

    /**
     * Handle one-off purchase completion or cancellation.
     */
    private function handleOneOffPurchase(\App\Models\OneOffPurchase $purchase, string $txId, string $status)
    {
        if ($status !== 'success') {
            $purchase->status = 'cancelled';
            $purchase->save();
            return response()->json(['ok' => false]);
        }

        // Mark purchase as confirmed
        $purchase->status = 'confirmed';
        $purchase->gateway_meta = array_merge($purchase->gateway_meta ?? [], ['completed_at' => now()]);
        $purchase->save();

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

        return response()->json(['ok' => true]);
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
     */
    private function completeSubscription(Subscription $sub, string $txId, Request $request)
    {
        $pkg = $sub->package;
        $days = $pkg->duration_days ?? 30;

        Log::info('[Payment] Completing subscription activation', [
            'subscription_id' => $sub->id,
            'user_id' => $sub->user_id,
            'package_id' => $pkg->id,
            'tx' => $txId,
            'duration_days' => $days,
        ]);

        // Mark subscription active
        $sub->status = 'active';
        $sub->started_at = now();
        $sub->ends_at = now()->addDays($days);
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

        // Prevent duplicate transactions
        if (\App\Models\Transaction::where('tx_id', $txId)->exists()) {
            Log::warning('[Payment] Duplicate transaction attempt prevented', [
                'subscription_id' => $sub->id,
                'tx' => $txId,
            ]);
            return response()->json(['ok' => true, 'skipped' => true]);
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

        // Handle affiliate commission if user was referred
        $this->handleAffiliateCommission($sub->user_id, $amount, $txId);

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
}
