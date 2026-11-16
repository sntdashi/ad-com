<?php

namespace App\Actions\Auth;

use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class IssueMobileToken
{
    public function issue(User $user, string $deviceName, bool $twoFactor = false, int $status = 200): JsonResponse
    {
        $expiresAt = now()->addDays(config('mobile.token_days'));
        $abilities = ['mobile', 'role:'.$user->role];

        if ($twoFactor) {
            $abilities[] = 'auth:2fa';
        }

        $token = $user->createToken($deviceName, $abilities, $expiresAt);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toISOString(),
            'user' => new UserResource($user),
        ], $status)->header('Cache-Control', 'no-store');
    }
}
