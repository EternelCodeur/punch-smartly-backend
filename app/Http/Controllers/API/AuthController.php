<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Cookie;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        // Validation des champs d'entrée
        $request->validate([
            'password' => 'required|string',
            'remember' => 'sometimes|boolean',
        ]);

        try {
            // TTL: 7 jours par défaut, 1 an si "remember" activé
            $remember = (bool) $request->boolean('remember');
            $minutes = $remember ? 525600 : 10080; // 365 jours vs 7 jours
            JWTAuth::factory()->setTTL($minutes);
            // Authentifier par mot de passe uniquement (PIN): rechercher l'utilisateur dont le hash correspond
            $inputPassword = (string) $request->input('password');
            $user = User::all()->first(function ($u) use ($inputPassword) {
                return $u->password && \Illuminate\Support\Facades\Hash::check($inputPassword, $u->password);
            });
            if (!$user) {
                return response()->json(['error' => 'Identifiants invalides'], 401);
            }
            // Générer un token pour cet utilisateur
            $token = JWTAuth::fromUser($user);
        } catch (JWTException $e) {
            return response()->json(['error' => 'Erreur serveur'], 500);
        }

        // Récupérer l'utilisateur authentifié
        // $user est déjà défini ci-dessus
        // Utiliser le schéma de la requête pour déterminer le flag Secure
        // En dev (HTTP), Secure doit être false afin que le cookie soit accepté par le navigateur
        $secure = $request->isSecure();
        $cookie = cookie('token', $token, $minutes, '/', null, $secure, true, false, 'Lax');
        // Déposer aussi un cookie lisible côté client avec les infos publiques de l'utilisateur
        $publicUser = [
            'id' => $user->id,
            'nom' => $user->nom,
            'role' => strtolower($user->role),
            'tenant_id' => $user->tenant_id,
            'enterprise_id' => $user->enterprise_id,
        ];
        // httpOnly = false pour permettre la lecture depuis le frontend
        $userCookie = cookie('user', json_encode($publicUser), $minutes, '/', null, $secure, false, false, 'Lax');
        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'nom' => $user->nom,
                'role' => strtolower($user->role), // renvoyer en minuscule
                'tenant_id' => $user->tenant_id,
                'enterprise_id' => $user->enterprise_id,
            ],
        ])->withCookie($cookie)->withCookie($userCookie);
    }

    public function me()
    {
        try {
            // Essayer depuis l'en-tête Authorization si présent
            try {
                $user = JWTAuth::parseToken()->authenticate();
                return response()->json($user);
            } catch (\Exception $ignored) {
                // Sinon, lire depuis le cookie 'token'
                $token = request()->cookie('token');
                if (!$token) {
                    return response()->json(['error' => 'Non authentifié'], 401);
                }
                $user = JWTAuth::setToken($token)->authenticate();
                return response()->json($user);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'Non authentifié'], 401);
        }
    }

    public function logout()
    {
        try {
            // Invalider le token du cookie si présent
            $token = request()->cookie('token');
            if ($token) {
                JWTAuth::setToken($token)->invalidate();
            } else {
                // fallback: essaie via l'en-tête si disponible
                $parsed = JWTAuth::getToken();
                if ($parsed) JWTAuth::invalidate($parsed);
            }
        } catch (\Exception $e) {
            // ignorer les erreurs d'invalidation
        }
        return response()->json(['message' => 'Déconnecté'])
            ->withCookie(cookie()->forget('token'))
            ->withCookie(cookie()->forget('user'));
    }
}
