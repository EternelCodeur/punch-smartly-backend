<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Cookie;

class AuthController extends Controller
{
    /**
     * POST /api/login { password }
     * Authentifie en utilisant uniquement le mot de passe (code secret)
     * et renvoie un JWT + l'utilisateur. Le mot de passe doit être unique par utilisateur.
     */
    public function login(Request $request)
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:6'],
        ]);

        // Protection simple anti brute-force
        usleep(200 * 1000); // 200ms

        $password = $data['password'];

        // Parcourt des utilisateurs pour trouver la correspondance du hash
        // NOTE: Pour des bases volumineuses, envisager de stocker un identifiant secondaire (secret unique)
        $found = null;
        foreach (User::cursor() as $u) {
            if (Hash::check($password, $u->password)) {
                $found = $u;
                break;
            }
        }

        if (!$found) {
            return response()->json(['message' => 'Identifiants invalides'], 401);
        }

        $found->load('entreprise');

        // Génération du JWT
        $now = time();
        $exp = $now + 60 * 60 * 8 * 24; // 8j
        $issuer = config('app.url') ?: 'http://localhost';
        // Résolution robuste du secret
        $secret = env('JWT_SECRET');
        if (!$secret) {
            $appKey = config('app.key');
            if ($appKey && str_starts_with($appKey, 'base64:')) {
                $decoded = base64_decode(substr($appKey, 7));
                $secret = $decoded ?: $appKey;
            } else {
                $secret = $appKey;
            }
        }
        if (!$secret) {
            return response()->json(['message' => 'Secret JWT introuvable (définissez JWT_SECRET ou APP_KEY).'], 500);
        }

        $payload = [
            'iss' => $issuer,
            'iat' => $now,
            'exp' => $exp,
            'sub' => $found->id,
            'role' => $found->role,
            'enterprise_id' => $found->enterprise_id,
        ];

        // Always generate token with firebase/php-jwt to avoid JWTSubject requirement
        try {
            $token = JWT::encode($payload, $secret, 'HS256');
        } catch (\Throwable $e) {
            return response()->json(['message' => 'JWT encode error: '.$e->getMessage()], 500);
        }

        // Send token as HttpOnly cookie (and return user json)
        // Use Secure flag only when the current request is HTTPS to avoid cookie drop in dev
        $secure = $request->isSecure();
        $cookie = cookie('ps_token', $token, (int)(($exp - $now) / 60), '/', null, $secure, true, false, 'Lax');
        return response()->json([
            'user' => $found,
            'expires_in' => $exp - $now,
        ])->cookie($cookie);
    }

    /**
     * GET /api/me
     */
    public function me(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user) return response()->json(['message' => 'Unauthorized'], 401);
            $user->load('entreprise');
            return response()->json($user);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'ME endpoint error: '.$e->getMessage()], 500);
        }
    }

    /**
     * POST /api/logout
     * Clear JWT cookie
     */
    public function logout(Request $request)
    {
        $forget = Cookie::forget('ps_token', '/', null, app()->environment('production'), true, false, 'Lax');
        return response()->json(['ok' => true])->withCookie($forget);
    }
}
