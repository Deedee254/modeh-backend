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
            'parent' => 'nullable|string', // slug or id of parent institution (branch creation)
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:50',
        ]);

        // Resolve parent if provided (accept id or slug)
        $parentId = null;
        if (!empty($data['parent'])) {
            $p = $data['parent'];
            $q = Institution::query();
            if (ctype_digit(strval($p))) {
                $q->orWhere('id', (int)$p);
            }
            $q->orWhere('slug', $p);
            $parent = $q->first();
            if ($parent) $parentId = $parent->id;
        }

        $institution = Institution::create(array_merge($data, ['created_by' => $user->id, 'parent_id' => $parentId]));

        // Attach the creator as institution-manager
        $institution->users()->attach($user->id, [
            'role' => 'institution-manager',
            'status' => 'active',
            'invited_by' => null,
        ]);

        return response()->json($institution, 201);
    }

    public function show(Institution $institution)
    {
        $institution->load('users', 'children');
        return response()->json($institution);
    }
}
