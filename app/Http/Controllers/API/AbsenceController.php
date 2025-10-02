<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Absence;
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
        $data = $request->validate([
            'employe_id' => ['required', 'integer', 'exists:employes,id'],
            'date' => ['required', 'date'],
            'status' => ['nullable', 'string'], // justified | unjustified
            'reason' => ['nullable', 'string'],
        ]);

        $absence = Absence::create($data);
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
