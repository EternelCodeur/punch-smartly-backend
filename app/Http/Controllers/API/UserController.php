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

        $authRole = strtolower((string)($request->attributes->get('auth_role') ?? optional($request->user())->role ?? ''));
        $authTenantId = $request->attributes->get('auth_tenant_id') ?? optional($request->user())->tenant_id;
        $authEnterpriseId = $request->attributes->get('auth_enterprise_id') ?? optional($request->user())->enterprise_id;

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('nom', 'like', "%{$search}%");
            });
        }

        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }

        // Tenant/enterprise filtering
        if ($authRole !== 'supertenant') {
            if ($authTenantId) {
                $query->where('tenant_id', $authTenantId);
            }
            if ($authRole === 'admin' && $authEnterpriseId) {
                $query->where('enterprise_id', $authEnterpriseId);
            }
        } else if ($enterpriseId = $request->query('enterprise_id')) {
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
        $authRole = strtolower((string)($request->attributes->get('auth_role') ?? optional($request->user())->role ?? ''));
        $authTenantId = $request->attributes->get('auth_tenant_id') ?? optional($request->user())->tenant_id;
        $authEnterpriseId = $request->attributes->get('auth_enterprise_id') ?? optional($request->user())->enterprise_id;

        if (!in_array($authRole, ['supertenant','superadmin'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'role' => ['required', 'string', Rule::in(['user','admin','superadmin'])],
            'enterprise_id' => ['sometimes', 'nullable', 'integer', 'exists:entreprises,id'],
            'tenant_id' => ['sometimes', 'nullable', 'integer', 'exists:tenants,id'],
        ]);

        // Supertenant can assign tenant_id, else force current tenant
        if ($authRole === 'supertenant') {
            if (empty($data['tenant_id'])) {
                return response()->json(['message' => 'tenant_id requis pour créer un utilisateur'], 422);
            }
        } else { // superadmin
            $data['tenant_id'] = $authTenantId;
        }
        // Enterprise must belong to same tenant when provided
        if (!empty($data['enterprise_id'])) {
            $ok = \App\Models\Entreprise::where('id', $data['enterprise_id'])
                ->when($authRole !== 'supertenant' && $authTenantId, fn($q) => $q->where('tenant_id', $authTenantId))
                ->exists();
            if (!$ok) return response()->json(['message' => 'Entreprise hors tenant'], 403);
        }

        $user = new User();
        $user->nom = $data['nom'];
        $user->role = $data['role'];
        if (array_key_exists('tenant_id', $data)) {
            $user->tenant_id = $data['tenant_id'];
        }
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
        $authRole = strtolower((string)($request->attributes->get('auth_role') ?? optional($request->user())->role ?? ''));
        $authTenantId = $request->attributes->get('auth_tenant_id') ?? optional($request->user())->tenant_id;
        $authEnterpriseId = $request->attributes->get('auth_enterprise_id') ?? optional($request->user())->enterprise_id;

        if (!in_array($authRole, ['supertenant','superadmin'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if ($authRole === 'superadmin' && (int)$user->tenant_id !== (int)$authTenantId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'nom' => ['sometimes', 'required', 'string', 'max:255'],
            'role' => ['sometimes', 'required', 'string', Rule::in(['user','admin','superadmin'])],
            'enterprise_id' => ['sometimes', 'nullable', 'integer', 'exists:entreprises,id'],
        ]);

        if (array_key_exists('nom', $data)) {
            $user->nom = $data['nom'];
        }
        if (array_key_exists('role', $data)) {
            // superadmin cannot escalate to supertenant
            if ($authRole === 'superadmin' && $data['role'] === 'superadmin' && (int)$user->tenant_id !== (int)$authTenantId) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
            if ($authRole === 'superadmin' && $data['role'] === 'supertenant') {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
            $user->role = $data['role'];
        }
        if (array_key_exists('enterprise_id', $data)) {
            // Ensure enterprise belongs to same tenant (superadmin) or any (supertenant)
            $ok = \App\Models\Entreprise::where('id', $data['enterprise_id'])
                ->when($authRole === 'superadmin', fn($q) => $q->where('tenant_id', $authTenantId))
                ->exists();
            if (!$ok) return response()->json(['message' => 'Entreprise hors tenant'], 403);
            $user->enterprise_id = $data['enterprise_id'];
        }

        $user->save();
        $user->load('entreprise');
        return response()->json($user);
    }

    /**
     * Remove the specified user from storage.
     */
    public function destroy(Request $request, User $user)
    {
        $authRole = strtolower((string)($request->attributes->get('auth_role') ?? optional($request->user())->role ?? ''));
        $authTenantId = $request->attributes->get('auth_tenant_id') ?? optional($request->user())->tenant_id;
        if (!in_array($authRole, ['supertenant','superadmin'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if ($authRole === 'superadmin' && (int)$user->tenant_id !== (int)$authTenantId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $user->delete();
        return response()->json(null, 204);
    }
}

