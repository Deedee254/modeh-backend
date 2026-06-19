<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PromoCode;
use Illuminate\Http\Request;

class PromoCodeController extends Controller
{
    public function index()
    {
        $promoCodes = PromoCode::withCount('usages')->orderBy('created_at', 'desc')->paginate(20);
        return response()->json($promoCodes);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|unique:promo_codes,code',
            'discount_type' => 'required|in:fixed,percentage',
            'discount_amount' => 'required|numeric|min:0',
            'max_uses_overall' => 'nullable|integer|min:1',
            'max_uses_per_user' => 'nullable|integer|min:1',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
            'is_active' => 'boolean',
        ]);

        $promoCode = PromoCode::create($validated);

        return response()->json(['message' => 'Promo code created successfully', 'promo_code' => $promoCode], 201);
    }

    public function show(PromoCode $promoCode)
    {
        return response()->json($promoCode->load('usages.user'));
    }

    public function update(Request $request, PromoCode $promoCode)
    {
        $validated = $request->validate([
            'code' => 'sometimes|string|unique:promo_codes,code,' . $promoCode->id,
            'discount_type' => 'sometimes|in:fixed,percentage',
            'discount_amount' => 'sometimes|numeric|min:0',
            'max_uses_overall' => 'nullable|integer|min:1',
            'max_uses_per_user' => 'nullable|integer|min:1',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
            'is_active' => 'boolean',
        ]);

        $promoCode->update($validated);

        return response()->json(['message' => 'Promo code updated successfully', 'promo_code' => $promoCode]);
    }

    public function destroy(PromoCode $promoCode)
    {
        $promoCode->delete();
        return response()->json(['message' => 'Promo code deleted successfully']);
    }
}
