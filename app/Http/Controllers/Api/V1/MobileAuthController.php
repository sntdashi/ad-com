<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\IssueMobileToken;
use App\Actions\Fortify\CreateNewUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MobileLoginRequest;
use App\Http\Requests\Api\V1\MobileRegisterRequest;
use App\Http\Requests\Api\V1\MobileTwoFactorRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\LoginOtpChallenge;
use App\Models\User;
use App\Notifications\MobileVerifyEmail;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Symfony\Component\HttpFoundation\Response;

class MobileAuthController extends Controller
{
    public function __construct(private IssueMobileToken $tokens) {}

    public function login(MobileLoginRequest $request): JsonResponse
    {
        $user = User::query()->whereRaw('LOWER(email) = ?', [$request->string('email')->toString()])->first();

        if (! $user || ! Hash::check($request->string('password')->toString(), $user->password) || ! $user->is_active) {
            throw ValidationException::withMessages(['email' => ['Email atau kata sandi tidak sesuai.']]);
        }

        if ($denial = $this->accessDenial($user)) {
            return $denial;
        }

        if ($user->role === 'technician') {
            return $this->startAuthenticatorChallenge($user, $request->string('device_name')->toString());
        }

        return $this->tokens->issue($user, $request->string('device_name')->toString());
    }

    public function register(MobileRegisterRequest $request, CreateNewUser $createUser): JsonResponse
    {
        /** @var array<string, string> $input */
        $input = $request->safe()->only(['name', 'email', 'phone', 'password', 'password_confirmation']);
        $user = $createUser->create($input)->refresh();
        $user->notify(new MobileVerifyEmail);

        return $this->tokens->issue($user, $request->string('device_name')->toString(), status: 201);
    }

    public function twoFactor(MobileTwoFactorRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            $challenge = LoginOtpChallenge::query()
                ->where('purpose', 'mobile_totp')
                ->where('challenge_token_hash', hash('sha256', $request->string('challenge_token')->toString()))
                ->lockForUpdate()
                ->first();

            if (! $challenge || $challenge->expires_at->isPast() || $challenge->attempts >= (int) config('mobile.challenge_attempts')) {
                throw ValidationException::withMessages(['challenge_token' => ['Sesi verifikasi berakhir. Silakan masuk kembali.']]);
            }

            $user = User::query()->lockForUpdate()->find($challenge->user_id);

            if (! $user || ! $user->is_active || ! hash_equals($challenge->security_fingerprint, $user->mobileSecurityFingerprint())) {
                $challenge->delete();
                throw ValidationException::withMessages(['challenge_token' => ['Akun berubah. Silakan masuk kembali.']]);
            }

            if ($denial = $this->accessDenial($user)) {
                $challenge->delete();

                return $denial;
            }

            $isValidCode = $request->filled('recovery_code')
                ? $this->consumeRecoveryCode($user, $request->string('recovery_code')->toString())
                : app(TwoFactorAuthenticationProvider::class)->verify(
                    decrypt($user->two_factor_secret),
                    $request->string('code')->toString(),
                );

            if (! $isValidCode) {
                $challenge->increment('attempts');

                $error = $request->filled('recovery_code') ? 'recovery_code' : 'code';

                return response()->json(['message' => 'Kode autentikator tidak valid.', 'errors' => [$error => ['Kode autentikator tidak valid.']]], 422);
            }

            $deviceName = $challenge->device_name ?? 'Mobile';
            $challenge->delete();

            return $this->tokens->issue($user, $deviceName, twoFactor: true);
        });
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        Password::broker(config('fortify.passwords'))->sendResetLink(['email' => mb_strtolower($data['email'])]);

        return response()->json(['message' => 'Jika email terdaftar, tautan reset dikirim ke email dan kode OTP dikirim ke WhatsApp akun.']);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->notify(new MobileVerifyEmail);
        }

        return response()->json(['message' => 'Periksa email untuk melanjutkan verifikasi.'], 202);
    }

    public function verifyEmail(Request $request, string $id, string $hash): Response
    {
        $user = User::query()->findOrFail($id);
        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);
        abort_unless($user->is_active, 403);

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response('Email berhasil diverifikasi. Kembali ke aplikasi AD Com dan muat ulang profil.', 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    private function accessDenial(User $user): ?JsonResponse
    {
        if (! in_array($user->role, ['customer', 'technician'], true)) {
            return response()->json(['message' => 'Akun ini menggunakan dashboard web.', 'code' => 'mobile_role_not_supported'], 403);
        }

        if ($user->role === 'technician' && ! $user->hasEnabledTwoFactorAuthentication()) {
            return response()->json(['message' => 'Aktifkan OTP autentikator melalui pengaturan keamanan web terlebih dahulu.', 'code' => 'two_factor_setup_required'], 403);
        }

        return null;
    }

    private function startAuthenticatorChallenge(User $user, string $deviceName): JsonResponse
    {
        $challengeToken = Str::random(64);
        $challenge = LoginOtpChallenge::query()->create([
            'user_id' => $user->id,
            'purpose' => 'mobile_totp',
            'code_hash' => Hash::make(Str::random(48)),
            'challenge_token_hash' => hash('sha256', $challengeToken),
            'device_name' => $deviceName,
            'security_fingerprint' => $user->mobileSecurityFingerprint(),
            'expires_at' => now()->addSeconds((int) config('mobile.challenge_seconds')),
        ]);

        LoginOtpChallenge::query()
            ->where('user_id', $user->id)
            ->where('purpose', 'mobile_totp')
            ->where('id', '!=', $challenge->id)
            ->delete();

        return response()->json([
            'two_factor' => true,
            'challenge_token' => $challengeToken,
            'expires_in' => (int) config('mobile.challenge_seconds'),
            'method' => 'authenticator',
            'user' => new UserResource($user),
        ], 202)->header('Cache-Control', 'no-store');
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $recoveryCodes = $user->recoveryCodes();

        if (! in_array($code, $recoveryCodes, true)) {
            return false;
        }

        $user->replaceRecoveryCode($code);

        return true;
    }
}
