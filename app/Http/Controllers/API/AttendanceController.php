<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employe;
use App\Models\Absence;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Contrôleur API pour la gestion des présences (pointages) des employés.
 */
class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $query = Attendance::query()->with('employe');

        // Scope by enterprise for role=user
        $auth = $request->user();
        $authRole = $request->attributes->get('auth_role');
        $authEnterpriseId = $request->attributes->get('auth_enterprise_id');
        if ($auth && ($authRole === 'user') && $authEnterpriseId) {
            $query->whereHas('employe', function($q) use ($authEnterpriseId) {
                $q->where('entreprise_id', $authEnterpriseId);
            });
        }

        $employeId = (int)($request->query('employe_id') ?? $request->query('employee_id') ?? 0);
        if ($employeId > 0) {
            $query->where('employe_id', $employeId);
        }

        $from = $request->query('from');
        $to = $request->query('to');
        $month = $request->query('month'); // YYYY-MM

        // If an employee is targeted but no date filters are provided, default to current month
        if ($employeId && !$month && !$from && !$to) {
            $month = now()->format('Y-m');
        }

        if ($month && preg_match('/^\\d{4}-\\d{2}$/', $month)) {
            $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
            $end = (clone $start)->endOfMonth();
            $query->whereBetween('date', [$start->toDateString(), $end->toDateString()]);
        } elseif ($from && $to) {
            $query->whereBetween('date', [$from, $to]);
        }

        // For an employee monthly sheet, return full month by default (no pagination)
        $defaultPerPage = $employeId > 0 ? 0 : 50;
        $perPage = (int)($request->query('per_page', $defaultPerPage));
        if ($perPage > 0) {
            return response()->json($query->orderBy('date', 'desc')->paginate($perPage));
        }

        return response()->json($query->orderBy('date', 'desc')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'employe_id' => ['required', 'integer', 'exists:employes,id'],
            'date' => ['required', 'date'],
            'check_in_at' => ['nullable', 'date'],
            'check_in_signature' => ['nullable', 'string'],
            'check_out_at' => ['nullable', 'date'],
            'check_out_signature' => ['nullable', 'string'],
        ]);

        $attendance = Attendance::create($data);
        return response()->json($attendance, 201);
    }

    public function show(Attendance $attendance)
    {
        $attendance->load('employe');
        return response()->json($attendance);
    }

    public function update(Request $request, Attendance $attendance)
    {
        $data = $request->validate([
            'check_in_at' => ['sometimes', 'nullable', 'date'],
            'check_in_signature' => ['sometimes', 'nullable', 'string'],
            'check_out_at' => ['sometimes', 'nullable', 'date'],
            'check_out_signature' => ['sometimes', 'nullable', 'string'],
        ]);

        $attendance->fill($data)->save();
        $attendance->load('employe');
        return response()->json($attendance);
    }

    public function destroy(Attendance $attendance)
    {
        $attendance->delete();
        return response()->json(null, 204);
    }

    /**
     * Admin-only: mark/check-in an employee as "sur le terrain" for today or a provided date.
     */
    public function adminCheckInOnField(Request $request)
    {
        // Enforce admin role
        $authRole = $request->attributes->get('auth_role');
        if ($authRole !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'employe_id' => ['required', 'integer', 'exists:employes,id'],
            'date' => ['nullable', 'date'],
        ]);

        try {
            $date = isset($data['date']) ? Carbon::parse($data['date'])->toDateString() : now()->toDateString();

            $attendance = DB::transaction(function () use ($data, $date) {
                $att = Attendance::firstOrCreate([
                    'employe_id' => $data['employe_id'],
                    'date' => $date,
                ]);
                if (!$att->check_in_at) {
                    $att->check_in_at = now();
                }
                $att->on_field = true;
                $att->save();
                return $att;
            });

            // Update Employe daily flags similarly to normal check-in
            try {
                $emp = Employe::find($data['employe_id']);
                if ($emp) {
                    $today = Carbon::parse($date)->toDateString();
                    if ($emp->attendance_date !== $today) {
                        $emp->attendance_date = $today;
                        $emp->arrival_signed = true;
                        $emp->departure_signed = false;
                    } else {
                        $emp->arrival_signed = true;
                    }
                    $emp->save();
                }
            } catch (\Throwable $e) { /* ignore */ }

            return response()->json($attendance);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Erreur serveur lors du marquage sur le terrain'], 500);
        }
    }

    public function checkIn(Request $request)
    {
        $data = $request->validate([
            'employe_id' => ['required', 'integer', 'exists:employes,id'],
            'signature' => ['nullable', 'string'],
            'date' => ['nullable', 'date'], // optionnel pour backfill
        ]);

        try {
            // Use provided date when backfilling; otherwise, use today's date
            $date = isset($data['date']) ? Carbon::parse($data['date'])->toDateString() : now()->toDateString();

            $attendance = DB::transaction(function () use ($data, $date) {
                // Create or fetch atomically to avoid unique constraint race
                return Attendance::firstOrCreate([
                    'employe_id' => $data['employe_id'],
                    'date' => $date,
                ]);
            });

            if ($attendance->check_in_at) {
                return response()->json(['message' => 'Arrivée déjà enregistrée pour cette date'], 422);
            }

            $attendance->check_in_at = now();
            // Stocker Base64 en base ET sauvegarder un fichier local si DataURL
            $fileUrl = null;
            if (!empty($data['signature']) && str_starts_with($data['signature'], 'data:image')) {
                [$meta, $b64] = explode(',', $data['signature'], 2);
                $ext = str_contains($meta, 'image/jpeg') ? 'jpg' : 'png';
                $binary = base64_decode($b64, true);
                if ($binary !== false) {
                    $filename = 'signatures/' . $data['employe_id'] . '_' . $date . '_in_' . uniqid() . '.' . $ext;
                    Storage::disk('public')->put($filename, $binary);
                    $fileUrl = '/storage/' . $filename;
                }
            }

            // Conserver la base64 en DB, comme demandé
            $attendance->check_in_signature = $data['signature'] ?? null;
            $attendance->save();

            // Update Employe daily flags: set today's date and mark arrival_signed=true, reset departure if new day
            try {
                $emp = Employe::find($data['employe_id']);
                if ($emp) {
                    $today = Carbon::parse($date)->toDateString();
                    if ($emp->attendance_date !== $today) {
                        // New day: reset both flags then set arrival
                        $emp->attendance_date = $today;
                        $emp->arrival_signed = true;
                        $emp->departure_signed = false;
                    } else {
                        $emp->arrival_signed = true;
                    }
                    $emp->save();
                }
            } catch (\Throwable $e) { /* ignore non-blocking */ }

            $payload = $attendance->toArray();
            if ($fileUrl) { $payload['check_in_signature_file_url'] = $fileUrl; }
            return response()->json($payload);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Erreur serveur lors de l\'enregistrement de l\'arrivée'], 500);
        }
    }

    public function checkOut(Request $request)
    {
        $data = $request->validate([
            'employe_id' => ['required', 'integer', 'exists:employes,id'],
            'signature' => ['nullable', 'string'],
            'date' => ['nullable', 'date'],
        ]);

        try {
            $date = isset($data['date']) ? Carbon::parse($data['date'])->toDateString() : now()->toDateString();

            $attendance = DB::transaction(function () use ($data, $date) {
                // Create or fetch atomically to avoid unique constraint race
                return Attendance::firstOrCreate([
                    'employe_id' => $data['employe_id'],
                    'date' => $date,
                ]);
            });

            if ($attendance->check_out_at) {
                return response()->json(['message' => 'Départ déjà enregistré pour cette date'], 422);
            }

            // Require that today's arrival has been signed before allowing departure
            if (!$attendance->check_in_at) {
                return response()->json(['message' => 'Arrivée non signée pour aujourd\'hui'], 422);
            }

            $attendance->check_out_at = now();
            // Stocker Base64 en base ET sauvegarder un fichier local si DataURL
            $fileUrl = null;
            if (!empty($data['signature']) && str_starts_with($data['signature'], 'data:image')) {
                [$meta, $b64] = explode(',', $data['signature'], 2);
                $ext = str_contains($meta, 'image/jpeg') ? 'jpg' : 'png';
                $binary = base64_decode($b64, true);
                if ($binary !== false) {
                    $filename = 'signatures/' . $data['employe_id'] . '_' . $date . '_out_' . uniqid() . '.' . $ext;
                    Storage::disk('public')->put($filename, $binary);
                    $fileUrl = '/storage/' . $filename;
                }
            }

            // Conserver la base64 en DB, comme demandé
            $attendance->check_out_signature = $data['signature'] ?? null;
            $attendance->save();

            // Update Employe daily flags: mark departure_signed=true for today's date
            try {
                $emp = Employe::find($data['employe_id']);
                if ($emp) {
                    $today = Carbon::parse($date)->toDateString();
                    // Ensure date is set for today
                    if ($emp->attendance_date !== $today) {
                        $emp->attendance_date = $today;
                        // if arrival flag wasn't set earlier, keep it as is; we won't force it
                    }
                    $emp->departure_signed = true;
                    $emp->save();
                }
            } catch (\Throwable $e) { /* ignore non-blocking */ }

            $payload = $attendance->toArray();
            if ($fileUrl) { $payload['check_out_signature_file_url'] = $fileUrl; }
            return response()->json($payload);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Erreur serveur lors de l\'enregistrement du départ'], 500);
        }
    }

    public function summary(Request $request, int $employe_id)
    {
        $request->validate([
            'month' => ['nullable', 'regex:/^\\d{4}-\\d{2}$/'], // YYYY-MM
        ]);

        $month = $request->query('month');
        if (!$month) {
            // If month not provided, fallback to latest attendance month for that employee
            $latest = Attendance::query()
                ->where('employe_id', $employe_id)
                ->orderByDesc('date')
                ->first();
            $month = $latest ? Carbon::parse($latest->date)->format('Y-m') : now()->format('Y-m');
        }

        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = (clone $start)->endOfMonth();

        // Scope by enterprise for role=user
        $auth = $request->user();
        $authRole = $request->attributes->get('auth_role');
        $authEnterpriseId = $request->attributes->get('auth_enterprise_id');

        $records = Attendance::query()
            ->where('employe_id', $employe_id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('date')
            ->get();

        if ($auth && ($authRole === 'user') && $authEnterpriseId) {
            // Verify the employee belongs to same enterprise to avoid leakage
            $emp = Employe::find($employe_id);
            if (!$emp || (int)($emp->entreprise_id) !== (int)$authEnterpriseId) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        // Key by normalized string date to avoid Carbon-object keys mismatching string lookups
        $byDate = $records->keyBy(function ($r) {
            return Carbon::parse($r->date)->toDateString();
        });

        // Charger les absences (congés) sur le mois
        $absences = Absence::query()
            ->where('employe_id', $employe_id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(function ($r) { return Carbon::parse($r->date)->toDateString(); });

        $perDay = [];
        $monthMins = 0;

        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $dateStr = $d->toDateString();
            $rec = $byDate->get($dateStr);
            $in = $rec?->check_in_at ? Carbon::parse($rec->check_in_at)->format('H:i') : null;
            $out = $rec?->check_out_at ? Carbon::parse($rec->check_out_at)->format('H:i') : null;

            $mins = 0;
            if ($in && $out) {
                [$ih, $im] = array_map('intval', explode(':', $in));
                [$oh, $om] = array_map('intval', explode(':', $out));
                $mins = ($oh * 60 + $om) - ($ih * 60 + $im);
                if ($mins < 0) { $mins = 0; }
                $monthMins += $mins;
            }

            $leave = null;
            $leaveStatus = null;
            if (!$rec && ($a = $absences->get($dateStr))) {
                $leave = true;
                $leaveStatus = $a->status ?: 'conge';
            }

            $perDay[] = [
                'date' => $dateStr,
                'in' => $in,
                'out' => $out,
                'inSignature' => $rec?->check_in_signature,
                'outSignature' => $rec?->check_out_signature,
                'onField' => (bool)($rec?->on_field ?? false),
                'mins' => $mins,
                'leave' => $leave,
                'leaveStatus' => $leaveStatus,
            ];
        }

        return response()->json([
            'month' => $month,
            'perDay' => $perDay,
            'monthMins' => $monthMins,
        ]);
    }
}
