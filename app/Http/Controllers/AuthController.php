<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->validated());

        return response()->json([
            'message' => 'Registration successful',
            'user' => $user,
            'token' => $this->issueToken($user, $request),
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        // Same message for "no account" and "wrong password" so attackers
        // cannot discover which emails are registered.
        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            return response()->json(['message' => 'Invalid email or password'], 401);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'message' => 'Login successful',
            'user' => $user,
            'token' => $this->issueToken($user, $request),
        ]);
    }

    /** Returns the logged-in user (used by the app to restore a session). */
    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logout successful']);
    }

    /** One token per device; the device name helps when reviewing sessions. */
    private function issueToken(User $user, Request $request): string
    {
        $device = substr((string) $request->userAgent(), 0, 100) ?: 'mobile';

        return $user->createToken($device)->plainTextToken;
    }
}
