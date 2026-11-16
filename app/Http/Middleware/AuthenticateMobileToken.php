<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMobileToken implements AuthenticatesRequests
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        $token = $bearer ? PersonalAccessToken::findToken($bearer) : null;

        if (! $token || ($token->expires_at && $token->expires_at->isPast())
            || $token->created_at->lt(now()->subDays(config('mobile.token_days')))) {
            return response()->json(['message' => 'Sesi berakhir. Silakan masuk kembali.'], 401);
        }

        $user = $token->tokenable;

        if (! $user instanceof User || ! $user->is_active) {
            return response()->json(['message' => 'Akun tidak tersedia.'], 401);
        }

        if (! in_array($user->role, ['customer', 'technician'], true)
            || ! in_array('mobile', $token->abilities, true)
            || ! in_array('role:'.$user->role, $token->abilities, true)) {
            return response()->json(['message' => 'Akun atau sesi ini tidak memiliki akses aplikasi.', 'code' => 'mobile_role_not_supported'], 403);
        }

        if (($user->role === 'technician' && ! $user->hasEnabledTwoFactorAuthentication())
            || ($user->hasEnabledTwoFactorAuthentication() && ! in_array('auth:2fa', $token->abilities, true))) {
            return response()->json(['message' => 'Konfirmasi keamanan akun diperlukan.', 'code' => 'two_factor_setup_required'], 403);
        }

        $user->withAccessToken($token);
        Auth::shouldUse('sanctum');
        Auth::guard('sanctum')->setUser($user);
        $request->setUserResolver(fn (): User => $user);
        $token->forceFill(['last_used_at' => now()])->save();

        return $next($request);
    }
}
