<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Institution;
use Illuminate\Support\Facades\Auth;

class InstitutionController extends Controller
{
    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:50',
        ]);

        $institution = Institution::create(array_merge($data, ['created_by' => $user->id]));

        // Attach the creator as institution-manager
        $institution->users()->attach($user->id, [
            'role' => 'institution-manager',
            'status' => 'active',
            'invited_by' => null,
        ]);

        return response()->json($institution, 201);
    }

    public function show($id)
    {
        $institution = Institution::with('users')->findOrFail($id);
        return response()->json($institution);
    }
}
