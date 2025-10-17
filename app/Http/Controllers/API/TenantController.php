<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Entreprise;
use App\Models\Employe;
use App\Models\Attendance;
use App\Models\Absence;
use App\Models\TemporaryDeparture;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class TenantController extends Controller
{
    public function index(Request $request)
    {
        $q = Tenant::query();
        if ($search = $request->query('search')) {
            $q->where('name', 'like', "%{$search}%");
        }
        if ($userRole = $request->query('user_role')) {
            $role = strtolower((string)$userRole);
            $q->whereHas('users', function ($uq) use ($role) {
                $uq->where('role', $role);
            });
        }
        $perPage = (int)($request->query('per_page', 50));
        if ($perPage > 0) {
            return response()->json($q->orderBy('name')->paginate($perPage));
        }
        return response()->json($q->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'contact' => ['nullable', 'string', 'max:191'],
        ]);
        $tenant = Tenant::create($data);

        // Create a default superadmin user for this tenant
        // Generate a safe username from tenant name
        $base = Str::slug($tenant->name);
        $username = $base !== '' ? $base : ('tenant-'.$tenant->id);
        // ensure uniqueness (best effort)
        $suffix = 1;
        while (User::where('nom', $username)->exists()) {
            $suffix++;
            $username = ($base !== '' ? $base : 'tenant')."-{$suffix}";
        }

        $plainPassword = Str::random(12);
        $user = new User();
        $user->nom = $username;
        $user->role = 'superadmin';
        $user->tenant_id = $tenant->id;
        $user->enterprise_id = null;
        $user->password = Hash::make($plainPassword);
        $user->save();

        return response()->json([
            'tenant' => $tenant,
            'user' => $user,
            'plain_password' => $plainPassword,
        ], 201);
    }

    public function show(Tenant $tenant)
    {
        return response()->json($tenant);
    }

    public function update(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:191'],
            'contact' => ['sometimes', 'nullable', 'string', 'max:191'],
        ]);
        $tenant->fill($data)->save();
        return response()->json($tenant);
    }

    public function destroy(Tenant $tenant)
    {
        DB::transaction(function () use ($tenant) {
            // Supprimer les utilisateurs du tenant
            User::where('tenant_id', $tenant->id)->delete();

            // Récupérer les entreprises du tenant
            $entrepriseIds = Entreprise::where('tenant_id', $tenant->id)->pluck('id');

            if ($entrepriseIds->isNotEmpty()) {
                // Récupérer les employés liés à ces entreprises
                $employeIds = Employe::whereIn('entreprise_id', $entrepriseIds)->pluck('id');

                if ($employeIds->isNotEmpty()) {
                    // Supprimer toutes les entités dépendantes des employés
                    TemporaryDeparture::whereIn('employe_id', $employeIds)->delete();
                    Absence::whereIn('employe_id', $employeIds)->delete();
                    Attendance::whereIn('employe_id', $employeIds)->delete();

                    // Supprimer les employés
                    Employe::whereIn('id', $employeIds)->delete();
                }

                // Supprimer les entreprises
                Entreprise::whereIn('id', $entrepriseIds)->delete();
            }

            // Enfin, supprimer le tenant
            $tenant->delete();
        });

        return response()->json(null, 204);
    }
}
