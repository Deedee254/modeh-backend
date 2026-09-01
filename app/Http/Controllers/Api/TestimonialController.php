<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Testimonial;

class TestimonialController extends Controller
{
    /**
     * Display a listing of active testimonials.
     */
    public function index()
    {
        $testimonials = Testimonial::where('is_active', true)->get();

        return response()->json(['testimonials' => $testimonials]);
    }
}
