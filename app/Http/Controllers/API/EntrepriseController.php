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
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);
        $ent = Entreprise::create($data);
        return response()->json($ent, 201);
    }

    public function show(Entreprise $entreprise)
    {
        return response()->json($entreprise);
    }

    public function update(Request $request, Entreprise $entreprise)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
        ]);
        $entreprise->fill($data)->save();
        return response()->json($entreprise);
    }

    public function destroy(Entreprise $entreprise)
    {
        $entreprise->delete();
        return response()->json(null, 204);
    }
}
