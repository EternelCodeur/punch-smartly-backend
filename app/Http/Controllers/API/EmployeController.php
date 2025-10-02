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

        // Scope by enterprise for role=user
        $auth = $request->user();
        $authRole = $request->attributes->get('auth_role');
        $authEnterpriseId = $request->attributes->get('auth_enterprise_id');
        if ($auth && ($authRole === 'user') && $authEnterpriseId) {
            $query->where('entreprise_id', $authEnterpriseId);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('position', 'like', "%{$search}%");
            });
        }

        $entrepriseId = $request->query('entreprise_id');
        if ($auth && ($authRole === 'user') && $authEnterpriseId) {
            // Override any client-provided filter for regular users
            $entrepriseId = $authEnterpriseId;
        }
        // Ne pas filtrer la liste principale par entreprise: on retourne tous les employés

        $today = Carbon::now()->toDateString();
        // Normalize today's flags if requested (default: true):
        // Set attendance_date=today and arrival_signed=false, departure_signed=false
        // for employees that are not yet marked for today, so that "absents" are explicit
        $normalizeToday = filter_var($request->query('normalize_today', 'true'), FILTER_VALIDATE_BOOLEAN);
        if ($normalizeToday) {
            $norm = Employe::query();
            if ($entrepriseId) { $norm->where('entreprise_id', $entrepriseId); }
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
          // Total global (toutes entreprises confondues)
          $totalAll  = Employe::query()->count();
        // Today counts endpoint within index when requested (no join)
        if (filter_var($request->query('today_counts', 'false'), FILTER_VALIDATE_BOOLEAN)) {
            $scope = Employe::query();
            if ($entrepriseId) { $scope->where('entreprise_id', $entrepriseId); }
            $present = (clone $scope)->where('attendance_date', $today)->where('arrival_signed', true)->count();
            $absent = (clone $scope)->where('attendance_date', $today)->where('arrival_signed', false)->count();
            $left   = (clone $scope)->where('attendance_date', $today)->where('departure_signed', true)->count();
          
            return response()->json([
                'date' => $today,
                'totalEmployees' => $totalAll,
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
        $data = $request->validate([
            'entreprise_id' => ['nullable', 'integer', 'exists:entreprises,id'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
        ]);

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
        $data = $request->validate([
            'entreprise_id' => ['sometimes', 'nullable', 'integer', 'exists:entreprises,id'],
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'position' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $employe->fill($data)->save();
        $employe->load('entreprise');
        return response()->json($employe);
    }

    public function destroy(Employe $employe)
    {
        $employe->delete();
        return response()->json(null, 204);
    }
}
