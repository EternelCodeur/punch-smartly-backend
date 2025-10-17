<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Employe;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

/**
 * Contrôleur API pour la gestion des Employés.
 */
class EmployeController extends Controller
{
    public function index(Request $request)
    {
        $query = Employe::query()->with('entreprise');

        // Role-based scoping
        $auth = $request->user();
        $authRole = strtolower((string)($request->attributes->get('auth_role')));
        $authEnterpriseId = $request->attributes->get('auth_enterprise_id');
        $authTenantId = $request->attributes->get('auth_tenant_id');
        if ($auth) {
            if ($authRole === 'supertenant') {
                // no restriction
            } elseif ($authRole === 'superadmin' || $authRole === 'admin') {
                // Superadmin et Admin: voir tous les employés du même tenant (toutes entreprises confondues)
                if ($authTenantId) {
                    $query->whereHas('entreprise', function ($q) use ($authTenantId) { $q->where('tenant_id', $authTenantId); });
                } else {
                    // Sécurité: si pas de tenant_id connu, ne rien retourner
                    $query->whereRaw('1=0');
                }
            } elseif ($authRole === 'user') {
                // User reste limité à sa propre entreprise
                if ($authEnterpriseId) { $query->where('entreprise_id', $authEnterpriseId); }
                else { $query->whereRaw('1=0'); }
            }
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('position', 'like', "%{$search}%");
            });
        }

        $entrepriseId = $request->query('entreprise_id');
        if ($auth) {
            if ($authRole === 'user' && $authEnterpriseId) {
                $entrepriseId = $authEnterpriseId; // user: enforce own enterprise
            }
            if ($authRole === 'superadmin' && $authTenantId) {
                // ensure any provided entreprise_id belongs to tenant implicitly via earlier whereHas
            }
        }
        // Ne pas filtrer la liste principale par entreprise: on retourne tous les employés

        $today = Carbon::now()->toDateString();
        // Normalize today's flags if requested (default: true):
        // Set attendance_date=today and arrival_signed=false, departure_signed=false
        // for employees that are not yet marked for today, so that "absents" are explicit
        $normalizeToday = filter_var($request->query('normalize_today', 'true'), FILTER_VALIDATE_BOOLEAN);
        if ($normalizeToday) {
            $norm = Employe::query();
            if ($entrepriseId) {
                $norm->where('entreprise_id', $entrepriseId);
            } elseif (in_array($authRole, ['admin','superadmin'], true) && $authTenantId) {
                $norm->whereHas('entreprise', function ($q) use ($authTenantId) { $q->where('tenant_id', $authTenantId); });
            } elseif ($authRole === 'user' && $authEnterpriseId) {
                $norm->where('entreprise_id', $authEnterpriseId);
            }
            $norm->where(function($q) use ($today) {
                $q->whereNull('attendance_date')->orWhere('attendance_date', '!=', $today);
            })->update([
                'attendance_date' => $today,
                'arrival_signed' => false,
                'departure_signed' => false,
            ]);
        }

        // Optional status filter: present | absent | left (for today)
        $status = strtolower((string) $request->query('status', ''));
        if (in_array($status, ['present', 'absent', 'left'], true)) {
            if ($status === 'present') {
                $query->where('attendance_date', $today)->where('arrival_signed', true);
            } elseif ($status === 'absent') {
                $query->where('attendance_date', $today)->where('arrival_signed', false);
            } elseif ($status === 'left') {
                $query->where('attendance_date', $today)->where('departure_signed', true);
            }
        } else {
            // Legacy filters preserved only when no explicit status provided
            // If for_departure=true, return only employees eligible for departure today
            $forDeparture = filter_var($request->query('for_departure', 'false'), FILTER_VALIDATE_BOOLEAN);
            if ($forDeparture) {
                $query->where('attendance_date', $today)
                      ->where('arrival_signed', true)
                      ->where('departure_signed', false);
            } else {
                // Exclude employees who have already signed departure for today, by default
                // Par défaut, ne pas exclure les employés déjà partis afin d'afficher tout le monde
                $excludeDeparted = filter_var($request->query('exclude_departed_today', 'false'), FILTER_VALIDATE_BOOLEAN);
                if ($excludeDeparted) {
                    // Keep employees that do NOT have (attendance_date == today AND departure_signed == true)
                    $query->where(function($q) use ($today) {
                        $q->whereNull('attendance_date')
                          ->orWhere('attendance_date', '!=', $today)
                          ->orWhere(function($qq) use ($today) {
                              $qq->where('attendance_date', $today)
                                 ->where('departure_signed', false);
                          });
                    });
                }
            }
        }
        // Today counts endpoint within index when requested (scoped by entreprise or tenant)
        if (filter_var($request->query('today_counts', 'false'), FILTER_VALIDATE_BOOLEAN)) {
            $scope = Employe::query();
            if ($entrepriseId) {
                $scope->where('entreprise_id', $entrepriseId);
            } elseif (in_array($authRole, ['admin','superadmin'], true) && $authTenantId) {
                $scope->whereHas('entreprise', function ($q) use ($authTenantId) { $q->where('tenant_id', $authTenantId); });
            } elseif ($authRole === 'user' && $authEnterpriseId) {
                $scope->where('entreprise_id', $authEnterpriseId);
            }

            $totalEmployees = (clone $scope)->count();
            $present = (clone $scope)->where('attendance_date', $today)->where('arrival_signed', true)->count();
            $absent  = (clone $scope)->where('attendance_date', $today)->where('arrival_signed', false)->count();
            $left    = (clone $scope)->where('attendance_date', $today)->where('departure_signed', true)->count();

            return response()->json([
                'date' => $today,
                'totalEmployees' => $totalEmployees,
                'presentToday' => $present,
                'absentToday' => $absent,
                'leftToday' => $left,
            ]);
        }

        $perPage = (int)($request->query('per_page', 50));
        if ($perPage > 0) {
            return response()->json($query->orderBy('last_name')->orderBy('first_name')->paginate($perPage));
        }

        return response()->json($query->orderBy('last_name')->orderBy('first_name')->get());
    }

    public function store(Request $request)
    {
        $authRole = strtolower((string)($request->attributes->get('auth_role')));
        $authEnterpriseId = $request->attributes->get('auth_enterprise_id');
        $authTenantId = $request->attributes->get('auth_tenant_id');
        if (!in_array($authRole, ['supertenant','superadmin','admin'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $data = $request->validate([
            'entreprise_id' => ['nullable', 'integer', 'exists:entreprises,id'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
        ]);
        // Admin: peut créer dans n'importe quelle entreprise de son tenant
        if ($authRole === 'admin') {
            if (array_key_exists('entreprise_id', $data) && !is_null($data['entreprise_id'])) {
                $ok = \App\Models\Entreprise::where('id', $data['entreprise_id'])->where('tenant_id', $authTenantId)->exists();
                if (!$ok) return response()->json(['message' => 'Entreprise hors tenant'], 403);
            }
            // si entreprise_id est null, on laisse tel quel (création sans entreprise possible)
        }
        // Superadmin: doit rester dans son tenant
        if ($authRole === 'superadmin' && array_key_exists('entreprise_id', $data) && !is_null($data['entreprise_id'])) {
            $ok = \App\Models\Entreprise::where('id', $data['entreprise_id'])->where('tenant_id', $authTenantId)->exists();
            if (!$ok) return response()->json(['message' => 'Entreprise hors tenant'], 403);
        }
        $employe = Employe::create($data);
        return response()->json($employe, 201);
    }

    public function show(Employe $employe)
    {
        $employe->load('entreprise');
        return response()->json($employe);
    }
    public function update(Request $request, Employe $employe)
    {
        $authRole = strtolower((string)($request->attributes->get('auth_role')));
        $authEnterpriseId = $request->attributes->get('auth_enterprise_id');
        $authTenantId = $request->attributes->get('auth_tenant_id');
        // Authorization: tenant-based
        if (!in_array($authRole, ['supertenant','superadmin','admin'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'entreprise_id' => ['sometimes', 'nullable', 'integer', 'exists:entreprises,id'],
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'position' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        // Superadmin: employé doit rester dans son tenant; si changement d'entreprise, vérifier l'appartenance au tenant
        if ($authRole === 'superadmin' && array_key_exists('entreprise_id', $data) && !is_null($data['entreprise_id'])) {
            $ok = \App\Models\Entreprise::where('id', $data['entreprise_id'])->where('tenant_id', $authTenantId)->exists();
            if (!$ok) return response()->json(['message' => 'Entreprise hors tenant'], 403);
        }

        // Admin: peut modifier n'importe quel employé du même tenant et le rattacher à n'importe quelle entreprise du tenant
        if ($authRole === 'admin') {
            // Si une entreprise cible est fournie, elle doit appartenir au tenant de l'admin
            if (array_key_exists('entreprise_id', $data) && !is_null($data['entreprise_id'])) {
                $ok = \App\Models\Entreprise::where('id', $data['entreprise_id'])->where('tenant_id', $authTenantId)->exists();
                if (!$ok) return response()->json(['message' => 'Entreprise hors tenant'], 403);
            }
            // Résoudre le tenant effectif après mise à jour (entreprise fournie sinon entreprise actuelle)
            $effectiveEnterpriseId = array_key_exists('entreprise_id', $data) ? $data['entreprise_id'] : $employe->entreprise_id;
            if (!is_null($effectiveEnterpriseId)) {
                $empTenant = \App\Models\Entreprise::where('id', $effectiveEnterpriseId)->value('tenant_id');
                if ($authTenantId && (int)$empTenant !== (int)$authTenantId) {
                    return response()->json(['message' => 'Unauthorized'], 403);
                }
            } else {
                // Si aucune entreprise n'est définie (reste null), on autorise uniquement si l'employé actuel est déjà dans le même tenant
                $currentTenant = optional($employe->entreprise)->tenant_id;
                if ($currentTenant && $authTenantId && (int)$currentTenant !== (int)$authTenantId) {
                    return response()->json(['message' => 'Unauthorized'], 403);
                }
            }
        }

        // Pour superadmin: s'assurer que l'employé modifié reste dans son tenant si aucune entreprise cible n'est fournie
        if ($authRole === 'superadmin' && !array_key_exists('entreprise_id', $data)) {
            $currentTenant = optional($employe->entreprise)->tenant_id;
            if ($authTenantId && !is_null($currentTenant) && (int)$currentTenant !== (int)$authTenantId) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }
        $employe->fill($data)->save();
        $employe->load('entreprise');
        return response()->json($employe);
    }
    public function destroy(Employe $employe)
    {
        // Authorization like update
        $authRole = strtolower((string)request()->attributes->get('auth_role'));
        $authEnterpriseId = request()->attributes->get('auth_enterprise_id');
        $authTenantId = request()->attributes->get('auth_tenant_id');
        if ($authRole === 'admin' && (int)$employe->entreprise_id !== (int)$authEnterpriseId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if ($authRole === 'superadmin') {
            $empTenant = optional($employe->entreprise)->tenant_id;
            if ($authTenantId && (int)$empTenant !== (int)$authTenantId) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }
        if (!in_array($authRole, ['supertenant','superadmin','admin'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $employe->delete();
        return response()->json(null, 204);
    }
}
