<?php

namespace App\Http\Controllers\Api\Checkout;

use App\Http\Controllers\Controller;
use App\Models\PromoCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PromoController extends Controller
{
    public function validateCode(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
            'amount' => 'required|numeric|min:0'
        ]);

        $promo = PromoCode::where('code', $request->code)->first();

        if (!$promo) {
            return response()->json(['message' => 'Invalid promo code'], 404);
        }

        if (!$promo->isValid()) {
            return response()->json(['message' => 'Promo code is expired or reached its usage limit'], 400);
        }

        // Check user limit if a user is logged in
        $userId = Auth::id();
        if ($userId && $promo->max_uses_per_user !== null) {
            $userUses = $promo->usages()->where('user_id', $userId)->count();
            if ($userUses >= $promo->max_uses_per_user) {
                return response()->json(['message' => 'You have reached the maximum usage limit for this promo code'], 400);
            }
        }

        $discount = $promo->calculateDiscount($request->amount);
        $finalAmount = max(0, $request->amount - $discount);

        return response()->json([
            'valid' => true,
            'code' => $promo->code,
            'discount_type' => $promo->discount_type,
            'discount_amount' => $promo->discount_amount,
            'calculated_discount' => $discount,
            'final_amount' => $finalAmount
        ]);
    }
}
