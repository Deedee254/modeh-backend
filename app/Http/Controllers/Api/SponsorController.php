<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sponsor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SponsorController extends Controller
{
    /**
     * Return a list of active sponsors for the homepage carousel.
     * Response shape matches frontend expectations: { sponsors: [...] }
     */
    public function index(Request $request)
    {
        $sponsors = Sponsor::query()
            ->where('is_active', true)
            ->orderBy('id', 'asc')
            ->get()
            ->map(function ($s) {
                $rawLogo = $s->logo_url ?? $s->logo ?? null;

                // If the stored value looks like a relative storage path (no http/https),
                // resolve it through the filesystem so the frontend gets a usable URL.
                if ($rawLogo && !str_starts_with($rawLogo, 'http')) {
                    try {
                        $rawLogo = Storage::url($rawLogo);
                    } catch (\Throwable $e) {
                        // Leave as-is if Storage driver is misconfigured
                    }
                }

                return [
                    'id'          => $s->id,
                    'name'        => $s->name,
                    'logo'        => $rawLogo,
                    'website'     => $s->website_url ?? null,
                    'description' => $s->description ?? null,
                    'type'        => $s->type ?? null,
                ];
            });

        return response()->json(['sponsors' => $sponsors]);
    }
}
