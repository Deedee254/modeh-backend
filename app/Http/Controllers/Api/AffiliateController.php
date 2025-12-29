<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\AffiliatePayout;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AffiliateController extends Controller
{
    public function me(Request $request)
    {
        $user = $request->user();
        if (!$user) return response()->json(null, 401);

        $affiliate = $user->affiliate()->first();

        // Return a consistent shape when no affiliate exists so frontends don't get `null`.
        // Frontend expects at least the referral_code attribute; return it as null if absent.
        if (!$affiliate) {
            return response()->json(['referral_code' => null], 200);
        }

        return response()->json($affiliate);
    }

    public function stats(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $affiliate = $user->affiliate()->first();
        if (!$affiliate) {
            return response()->json([
                'totalEarned' => 0,
                'pendingPayouts' => 0,
                'activeReferrals' => 0,
                'conversionRate' => 0
            ]);
        }

        // Get affiliate stats
        $totalEarned = $affiliate->total_earnings ?? 0;
        $pendingPayouts = $affiliate->pending_payouts ?? 0;
        $activeReferrals = $affiliate->referrals()->where('status', 'active')->count();
        $totalReferrals = $affiliate->referrals()->count();
        $conversionRate = $totalReferrals > 0 ? ($activeReferrals / $totalReferrals) * 100 : 0;

        return response()->json([
            'totalEarned' => $totalEarned,
            'pendingPayouts' => $pendingPayouts,
            'activeReferrals' => $activeReferrals,
            'conversionRate' => round($conversionRate, 2)
        ]);
    }

    public function referrals(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $affiliate = $user->affiliate()->first();
        if (!$affiliate) {
            return response()->json([]);
        }

        $referrals = $affiliate->referrals()
            ->with('user:id,name')
            ->latest()
            ->get()
            ->map(function ($referral) {
                return [
                    'id' => $referral->id,
                    'user_name' => $referral->user->name,
                    'type' => $referral->type ?? 'signup',
                    'earnings' => $referral->earnings ?? 0,
                    'status' => $referral->status,
                    'created_at' => $referral->created_at
                ];
            });

        return response()->json($referrals);
    }

    public function generateCode(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Check if user already has an affiliate record
        $affiliate = $user->affiliate()->first();
        if ($affiliate && $affiliate->referral_code) {
            return response()->json([
                'message' => 'Affiliate code already exists',
                'referral_code' => $affiliate->referral_code
            ]);
        }

        // Generate a unique code
        $code = strtoupper(Str::random(8));
        while (Affiliate::where('referral_code', $code)->exists()) {
            $code = strtoupper(Str::random(8));
        }

        // Create or update affiliate record
        if (!$affiliate) {
            $affiliate = new Affiliate();
            $affiliate->fill([
                'referral_code' => $code,
                'commission_rate' => 10.00, // Default 10% commission
                'status' => 'active'
            ]);
            $user->affiliate()->save($affiliate);
        } else {
            $affiliate->update([
                'referral_code' => $code,
                'status' => 'active'
            ]);
        }

        return response()->json([
            'message' => 'Affiliate code generated successfully',
            'referral_code' => $code
        ]);
    }

    public function payoutRequest(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $affiliate = $user->affiliate()->first();
        if (!$affiliate) {
            return response()->json(['error' => 'User has no affiliate account'], 404);
        }

        // Validate minimum payout threshold (1000 KES)
        $totalEarned = $affiliate->total_earnings ?? 0;
        if ($totalEarned < 1000) {
            return response()->json([
                'error' => 'Minimum payout threshold is 1000 KES',
                'current_earnings' => $totalEarned,
                'required' => 1000
            ], 422);
        }

        // Check if there's already a pending payout request
        $existingPending = AffiliatePayout::where('affiliate_id', $affiliate->id)
            ->where('status', 'pending')
            ->first();

        if ($existingPending) {
            return response()->json([
                'error' => 'You have a pending payout request already',
                'payout_id' => $existingPending->id
            ], 422);
        }

        // Create new payout request
        $payout = AffiliatePayout::create([
            'affiliate_id' => $affiliate->id,
            'amount' => $totalEarned,
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payout request submitted successfully',
            'payout' => [
                'id' => $payout->id,
                'amount' => $payout->amount,
                'status' => $payout->status,
                'created_at' => $payout->created_at
            ]
        ]);
    }

    /**
     * Send an affiliate invitation email to a given address.
     * The authenticated user must have an affiliate code (generate if missing).
     */
    public function sendInvite(Request $request)
    {
        $user = $request->user();
        if (! $user) return response()->json(['error' => 'Unauthorized'], 401);

        $data = $request->validate([
            'email' => 'required|email'
        ]);

        // Ensure affiliate code exists (reuse generateCode logic)
        $affiliate = $user->affiliate()->first();
        if (! $affiliate || ! $affiliate->referral_code) {
            // generate a code
            $code = strtoupper(Str::random(8));
            while (\App\Models\Affiliate::where('referral_code', $code)->exists()) {
                $code = strtoupper(Str::random(8));
            }
            if (! $affiliate) {
                $affiliate = new \App\Models\Affiliate();
                $affiliate->fill([
                    'referral_code' => $code,
                    'commission_rate' => 10.00,
                    'status' => 'active'
                ]);
                $user->affiliate()->save($affiliate);
            } else {
                $affiliate->update(['referral_code' => $code, 'status' => 'active']);
            }
        }

        // Send email
        try {
            \Mail::to($data['email'])->send(new \App\Mail\AffiliateInvitationEmail($user, $data['email'], $affiliate->referral_code));
        } catch (\Throwable $e) {
            \Log::error('Failed to send affiliate invitation', ['to' => $data['email'], 'error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'message' => 'Failed to send email'], 500);
        }

        return response()->json(['ok' => true, 'message' => 'Affiliate invite sent', 'referral_code' => $affiliate->referral_code]);
    }
}
