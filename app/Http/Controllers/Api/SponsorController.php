<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sponsor;
use Illuminate\Http\Request;

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
                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'logo' => $s->logo_url ?? $s->logo ?? null,
                    'website' => $s->website_url ?? null,
                    'description' => $s->description ?? null,
                    'type' => $s->type ?? null,
                ];
            });

        return response()->json(['sponsors' => $sponsors]);
    }
}
