<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Handle an incoming request.
     * Usage: ->middleware('role:supertenant') or 'role:superadmin,admin'
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();
        $role = null;
        if ($user) {
            $role = strtolower((string)($user->role ?? ''));
        } else {
            // Fallback for JWT middleware in current project
            $role = strtolower((string)$request->attributes->get('auth_role'));
        }

        if (!$role || (!empty($roles) && !in_array($role, array_map('strtolower', $roles), true))) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return $next($request);
    }
}
