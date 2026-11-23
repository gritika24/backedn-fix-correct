<?php

namespace App\Http\Controllers;

use App\Http\Requests\Profile\ChangePasswordRequest;
use App\Http\Requests\Profile\UpdatePhotoRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => $user,
        ]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['password' => $request->validated('new_password')]);

        // Sign out every other device so a stolen session stops working.
        $user->tokens()
            ->whereKeyNot($user->currentAccessToken()->getKey())
            ->delete();

        return response()->json(['message' => 'Password changed successfully.']);
    }

    public function updatePhoto(UpdatePhotoRequest $request): JsonResponse
    {
        $user = $request->user();
        $oldPath = $user->profile_image;

        // Store the new photo first, then remove the old one.
        $path = $request->file('profile_image')->store('profile-images', 'public');
        $user->update(['profile_image' => $path]);

        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return response()->json([
            'message' => 'Profile photo updated successfully.',
            'profile_image' => Storage::disk('public')->url($path),
        ]);
    }

    public function deletePhoto(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->profile_image) {
            Storage::disk('public')->delete($user->profile_image);
            $user->update(['profile_image' => null]);
        }

        return response()->json([
            'message' => 'Profile photo removed successfully.',
            'profile_image' => null,
        ]);
    }
}
