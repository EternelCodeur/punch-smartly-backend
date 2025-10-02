<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;

class JwtAuthenticate
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $auth = $request->header('Authorization', '');
        $token = '';
        if (str_starts_with($auth, 'Bearer ')) {
            $token = trim(substr($auth, 7));
        }
        if ($token === '') {
            // Fallback: read from HttpOnly cookie
            $token = (string) $request->cookie('ps_token', '');
        }
        if ($token === '') {
            return response()->json(['message' => 'Token manquant'], 401);
        }

        try {
            if (class_exists(\Tymon\JWTAuth\Facades\JWTAuth::class)) {
                // Use tymon/jwt-auth if available
                $user = JWTAuth::setToken($token)->authenticate();
                if (!$user) {
                    return response()->json(['message' => 'Utilisateur introuvable'], 401);
                }
                $request->setUserResolver(function () use ($user) { return $user; });
                // Derive attributes from user model to avoid claim mismatch
                $request->attributes->set('auth_role', $user->role ?? null);
                $request->attributes->set('auth_enterprise_id', $user->enterprise_id ?? null);
            } else {
                // Fallback: firebase/php-jwt
                $secret = env('JWT_SECRET');
                if (!$secret) {
                    $appKey = config('app.key');
                    if ($appKey && str_starts_with($appKey, 'base64:')) {
                        $decodedKey = base64_decode(substr($appKey, 7));
                        $secret = $decodedKey ?: $appKey;
                    } else {
                        $secret = $appKey;
                    }
                }
                if (!$secret) {
                    return response()->json(['message' => 'Secret JWT introuvable'], 500);
                }
                $decoded = JWT::decode($token, new Key($secret, 'HS256'));
                $userId = $decoded->sub ?? null;
                if (!$userId) {
                    return response()->json(['message' => 'Token invalide'], 401);
                }
                $user = User::find($userId);
                if (!$user) {
                    return response()->json(['message' => 'Utilisateur introuvable'], 401);
                }
                $request->setUserResolver(function () use ($user) { return $user; });
                $request->attributes->set('auth_role', $decoded->role ?? ($user->role ?? null));
                $request->attributes->set('auth_enterprise_id', $decoded->enterprise_id ?? ($user->enterprise_id ?? null));
            }
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Token invalide ou expiré'], 401);
        }

        return $next($request);
    }
}
