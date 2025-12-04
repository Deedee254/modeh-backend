<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Institution;
use Illuminate\Support\Facades\Auth;

class InstitutionController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 15);

        $query = Institution::query();
        if ($request->filled('name')) {
            $query->where('name', 'like', '%' . $request->query('name') . '%');
        }

        $institutions = $query->with('children', 'users')->paginate($perPage);

        return response()->json($institutions);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'parent' => 'nullable|string', // slug or id of parent institution (branch creation)
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:50',
            'logo_url' => 'nullable|string|max:1000',
            'website' => 'nullable|url',
            'address' => 'nullable|string|max:500',
            'metadata' => 'nullable|array',
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

    public function update(Request $request, Institution $institution)
    {
        // Only institution managers can update
        $user = $request->user();
        $isManager = $institution->users()->where('user_id', $user->id)->where('role', 'institution-manager')->exists();
        if (!$isManager && !($user && $user->isAdmin())) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|nullable|string|max:255',
            'email' => 'sometimes|nullable|email',
            'phone' => 'sometimes|nullable|string|max:50',
            'logo_url' => 'sometimes|nullable|string|max:1000',
            'website' => 'sometimes|nullable|url',
            'address' => 'sometimes|nullable|string|max:500',
            'metadata' => 'sometimes|nullable|array',
            'is_active' => 'sometimes|boolean',
        ]);

        $institution->update($data);

        return response()->json($institution);
    }
}
