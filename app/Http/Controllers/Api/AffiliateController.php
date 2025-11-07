<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AffiliateEarning;
use App\Models\AffiliatePayout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AffiliateController extends Controller
{
    public function stats()
    {
        $user = auth()->user();
        
        $stats = [
            'totalEarned' => AffiliateEarning::where('user_id', $user->id)
                ->where('status', 'completed')
                ->sum('amount'),
                
            'pendingPayouts' => AffiliatePayout::where('user_id', $user->id)
                ->whereIn('status', ['pending', 'processing'])
                ->sum('amount'),
                
            'activeReferrals' => AffiliateEarning::where('user_id', $user->id)
                ->distinct('referred_user_id')
                ->count(),
                
            'conversionRate' => $this->calculateConversionRate($user->id)
        ];

        return response()->json($stats);
    }

    public function referrals()
    {
        $user = auth()->user();
        
        $referrals = AffiliateEarning::with('referredUser')
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return response()->json($referrals);
    }

    public function requestPayout()
    {
        $user = auth()->user();
        
        // Calculate available earnings
        $totalEarned = AffiliateEarning::where('user_id', $user->id)
            ->where('status', 'completed')
            ->sum('amount');
            
        $totalPaidOut = AffiliatePayout::where('user_id', $user->id)
            ->whereIn('status', ['completed', 'pending', 'processing'])
            ->sum('amount');
            
        $availableForPayout = $totalEarned - $totalPaidOut;

        // Minimum payout amount (1000 KES)
        if ($availableForPayout < 1000) {
            return response()->json([
                'success' => false,
                'message' => 'Minimum payout amount is 1000 KES'
            ], 400);
        }

        // Create payout request
        $payout = AffiliatePayout::create([
            'user_id' => $user->id,
            'amount' => $availableForPayout,
            'status' => 'pending',
            'payment_method' => 'mpesa', // Default to M-Pesa
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payout request submitted successfully',
            'payout' => $payout
        ]);
    }

    private function calculateConversionRate($userId)
    {
        $totalReferrals = DB::table('users')
            ->where('referred_by', $userId)
            ->count();
            
        if ($totalReferrals === 0) {
            return 0;
        }

        $convertedReferrals = AffiliateEarning::where('user_id', $userId)
            ->where('status', 'completed')
            ->distinct('referred_user_id')
            ->count();

        return round(($convertedReferrals / $totalReferrals) * 100, 2);
    }
}