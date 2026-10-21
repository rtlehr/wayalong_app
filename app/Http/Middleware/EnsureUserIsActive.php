<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Always use the stored status: an authenticated model may have been
        // loaded before an administrator deactivated the account.
        if ($user instanceof User && (int) User::query()->whereKey($user->getKey())->value('is_active') !== 1) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(403, 'This WayAlong account has been deactivated.');
        }

        return $next($request);
    }
}
