<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\AffiliatePayout;
use App\Models\AffiliateReferral;
use App\Models\AffiliateLinkClick;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class AffiliateController extends Controller
{
    private function requireAdmin()
    {
        $user = auth()->user();
        if (!$user || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }
        return null;
    }

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

    /**
     * ADMIN METHODS - Get all affiliates with their stats
     */
    public function adminIndex(Request $request)
    {
        if ($resp = $this->requireAdmin()) return $resp;

        try {
            $affiliates = Affiliate::with(['user'])
                ->withCount('referrals')
                ->get()
                ->map(function ($affiliate) {
                    return [
                        'id' => $affiliate->id,
                        'user_id' => $affiliate->user_id,
                        'referral_code' => $affiliate->referral_code,
                        'commission_rate' => $affiliate->commission_rate,
                        'total_earnings' => $affiliate->total_earnings,
                        'status' => $affiliate->status ?? 'active',
                        'referrals_count' => $affiliate->referrals_count,
                        'user' => [
                            'id' => $affiliate->user->id,
                            'name' => $affiliate->user->name,
                            'email' => $affiliate->user->email,
                        ],
                    ];
                });

            return response()->json([
                'ok' => true,
                'data' => $affiliates,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Error fetching affiliates',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ADMIN METHODS - Get all referral transactions
     */
    public function adminReferrals(Request $request)
    {
        if ($resp = $this->requireAdmin()) return $resp;

        try {
            $query = AffiliateReferral::with(['affiliate.user', 'user']);

            // Filter by status if provided
            if ($request->has('status')) {
                $query->where('status', $request->query('status'));
            }

            // Sort by newest first
            $referrals = $query->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($referral) {
                    return [
                        'id' => $referral->id,
                        'affiliate_id' => $referral->affiliate_id,
                        'user_id' => $referral->user_id,
                        'type' => $referral->type ?? 'signup',
                        'earnings' => $referral->earnings,
                        'status' => $referral->status ?? 'active',
                        'created_at' => $referral->created_at,
                        'affiliate' => [
                            'id' => $referral->affiliate->id,
                            'referral_code' => $referral->affiliate->referral_code,
                            'user' => [
                                'id' => $referral->affiliate->user->id,
                                'name' => $referral->affiliate->user->name,
                                'email' => $referral->affiliate->user->email,
                            ],
                        ],
                        'user' => [
                            'id' => $referral->user->id,
                            'name' => $referral->user->name,
                            'email' => $referral->user->email,
                        ],
                    ];
                });

            return response()->json([
                'ok' => true,
                'data' => $referrals,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Error fetching referrals',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ADMIN METHODS - Get link click events
     */
    public function adminClicks(Request $request)
    {
        if ($resp = $this->requireAdmin()) return $resp;

        try {
            $query = AffiliateLinkClick::with(['affiliate.user']);

            // Filter by affiliate if provided
            if ($request->has('affiliate_id')) {
                $query->where('affiliate_id', $request->query('affiliate_id'));
            }

            // Sort by newest first
            $clicks = $query->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($click) {
                    return [
                        'id' => $click->id,
                        'affiliate_id' => $click->affiliate_id,
                        'ip_address' => $click->ip_address,
                        'user_agent' => $click->user_agent,
                        'created_at' => $click->created_at,
                        'affiliate' => [
                            'id' => $click->affiliate->id,
                            'referral_code' => $click->affiliate->referral_code,
                            'user' => [
                                'id' => $click->affiliate->user->id,
                                'name' => $click->affiliate->user->name,
                            ],
                        ],
                    ];
                });

            return response()->json([
                'ok' => true,
                'data' => $clicks,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Error fetching link clicks',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ADMIN METHODS - Get affiliate metrics/stats
     */
    public function adminMetrics(Request $request)
    {
        if ($resp = $this->requireAdmin()) return $resp;

        try {
            $totalAffiliates = Affiliate::count();
            $totalReferrals = AffiliateReferral::count();
            $totalClicks = AffiliateLinkClick::count();
            $totalEarnings = AffiliateReferral::sum('earnings') ?? 0;

            $conversionRate = $totalClicks > 0 
                ? ($totalReferrals / $totalClicks) * 100 
                : 0;

            return response()->json([
                'ok' => true,
                'data' => [
                    'total_affiliates' => $totalAffiliates,
                    'total_referrals' => $totalReferrals,
                    'total_clicks' => $totalClicks,
                    'total_earnings' => $totalEarnings,
                    'conversion_rate' => round($conversionRate, 2),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Error fetching metrics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }



    /**
     * ADMIN METHODS - Update affiliate commission rate (percentage)
     */
    public function updateCommissionRate(Request $request, $affiliateId): \Illuminate\Http\JsonResponse
    {
        if ($resp = $this->requireAdmin()) return $resp;

        $validated = $request->validate([
            'commission_rate' => 'required|numeric|min:0|max:100',
        ]);

        $affiliate = Affiliate::with(['user'])->findOrFail($affiliateId);
        $affiliate->commission_rate = (float) $validated['commission_rate'];
        $affiliate->save();

        return response()->json([
            'ok' => true,
            'data' => [
                'id' => $affiliate->id,
                'user_id' => $affiliate->user_id,
                'referral_code' => $affiliate->referral_code,
                'commission_rate' => (float) $affiliate->commission_rate,
                'total_earnings' => (float) ($affiliate->total_earnings ?? 0),
                'status' => $affiliate->status ?? 'active',
                'user' => $affiliate->user ? [
                    'id' => $affiliate->user->id,
                    'name' => $affiliate->user->name,
                    'email' => $affiliate->user->email,
                ] : null,
            ],
        ]);
    }
}
