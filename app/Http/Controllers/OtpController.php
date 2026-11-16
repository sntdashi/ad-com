<?php

namespace App\Http\Controllers;

use App\Services\Otp\OtpConfig;
use App\Services\PasswordOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OtpController extends Controller
{
    public function issue(Request $request, PasswordOtpService $otpService): JsonResponse
    {
        $validated = $request->validate([
            'purpose' => ['required', Rule::in(OtpConfig::PURPOSES)],
            'phone' => ['nullable', 'string', 'max:24'],
        ]);

        $purpose = (string) $validated['purpose'];
        $user = $request->user();

        if ($purpose !== 'registration_phone' && $purpose !== 'password_reset') {
            abort_if($user === null, 401);
        }

        $challenge = $otpService->issueChallenge(
            $purpose === 'registration_phone' ? null : $user,
            $purpose,
            $validated['phone'] ?? $user?->phone,
            $request->ip(),
        );

        return response()->json([
            'message' => 'Kode verifikasi telah diproses.',
            'challenge' => $challenge,
        ]);
    }

    public function resend(Request $request, string $challengeId, PasswordOtpService $otpService): JsonResponse
    {
        $challenge = $otpService->resendChallenge($challengeId, $request->user(), $request->ip());

        return response()->json([
            'message' => 'Kode OTP baru telah dikirim.',
            'challenge' => $challenge,
        ]);
    }

    public function verify(Request $request, string $challengeId, PasswordOtpService $otpService): JsonResponse
    {
        $validated = $request->validate([
            'purpose' => ['required', Rule::in(OtpConfig::PURPOSES)],
            'code' => ['required', 'string', 'min:4', 'max:8'],
        ]);

        $challenge = $otpService->verifyChallenge(
            $challengeId,
            (string) $validated['purpose'],
            (string) $validated['code'],
            $request->user(),
        );

        return response()->json([
            'verified' => true,
            'challenge_id' => $challenge->challenge_id,
            'purpose' => $challenge->purpose,
        ]);
    }
}
