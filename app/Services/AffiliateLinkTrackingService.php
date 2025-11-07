<?php

namespace App\Services;

use App\Models\AffiliateLinkClick;
use Illuminate\Http\Request;

class AffiliateLinkTrackingService
{
    public function trackClick(Request $request, string $affiliateCode, string $targetUrl): void
    {
        AffiliateLinkClick::create([
            'user_id' => $request->user()?->id,
            'affiliate_code' => $affiliateCode,
            'source_url' => $request->header('referer'),
            'target_url' => $targetUrl,
            'utm_source' => $request->query('utm_source'),
            'utm_medium' => $request->query('utm_medium'),
            'utm_campaign' => $request->query('utm_campaign'),
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip(),
        ]);
    }

    public function markAsConverted(string $affiliateCode): void
    {
        // Find the most recent click for this affiliate code that hasn't converted yet
        AffiliateLinkClick::where('affiliate_code', $affiliateCode)
            ->whereNull('converted_at')
            ->latest()
            ->first()
            ?->update(['converted_at' => now()]);
    }
}