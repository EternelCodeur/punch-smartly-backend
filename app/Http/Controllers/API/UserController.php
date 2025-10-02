<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class UserController extends Controller
{
    /**
     * Display a listing of users with optional search and role filter.
     */
    public function index(Request $request)
    {
        $query = User::with('entreprise');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('nom', 'like', "%{$search}%");
            });
        }

        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }

        if ($enterpriseId = $request->query('enterprise_id')) {
            $query->where('enterprise_id', $enterpriseId);
        }

        $perPage = (int)($request->query('per_page', 15));
        if ($perPage > 0) {
            return response()->json($query->paginate($perPage));
        }

        return response()->json($query->get());
    }

    /**
     * Store a newly created user in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'role' => ['required', 'string', Rule::in(['user','admin','superadmin'])],
            'enterprise_id' => ['sometimes', 'nullable', 'integer', 'exists:entreprises,id'],
        ]);

        $user = new User();
        $user->nom = $data['nom'];
        $user->role = $data['role'];
        if (array_key_exists('enterprise_id', $data)) {
            $user->enterprise_id = $data['enterprise_id'];
        }
        $plainPassword = Str::random(12);
        $user->password = Hash::make($plainPassword);
        $user->save();
        $user->load('entreprise');
        // Return the plain password ONCE so the frontend can display it to the admin
        return response()->json([
            'user' => $user,
            'plain_password' => $plainPassword,
        ], 201);
    }

    /**
     * Display the specified user.
     */
    public function show(User $user)
    {
        $user->load('entreprise');
        return response()->json($user);
    }

    /**
     * Update the specified user in storage.
     */
    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'nom' => ['sometimes', 'required', 'string', 'max:255'],
            'role' => ['sometimes', 'required', 'string', Rule::in(['user','admin','superadmin'])],
            'enterprise_id' => ['sometimes', 'nullable', 'integer', 'exists:entreprises,id'],
        ]);

        if (array_key_exists('nom', $data)) {
            $user->nom = $data['nom'];
        }
        if (array_key_exists('role', $data)) {
            $user->role = $data['role'];
        }
        if (array_key_exists('enterprise_id', $data)) {
            $user->enterprise_id = $data['enterprise_id'];
        }

        $user->save();
        $user->load('entreprise');
        return response()->json($user);
    }

    /**
     * Remove the specified user from storage.
     */
    public function destroy(User $user)
    {
        $user->delete();
        return response()->json(null, 204);
    }
}

