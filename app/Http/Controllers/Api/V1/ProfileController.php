<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\UpdateUserProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DeleteProfileRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function update(ProfileUpdateRequest $request, UpdateUserProfile $updateProfile): UserResource
    {
        $user = $updateProfile->handle($request->user(), $request->validated(), $request->avatar(), $request->boolean('remove_avatar'));

        return new UserResource($user);
    }

    public function destroy(DeleteProfileRequest $request): Response
    {
        $user = $request->user();

        if (! Hash::check($request->string('password')->toString(), $user->password)) {
            throw ValidationException::withMessages(['password' => 'Kata sandi tidak sesuai.']);
        }

        $avatarPath = $user->avatar_path;
        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            $user->delete();
        });

        if ($avatarPath !== null) {
            Storage::disk('public')->delete($avatarPath);
        }

        return response()->noContent();
    }
}
