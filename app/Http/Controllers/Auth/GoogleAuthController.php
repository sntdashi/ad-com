<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        if (! $this->googleIsConfigured()) {
            return $this->redirectToLoginWithError(
                'Masuk dengan Google belum dikonfigurasi. Hubungi pengelola AD Com.',
            );
        }

        return Socialite::driver(SocialAccount::PROVIDER_GOOGLE)->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        if (! $this->googleIsConfigured()) {
            return $this->redirectToLoginWithError(
                'Masuk dengan Google belum dikonfigurasi. Hubungi pengelola AD Com.',
            );
        }

        try {
            /** @var SocialiteUser $googleUser */
            $googleUser = Socialite::driver(SocialAccount::PROVIDER_GOOGLE)->user();
            $user = $this->resolveUser($googleUser);
        } catch (ValidationException $exception) {
            return $this->redirectToLoginWithError(
                $exception->errors()['google'][0] ?? 'Login Google gagal. Silakan coba lagi.',
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->redirectToLoginWithError(
                'Login Google dibatalkan atau gagal. Silakan coba lagi.',
            );
        }

        if (! $user->phone) {
            if ($user->role !== 'customer' && $user->hasEnabledTwoFactorAuthentication()) {
                return $this->challengeInternalAccount($request, $user);
            }

            Auth::login($user);
            $request->session()->regenerate();
            $request->session()->put('auth.google_phone_setup', true);

            return redirect()->route('profile.edit')->with('status', 'Tambahkan nomor WhatsApp agar kode pemulihan password dapat dikirim ke akunmu.');
        }

        if ($user->role !== 'customer' && $user->hasEnabledTwoFactorAuthentication()) {
            return $this->challengeInternalAccount($request, $user);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function resolveUser(SocialiteUser $googleUser): User
    {
        $providerUserId = trim((string) $googleUser->getId());
        $email = Str::lower(trim((string) $googleUser->getEmail()));
        $rawUser = $googleUser->getRaw();
        $emailIsVerified = filter_var(
            $rawUser['email_verified'] ?? false,
            FILTER_VALIDATE_BOOL,
        ) === true;

        if ($providerUserId === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! $emailIsVerified) {
            throw ValidationException::withMessages([
                'google' => 'Google tidak mengembalikan identitas dengan email terverifikasi.',
            ]);
        }

        $name = trim((string) $googleUser->getName());
        $name = $name !== ''
            ? Str::limit($name, 255, '')
            : Str::of($email)->before('@')->replace(['.', '_', '-'], ' ')->title()->toString();

        return DB::transaction(function () use ($providerUserId, $email, $name, $googleUser): User {
            $socialAccount = SocialAccount::query()
                ->where('provider', SocialAccount::PROVIDER_GOOGLE)
                ->where('provider_user_id', $providerUserId)
                ->lockForUpdate()
                ->first();

            if ($socialAccount !== null) {
                $user = User::query()->lockForUpdate()->findOrFail($socialAccount->user_id);
                $this->ensureUserIsActive($user);
                $this->markEmailAsVerified($user);

                $socialAccount->update([
                    'provider_email' => $email,
                    'avatar_url' => $googleUser->getAvatar(),
                ]);

                if ($user->avatar_url === null && $googleUser->getAvatar() !== null) {
                    $user->update(['avatar_url' => $googleUser->getAvatar()]);
                }

                return $user;
            }

            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->lockForUpdate()
                ->first();

            if ($user === null) {
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Str::random(64),
                    'role' => 'customer',
                    'avatar_url' => $googleUser->getAvatar(),
                    'is_active' => true,
                ]);
            } else {
                $this->ensureUserIsActive($user);

                $conflictingAccountExists = $user->socialAccounts()
                    ->where('provider', SocialAccount::PROVIDER_GOOGLE)
                    ->where('provider_user_id', '!=', $providerUserId)
                    ->lockForUpdate()
                    ->exists();

                if ($conflictingAccountExists) {
                    throw ValidationException::withMessages([
                        'google' => 'Email ini sudah tertaut ke akun Google lain.',
                    ]);
                }

                if ($user->avatar_url === null && $googleUser->getAvatar() !== null) {
                    $user->update(['avatar_url' => $googleUser->getAvatar()]);
                }

            }

            $this->markEmailAsVerified($user);

            $user->socialAccounts()->create([
                'provider' => SocialAccount::PROVIDER_GOOGLE,
                'provider_user_id' => $providerUserId,
                'provider_email' => $email,
                'avatar_url' => $googleUser->getAvatar(),
            ]);

            return $user;
        });
    }

    private function challengeInternalAccount(Request $request, User $user): RedirectResponse
    {
        Auth::logout();
        $request->session()->regenerate();
        $request->session()->put([
            'login.id' => $user->id,
            'login.remember' => false,
        ]);

        return redirect()->route('two-factor.login');
    }

    private function ensureUserIsActive(User $user): void
    {
        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'google' => 'Akun AD Com ini dinonaktifkan. Hubungi pengelola.',
            ]);
        }
    }

    private function markEmailAsVerified(User $user): void
    {
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }
    }

    private function googleIsConfigured(): bool
    {
        return collect(['client_id', 'client_secret', 'redirect'])
            ->every(fn (string $key): bool => filled(config("services.google.{$key}")));
    }

    private function redirectToLoginWithError(string $message): RedirectResponse
    {
        return to_route('login')->with('google_error', $message);
    }
}
