<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SubscriptionApiController extends Controller
{
    public function store(Request $request)
    {
        return response()->json([
            'ok' => false,
            'message' => 'Direct personal subscription creation is deprecated. Use institution package purchase flow.'
        ], 410);
    }
}
