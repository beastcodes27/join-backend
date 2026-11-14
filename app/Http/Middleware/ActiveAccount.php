<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActiveAccount
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()) {
            abort_if($request->user()->status !== 'active', 403, 'This account is unavailable.');
            if (time() - (int) $request->session()->get('authenticated_at', 0) > 7200) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                abort(401, 'Your session expired. Please sign in again.');
            }
        }

        return $next($request);
    }
}
