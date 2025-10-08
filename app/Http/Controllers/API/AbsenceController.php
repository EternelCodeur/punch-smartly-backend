<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Absence;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

/**
 * Contrôleur API pour la gestion des absences.
 */
class AbsenceController extends Controller
{
    public function index(Request $request)
    {
        $query = Absence::query()->with('employe');

        if ($employeId = $request->query('employe_id')) {
            $query->where('employe_id', $employeId);
        }

        $from = $request->query('from');
        $to = $request->query('to');

        if ($from && $to) {
            $query->whereBetween('date', [$from, $to]);
        }

        $perPage = (int)($request->query('per_page', 50));
        if ($perPage > 0) {
            return response()->json($query->orderBy('date', 'desc')->paginate($perPage));
        }

        return response()->json($query->orderBy('date', 'desc')->get());
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

        // Valeur par défaut pour status
        $status = $data['status'] ?? 'conge';
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
        $absence->delete();
        return response()->json(null, 204);
    }
}
