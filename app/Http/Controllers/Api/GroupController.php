<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

class GroupController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $groups = Group::whereHas('members', function ($q) use ($user) {
            $q->where('users.id', $user->id);
        })->with('members')->get();

        return response()->json(['groups' => $groups]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $v = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'emails' => 'array',
            'emails.*' => 'email'
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $emails = $request->input('emails', []);
        $emails = array_values(array_unique(array_filter($emails)));

        // Always include creator
        $members = [$user->email];
        $members = array_merge($members, $emails);
        $members = array_slice($members, 0, 10); // enforce max 10

        // Find existing users by email
        $users = User::whereIn('email', $members)->get();

        // Create group
        $group = Group::create(['name' => $request->name, 'created_by' => $user->id]);
        $group->members()->attach($users->pluck('id')->toArray());

        // Broadcast membership change to the group channel so members can react
        try {
            event(new \App\Events\GroupMembershipChanged($group->id, 'created', $group->members->pluck('id')->toArray()));
        } catch (\Throwable $e) {
            // ignore broadcasting errors
        }

        return response()->json(['group' => $group->load('members')], 201);
    }
}
