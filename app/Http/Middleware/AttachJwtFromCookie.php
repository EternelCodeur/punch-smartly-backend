<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AttachJwtFromCookie
{
    /**
     * Handle an incoming request.
     *
     * If a 'token' cookie exists and there is no Authorization header,
     * attach it as a Bearer token so JWT middleware can parse it.
     */
    public function handle(Request $request, Closure $next)
    {
        if (!$request->headers->has('Authorization')) {
            $token = $request->cookie('token');
            if (!empty($token)) {
                $request->headers->set('Authorization', 'Bearer ' . $token);
            }
        }
        return $next($request);
    }
}
