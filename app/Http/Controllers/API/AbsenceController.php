<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Absence;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

/**
 * ContrÃ´leur API pour la gestion des absences.
 */
class AbsenceController extends Controller
{
    public function index(Request $request)
    {
        $query = Absence::query()->with('employe');

        // Role/tenant scoping
        $authRole = strtolower((string)$request->attributes->get('auth_role'));
        $authEnterpriseId = $request->attributes->get('auth_enterprise_id');
        $authTenantId = $request->attributes->get('auth_tenant_id');
        if ($authRole === 'supertenant') {
            // no restriction
        } elseif (in_array($authRole, ['superadmin','admin'], true)) {
            // Admin & Superadmin: visibilité sur toutes les absences du tenant
            if ($authTenantId) {
                $query->whereHas('employe', function($q) use ($authTenantId) {
                    $q->whereHas('entreprise', function($qq) use ($authTenantId) { $qq->where('tenant_id', $authTenantId); });
                });
            } else {
                $query->whereRaw('1=0');
            }
        } elseif ($authRole === 'user') {
            if ($authEnterpriseId) {
                $query->whereHas('employe', function($q) use ($authEnterpriseId) {
                    $q->where('entreprise_id', $authEnterpriseId);
                });
            } else {
                $query->whereRaw('1=0');
            }
        } else {
            $query->whereRaw('1=0');
        }

        // Filtre employé (cast explicite)
        $employeId = $request->query('employe_id');
        if ($employeId !== null && $employeId !== '') {
            $query->where('employe_id', (int) $employeId);
        }

        // Validation légère des dates (évite 500 si format invalide)
        $from = $request->query('from');
        $to = $request->query('to');
        $month = $request->query('month');

        try {
            if ($from && $to) {
                $fromD = Carbon::parse($from)->toDateString();
                $toD = Carbon::parse($to)->toDateString();
                if ($fromD > $toD) {
                    return response()->json(['message' => 'from doit être <= to'], 422);
                }
                $query->whereBetween('absences.date', [$fromD, $toD]);
            } elseif ($month) {
                // Support mois (YYYY-MM)
                $start = Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfMonth();
                $end = $start->copy()->endOfMonth();
                $query->whereBetween('absences.date', [$start->toDateString(), $end->toDateString()]);
            }
        } catch (\Exception $e) {
            return response()->json(['message' => 'Paramètres de dates invalides'], 422);
        }

        $perPage = (int)($request->query('per_page', 50));
        if ($perPage > 0) {
            return response()->json($query->orderBy('absences.date', 'asc')->paginate($perPage));
        }

        return response()->json($query->orderBy('absences.date', 'asc')->get());
    }

    public function store(Request $request)
    {
        // Supporte soit une seule date, soit une plage [start_date, end_date]
        $data = $request->validate([
            'employe_id' => ['required', 'integer', 'exists:employes,id'],
            'date' => ['sometimes', 'date'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date'],
            'status' => ['sometimes', 'nullable', 'string'], // e.g. conge | justified | unjustified
            'reason' => ['sometimes', 'nullable', 'string'],
        ]);

        // Scope check
        $authRole = strtolower((string)$request->attributes->get('auth_role'));
        $authEnterpriseId = $request->attributes->get('auth_enterprise_id');
        $authTenantId = $request->attributes->get('auth_tenant_id');
        if (!in_array($authRole, ['supertenant','superadmin','admin','user'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $emp = \App\Models\Employe::with('entreprise')->find($data['employe_id']);
        if (!$emp) { return response()->json(['message' => 'Employé introuvable'], 404); }
        // Admin/Superadmin: employee must belong to same tenant
        if (in_array($authRole, ['superadmin','admin'], true)) {
            if ($authTenantId && (int)optional($emp->entreprise)->tenant_id !== (int)$authTenantId) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        } elseif ($authRole === 'user') {
            // User: employee must belong to same enterprise
            if ($authEnterpriseId && (int)optional($emp)->entreprise_id !== (int)$authEnterpriseId) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        // Valeur par défaut pour status
        $status = $data['status'];
        $reason = $data['reason'] ?? null;

        // Si plage fournie, créer une absence par jour
        if (!empty($data['start_date']) && !empty($data['end_date'])) {
            $start = Carbon::parse($data['start_date'])->startOfDay();
            $end = Carbon::parse($data['end_date'])->startOfDay();
            if ($end->lt($start)) {
                return response()->json(['message' => 'La date de fin doit être postérieure ou égale à la date de début'], 422);
            }

            $created = [];
            DB::transaction(function () use ($data, $status, $reason, $start, $end, &$created) {
                for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                    $created[] = Absence::firstOrCreate([
                        'employe_id' => $data['employe_id'],
                        'date' => $d->toDateString(),
                    ], [
                        'status' => $status,
                        'reason' => $reason,
                    ]);
                }
            });

            return response()->json(array_map(fn ($a) => $a->toArray(), $created), 201);
        }

        // Sinon, création pour une seule date
        if (empty($data['date'])) {
            return response()->json(['message' => 'date ou start_date/end_date requis'], 422);
        }

        $absence = Absence::firstOrCreate([
            'employe_id' => $data['employe_id'],
            'date' => Carbon::parse($data['date'])->toDateString(),
        ], [
            'status' => $status,
            'reason' => $reason,
        ]);

        return response()->json($absence, 201);
    }

    public function show(Absence $absence)
    {
        $absence->load('employe');
        return response()->json($absence);
    }

    public function update(Request $request, Absence $absence)
    {
        // Scope check
        $authRole = strtolower((string)$request->attributes->get('auth_role'));
        $authEnterpriseId = $request->attributes->get('auth_enterprise_id');
        $authTenantId = $request->attributes->get('auth_tenant_id');
        $emp = \App\Models\Employe::with('entreprise')->find($absence->employe_id);
        if (in_array($authRole, ['superadmin','admin'], true)) {
            if ($authTenantId && (int)optional(optional($emp)->entreprise)->tenant_id !== (int)$authTenantId) return response()->json(['message' => 'Unauthorized'], 403);
        } elseif ($authRole === 'user') {
            if ($authEnterpriseId && (int)optional($emp)->entreprise_id !== (int)$authEnterpriseId) return response()->json(['message' => 'Unauthorized'], 403);
        }
        if (!in_array($authRole, ['supertenant','superadmin','admin'], true)) return response()->json(['message' => 'Unauthorized'], 403);

        $data = $request->validate([
            'status' => ['sometimes', 'nullable', 'string'],
            'reason' => ['sometimes', 'nullable', 'string'],
        ]);

        $absence->fill($data)->save();
        $absence->load('employe');
        return response()->json($absence);
    }

    public function destroy(Absence $absence)
    {
        // Scope check
        $authRole = strtolower((string)request()->attributes->get('auth_role'));
        $authEnterpriseId = request()->attributes->get('auth_enterprise_id');
        $authTenantId = request()->attributes->get('auth_tenant_id');
        $emp = \App\Models\Employe::with('entreprise')->find($absence->employe_id);
        if (in_array($authRole, ['superadmin','admin'], true)) {
            if ($authTenantId && (int)optional(optional($emp)->entreprise)->tenant_id !== (int)$authTenantId) return response()->json(['message' => 'Unauthorized'], 403);
        } elseif ($authRole === 'user') {
            if ($authEnterpriseId && (int)optional($emp)->entreprise_id !== (int)$authEnterpriseId) return response()->json(['message' => 'Unauthorized'], 403);
        }
        if (!in_array($authRole, ['supertenant','superadmin','admin'], true)) return response()->json(['message' => 'Unauthorized'], 403);

        $absence->delete();
        return response()->json(null, 204);
    }
}

