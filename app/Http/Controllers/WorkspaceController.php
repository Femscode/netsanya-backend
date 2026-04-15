<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Models\WorkspaceInvite;
use App\Models\User;
use App\Mail\WorkspaceInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;

class WorkspaceController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Return workspaces the user belongs to
        return response()->json([
            'workspaces' => $user->workspaces()->with('owner')->get()
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $workspace = DB::transaction(function () use ($request, $data) {
            $workspace = Workspace::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'owner_id' => $request->user()->id,
            ]);

            // Add owner as admin
            $workspace->users()->attach($request->user()->id, [
                'role' => 'admin',
                'joined_at' => now(),
            ]);

            return $workspace;
        });

        return response()->json([
            'workspace' => $workspace->load('owner')
        ]);
    }

    public function update($id, Request $request)
    {
        $workspace = Workspace::findOrFail($id);

        // check if user is admin in this workspace
        $membership = $workspace->users()->where('user_id', $request->user()->id)->first();
        if (!$membership || $membership->pivot->role !== 'admin') {
            return response()->json(['message' => 'Only admins can update workspace settings'], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $workspace->update($data);

        return response()->json([
            'workspace' => $workspace->load('owner')
        ]);
    }

    public function show($id, Request $request)
    {
        $workspace = Workspace::with(['users', 'owner'])->findOrFail($id);

        // Ensure user belongs to workspace
        if (!$workspace->users()->where('user_id', $request->user()->id)->exists()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'workspace' => $workspace
        ]);
    }

    public function members($id, Request $request)
    {
        $workspace = Workspace::findOrFail($id);

        // Ensure user belongs to workspace
        if (!$workspace->users()->where('user_id', $request->user()->id)->exists()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'members' => $workspace->users()->select('users.id', 'users.name', 'users.email', 'users.avatar')
                ->get()
                ->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'avatar' => $user->avatar,
                        'role' => $user->pivot->role,
                        'joined_at' => $user->pivot->joined_at,
                    ];
                })
        ]);
    }

    public function invite($id, Request $request)
    {
        $workspace = Workspace::findOrFail($id);

        // Only admins can invite
        $currentUser = $workspace->users()->where('user_id', $request->user()->id)->first();
        if (!$currentUser || $currentUser->pivot->role !== 'admin') {
            return response()->json(['message' => 'Only admins can invite members'], 403);
        }

        $data = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', 'in:admin,member'],
        ]);

        // Check if user already exists in workspace
        $existingUser = User::where('email', $data['email'])->first();
        if ($existingUser && $workspace->users()->where('user_id', $existingUser->id)->exists()) {
            return response()->json(['message' => 'User is already a member of this workspace'], 422);
        }

        $token = Str::random(40);

        $invite = WorkspaceInvite::updateOrCreate(
            ['workspace_id' => $workspace->id, 'email' => $data['email']],
            [
                'token' => $token,
                'role' => $data['role'],
                'invited_by' => $request->user()->id,
                'status' => 'pending'
            ]
        );

        // Send Email
        Mail::to($data['email'])->send(new WorkspaceInvitation($workspace, $data['role'], $token));

        return response()->json([
            'message' => 'Invitation sent successfully',
            'invite' => $invite
        ]);
    }

    public function removeUser($id, $userId, Request $request)
    {
        $workspace = Workspace::findOrFail($id);

        // Only admins can remove users
        $currentUser = $workspace->users()->where('user_id', $request->user()->id)->first();
        if (!$currentUser || $currentUser->pivot->role !== 'admin') {
            return response()->json(['message' => 'Only admins can remove members'], 403);
        }

        // Cannot remove yourself if you are the owner
        if ($workspace->owner_id == $userId) {
            return response()->json(['message' => 'Workspace owner cannot be removed'], 422);
        }

        $workspace->users()->detach($userId);

        return response()->json(['message' => 'User removed from workspace']);
    }

    public function join($token, Request $request)
    {
        $invite = WorkspaceInvite::where('token', $token)->where('status', 'pending')->firstOrFail();

        $user = $request->user();
        if ($user->email !== $invite->email) {
            return response()->json(['message' => 'This invitation was sent to a different email address'], 403);
        }

        $workspace = $invite->workspace;

        DB::transaction(function () use ($invite, $user, $workspace) {
            if (!$workspace->users()->where('user_id', $user->id)->exists()) {
                $workspace->users()->attach($user->id, [
                    'role' => $invite->role,
                    'joined_at' => now(),
                ]);
            }

            $invite->update(['status' => 'accepted']);
        });

        return response()->json([
            'message' => 'Joined workspace successfully',
            'workspace' => $workspace->load('owner')
        ]);
    }
}
