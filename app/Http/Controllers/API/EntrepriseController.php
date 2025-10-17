<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Entreprise;
use Illuminate\Http\Request;

class EntrepriseController extends Controller
{
    public function index(Request $request)
    {
        $query = Entreprise::query();

        $role = strtolower((string)($request->attributes->get('auth_role') ?? optional($request->user())->role ?? ''));
        $tenantId = $request->attributes->get('auth_tenant_id') ?? optional($request->user())->tenant_id;
        $enterpriseId = $request->attributes->get('auth_enterprise_id') ?? optional($request->user())->enterprise_id;

        if ($role !== 'supertenant') {
            if (in_array($role, ['superadmin','admin'], true)) {
                // Superadmin et Admin: voir toutes les entreprises du même tenant
                if ($tenantId) { $query->where('tenant_id', $tenantId); }
                else { $query->whereRaw('1=0'); }
            } elseif ($role === 'user') {
                // User: uniquement sa propre entreprise
                if ($enterpriseId) { $query->where('id', $enterpriseId); } else { $query->whereRaw('1=0'); }
            } else {
                $query->whereRaw('1=0');
            }
        }
        if ($search = $request->query('search')) {
            $query->where('name', 'like', "%{$search}%");
        }
        $perPage = (int)($request->query('per_page', 50));
        if ($perPage > 0) {
            return response()->json($query->paginate($perPage));
        }
        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $role = strtolower((string)($request->attributes->get('auth_role') ?? optional($request->user())->role ?? ''));
        $tenantId = $request->attributes->get('auth_tenant_id') ?? optional($request->user())->tenant_id;

        if (!in_array($role, ['supertenant','superadmin'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tenant_id' => ['sometimes', 'nullable', 'integer', 'exists:tenants,id'],
        ]);
        if ($role === 'superadmin') {
            $data['tenant_id'] = $tenantId; // force to own tenant
        }
        $ent = Entreprise::create($data);
        return response()->json($ent, 201);
    }

    public function show(Request $request, Entreprise $entreprise)
    {
        $role = strtolower((string)($request->attributes->get('auth_role') ?? optional($request->user())->role ?? ''));
        $tenantId = $request->attributes->get('auth_tenant_id') ?? optional($request->user())->tenant_id;
        $enterpriseId = $request->attributes->get('auth_enterprise_id') ?? optional($request->user())->enterprise_id;
        if ($role !== 'supertenant') {
            if ($role === 'superadmin' && (int)$entreprise->tenant_id !== (int)$tenantId) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
            if (($role === 'admin' || $role === 'user') && (int)$entreprise->id !== (int)$enterpriseId) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }
        return response()->json($entreprise);
    }

    public function update(Request $request, Entreprise $entreprise)
    {
        $role = strtolower((string)($request->attributes->get('auth_role') ?? optional($request->user())->role ?? ''));
        $tenantId = $request->attributes->get('auth_tenant_id') ?? optional($request->user())->tenant_id;
        if (!in_array($role, ['supertenant','superadmin'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if ($role === 'superadmin' && (int)$entreprise->tenant_id !== (int)$tenantId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'tenant_id' => ['sometimes', 'nullable', 'integer', 'exists:tenants,id'],
        ]);
        $entreprise->fill($data)->save();
        return response()->json($entreprise);
    }

    public function destroy(Request $request, Entreprise $entreprise)
    {
        $role = strtolower((string)($request->attributes->get('auth_role') ?? optional($request->user())->role ?? ''));
        $tenantId = $request->attributes->get('auth_tenant_id') ?? optional($request->user())->tenant_id;
        if (!in_array($role, ['supertenant','superadmin'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if ($role === 'superadmin' && (int)$entreprise->tenant_id !== (int)$tenantId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $entreprise->delete();
        return response()->json(null, 204);
    }
}
