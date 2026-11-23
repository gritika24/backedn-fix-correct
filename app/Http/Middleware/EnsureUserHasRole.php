<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows the request only when the authenticated user has one of the given
 * roles, e.g. ->middleware('role:admin') or ->middleware('role:user,cafe_owner').
 */
class EnsureUserHasRole
{
    private const MESSAGES = [
        'admin' => 'Access denied. Admin privileges required.',
        'cafe_owner' => 'Access denied. Café owner privileges required.',
        'user' => 'Access denied. Only customers can access this feature.',
    ];

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, $roles, true)) {
            $message = count($roles) === 1
                ? (self::MESSAGES[$roles[0]] ?? 'Access denied.')
                : 'Access denied.';

            return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
