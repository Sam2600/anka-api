<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Http\Resources\AuthUserResource;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $user = User::with('tenant')->where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            AuditService::log('auth.login_failed', 'user', $user?->id, "Failed login attempt for {$request->email}");
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        AuditService::log('auth.login', 'user', $user->id, "User {$user->email} logged in", null, $user->tenant_id);

        return response()->json([
            'user'  => (new AuthUserResource($user))->resolve($request),
            'token' => $user->createToken('auth_token')->plainTextToken,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request)
    {
        $user = $request->user()->load('tenant');
        return new AuthUserResource($user);
    }

    // Revoke current token and issue a new one — used by the axios refresh interceptor.
    public function refresh(Request $request)
    {
        $user = $request->user();
        $user->currentAccessToken()->delete();
        return response()->json([
            'token' => $user->createToken('auth_token')->plainTextToken,
        ]);
    }

    // Update the authenticated user's own profile.
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'first_name' => 'sometimes|required|string|max:255',
            'last_name'  => 'sometimes|required|string|max:255',
            'email'      => 'sometimes|required|email|unique:users,email,'.$user->id,
        ]);

        $user->update($validated);

        return new AuthUserResource($user->fresh()->load('tenant'));
    }

    // Change the authenticated user's password.
    public function changePassword(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password'     => 'required|string',
            'new_password'         => 'required|string|min:8|confirmed',
            'new_password_confirmation' => 'required|string',
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect.',
                'errors'  => ['current_password' => ['Current password is incorrect.']],
            ], 422);
        }

        $user->update([
            'password' => Hash::make($validated['new_password']),
        ]);

        return response()->json(['message' => 'Password changed successfully.']);
    }
}
