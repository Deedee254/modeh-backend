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
     * ADMIN METHODS - Settle affiliate pending payouts to their wallets
     * Moves earnings from AffiliateReferral into their Wallet account
     */
    public function settlePayouts(): \Illuminate\Http\JsonResponse
    {
        try {
            return DB::transaction(function () {
                $settledCount = 0;
                $settledAmount = 0;

                // Get all active affiliates with pending earnings
                $affiliates = Affiliate::where('status', 'active')->get();

                foreach ($affiliates as $affiliate) {
                    // Calculate total pending earnings from AffiliateReferrals
                    $pendingEarnings = AffiliateReferral::where('affiliate_id', $affiliate->id)
                        ->where('status', 'active')
                        ->sum('earnings') ?? 0;

                    if ($pendingEarnings <= 0) {
                        continue;
                    }

                    // Get or create affiliate's wallet
                    $wallet = Wallet::firstOrCreate(
                        [
                            'user_id' => $affiliate->user_id,
                            'type' => 'affiliate',
                        ],
                        [
                            'available_balance' => 0,
                            'pending_balance' => 0,
                        ]
                    );

                    // Move earnings to pending wallet balance
                    $wallet->increment('pending_balance', $pendingEarnings);

                    // Mark all referral earnings as settled
                    AffiliateReferral::where('affiliate_id', $affiliate->id)
                        ->where('status', 'active')
                        ->update([
                            'status' => 'settled',
                            'updated_at' => now(),
                        ]);

                    // Log the settlement transaction
                    \App\Models\Transaction::create([
                        'user_id' => $affiliate->user_id,
                        'type' => 'affiliate_settlement',
                        'amount' => $pendingEarnings,
                        'status' => 'completed',
                        'description' => 'Affiliate referral earnings settlement',
                        'metadata' => [
                            'affiliate_id' => $affiliate->id,
                            'referral_code' => $affiliate->referral_code,
                        ],
                    ]);

                    $settledCount++;
                    $settledAmount += $pendingEarnings;
                }

                return response()->json([
                    'ok' => true,
                    'message' => 'Affiliate payouts settled successfully',
                    'data' => [
                        'affiliates_settled' => $settledCount,
                        'total_amount_settled' => $settledAmount,
                        'timestamp' => now(),
                    ],
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Error settling affiliate payouts',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ADMIN METHODS - Get pending affiliate payouts ready to settle
     */
    public function pendingPayouts(): \Illuminate\Http\JsonResponse
    {
        try {
            $affiliates = Affiliate::where('status', 'active')
                ->with(['user', 'referrals' => function ($query) {
                    $query->where('status', 'active');
                }])
                ->get()
                ->map(function ($affiliate) {
                    $pendingEarnings = $affiliate->referrals->sum('earnings') ?? 0;
                    
                    return [
                        'id' => $affiliate->id,
                        'user_id' => $affiliate->user_id,
                        'name' => $affiliate->user->name,
                        'email' => $affiliate->user->email,
                        'referral_code' => $affiliate->referral_code,
                        'pending_amount' => $pendingEarnings,
                        'total_earnings' => $affiliate->total_earnings,
                        'referrals_count' => count($affiliate->referrals),
                    ];
                })
                ->filter(function ($affiliate) {
                    return $affiliate['pending_amount'] > 0;
                })
                ->values();

            $totalPending = $affiliates->sum('pending_amount');

            return response()->json([
                'ok' => true,
                'data' => [
                    'pending_affiliates' => $affiliates,
                    'count' => count($affiliates),
                    'total_pending_amount' => $totalPending,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Error fetching pending payouts',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ADMIN METHODS - Settle payout for a single affiliate
     */
    public function settleSingleAffiliate($affiliateId)
    {
        try {
            DB::transaction(function () use ($affiliateId) {
                $affiliate = Affiliate::findOrFail($affiliateId);
                
                // Calculate pending earnings from active referrals
                $pendingEarnings = AffiliateReferral::where('affiliate_id', $affiliateId)
                    ->where('status', 'active')
                    ->sum('earnings') ?? 0;

                if ($pendingEarnings <= 0) {
                    throw new \Exception('No pending earnings to settle for this affiliate');
                }

                // Get or create wallet for affiliate user
                $wallet = Wallet::firstOrCreate(
                    ['user_id' => $affiliate->user_id],
                    ['available' => 0, 'pending' => 0, 'lifetime_earned' => 0]
                );

                // Add earnings to wallet pending balance
                $wallet->pending = bcadd($wallet->pending, $pendingEarnings, 2);
                $wallet->save();

                // Mark all referral earnings as settled
                AffiliateReferral::where('affiliate_id', $affiliateId)
                    ->where('status', 'active')
                    ->update([
                        'status' => 'settled',
                        'updated_at' => now(),
                    ]);

                // Log the settlement transaction
                \App\Models\Transaction::create([
                    'user_id' => $affiliate->user_id,
                    'quiz-master_id' => $affiliate->user_id,
                    'amount' => $pendingEarnings,
                    'status' => 'settlement',
                    'meta' => [
                        'type' => 'affiliate_payout',
                        'affiliate_id' => $affiliate->id,
                        'referral_code' => $affiliate->referral_code,
                    ],
                ]);
            });

            return response()->json([
                'ok' => true,
                'message' => 'Affiliate payout settled successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Error settling affiliate payout',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
