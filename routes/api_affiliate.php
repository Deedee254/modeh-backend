// Affiliate routes
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/affiliates/stats', [AffiliateController::class, 'stats']);
    Route::get('/affiliates/referrals', [AffiliateController::class, 'referrals']);
    Route::post('/affiliates/payout-request', [AffiliateController::class, 'requestPayout']);
});