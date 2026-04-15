<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = \Illuminate\Support\Facades\DB::transaction(function () use ($data) {
            $user = User::create($data);

            // 1. Check for pending invites
            $invites = \App\Models\WorkspaceInvite::where('email', $data['email'])
                ->where('status', 'pending')
                ->get();

            if ($invites->isNotEmpty()) {
                foreach ($invites as $invite) {
                    $workspace = $invite->workspace;
                    if ($workspace) {
                        $workspace->users()->attach($user->id, [
                            'role' => $invite->role,
                            'joined_at' => now(),
                        ]);
                        $invite->update(['status' => 'accepted']);
                    }
                }
            } else {
                // 2. Create a default "My Workspace" for new users
                $workspace = \App\Models\Workspace::create([
                    'name' => 'My Workspace',
                    'owner_id' => $user->id,
                ]);

                $workspace->users()->attach($user->id, [
                    'role' => 'admin',
                    'joined_at' => now(),
                ]);
            }

            return $user;
        });

        return response()->json([
            'token' => $user->createToken('auth')->plainTextToken,
            'user' => $user->load('workspaces'),
        ]);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        return response()->json([
            'token' => $user->createToken('auth')->plainTextToken,
            'user' => $user->load('workspaces'),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request)
    {
        return response()->json(['user' => $request->user()->load('workspaces')]);
    }

    public function forgotPassword(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        Password::sendResetLink(['email' => $data['email']]);

        return response()->json(['ok' => true]);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $data,
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));

                $user->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json(['ok' => true]);
    }

    public function googleRedirect()
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    public function googleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Exception $e) {
            return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/login?error=google_failed');
        }

        $user = User::where('google_id', $googleUser->id)
            ->orWhere('email', $googleUser->email)
            ->first();

        if ($user) {
            $user->update([
                'google_id' => $googleUser->id,
                'avatar' => $googleUser->avatar,
            ]);
        } else {
            $user = \Illuminate\Support\Facades\DB::transaction(function () use ($googleUser) {
                $user = User::create([
                    'name' => $googleUser->name,
                    'email' => $googleUser->email,
                    'google_id' => $googleUser->id,
                    'avatar' => $googleUser->avatar,
                    'password' => null,
                ]);

                // Create default workspace logic (same as register)
                $invites = \App\Models\WorkspaceInvite::where('email', $googleUser->email)
                    ->where('status', 'pending')
                    ->get();

                if ($invites->isNotEmpty()) {
                    foreach ($invites as $invite) {
                        $workspace = $invite->workspace;
                        if ($workspace) {
                            $workspace->users()->attach($user->id, [
                                'role' => $invite->role,
                                'joined_at' => now(),
                            ]);
                            $invite->update(['status' => 'accepted']);
                        }
                    }
                } else {
                    $workspace = \App\Models\Workspace::create([
                        'name' => 'My Workspace',
                        'owner_id' => $user->id,
                    ]);

                    $workspace->users()->attach($user->id, [
                        'role' => 'admin',
                        'joined_at' => now(),
                    ]);
                }

                return $user;
            });
        }

        $token = $user->createToken('auth')->plainTextToken;

        return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/auth/callback?token=' . $token);
    }
}
