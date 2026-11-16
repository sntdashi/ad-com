<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInternalTwoFactorAuthentication
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->role === 'customer' || $user->hasEnabledTwoFactorAuthentication()) {
            return $next($request);
        }

        return to_route('security.edit')->with('two_factor_required', true);

    }
}
