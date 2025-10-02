<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\TemporaryDeparture;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class TemporaryDepartureController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'employe_id' => ['sometimes', 'integer'],
            'entreprise_id' => ['sometimes', 'integer'],
        ]);

        $start = Carbon::createFromFormat('Y-m', $data['month'])->startOfMonth();
        $end = (clone $start)->endOfMonth();

        $q = TemporaryDeparture::query()
            ->with('employe')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('date', 'desc')
            ->orderBy('departure_time', 'desc');

        if (!empty($data['employe_id'])) {
            $q->where('employe_id', $data['employe_id']);
        }
        if (!empty($data['entreprise_id'])) {
            $entrepriseId = (int)$data['entreprise_id'];
            $q->whereHas('employe', function ($sub) use ($entrepriseId) {
                $sub->where('entreprise_id', $entrepriseId);
            });
        }

        return response()->json($q->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'employe_id' => ['required', 'integer', 'exists:employes,id'],
            'reason' => ['nullable', 'string'],
        ]);

        $today = now()->toDateString();
        $time = now()->format('H:i');

        $dep = TemporaryDeparture::create([
            'employe_id' => $data['employe_id'],
            'date' => $today,
            'departure_time' => $time,
            'reason' => $request->input('reason'),
        ]);

        return response()->json($dep->load('employe'), 201);
    }

    public function markReturn(Request $request, TemporaryDeparture $temporaryDeparture)
    {
        $data = $request->validate([
            'signature' => ['required', 'string'],
            // 'date' => ['sometimes', 'date'], // si besoin de surcharger la date
        ]);

        if ($temporaryDeparture->return_time) {
            return response()->json(['message' => 'Retour déjà enregistré'], 422);
        }

        $fileUrl = null;
        $sig = (string)($data['signature'] ?? '');

        try {
            // 1) Data URL: data:image/*;base64,.... => enregistrer fichier
            if ($sig !== '' && substr($sig, 0, 11) === 'data:image/') {
                $parts = explode(',', $sig, 2);
                if (count($parts) === 2) {
                    $meta = $parts[0];
                    $b64 = $parts[1];
                    $ext = (strpos($meta, 'image/jpeg') !== false || strpos($meta, 'image/jpg') !== false) ? 'jpg' : 'png';
                    $binary = base64_decode($b64, true);
                    if ($binary !== false) {
                        $filename = 'signatures/tmpdep_' . $temporaryDeparture->id . '_' . uniqid('', true) . '.' . $ext;
                        Storage::disk('public')->put($filename, $binary);
                        $fileUrl = Storage::url($filename); // ex: /storage/signatures/...
                    }
                }
            }
            // 2) Plain base64 probable (sans en-tête data:)
            else if ($sig !== '' && preg_match('/^[A-Za-z0-9+\/=_-]+$/', str_replace(["\r","\n"," "], '', $sig)) && strlen($sig) > 40) {
                $binary = base64_decode($sig, true);
                if ($binary !== false) {
                    $filename = 'signatures/tmpdep_' . $temporaryDeparture->id . '_' . uniqid('', true) . '.png';
                    Storage::disk('public')->put($filename, $binary);
                    $fileUrl = Storage::url($filename);
                }
            }
            // 3) URL absolue http(s) fournie => la considérer comme file_url
            else if (preg_match('/^https?:\/\//i', $sig)) {
                $fileUrl = $sig;
            }
            // 4) Chemin relatif stockage: /storage/... ou storage/...
            else if (substr($sig, 0, 9) === '/storage/' || substr($sig, 0, 8) === 'storage/' || substr($sig, 0, 9) === '/uploads/' || substr($sig, 0, 8) === 'uploads/') {
                // Normaliser via Storage::url quand c'est un chemin disque public
                if (substr($sig, 0, 8) === 'storage/') {
                    $fileUrl = Storage::url($sig); // => /storage/... si disque public
                } else {
                    $fileUrl = $sig; // déjà /storage/... ou /uploads/... côté web
                }
            }
        } catch (\Throwable $e) {
            // Ne pas bloquer le retour si l'enregistrement du fichier échoue
            report($e);
        }

        // Enregistrer les champs
        $temporaryDeparture->return_time = now()->format('H:i');
        $temporaryDeparture->return_signature = $data['signature']; // conserver la valeur source
        $temporaryDeparture->return_signature_file_url = $fileUrl;   // si nous avons un fichier/URL exploitable
        $temporaryDeparture->save();

        return response()->json($temporaryDeparture->load('employe'));
    }

    // GET /api/temporary-departures/{temporaryDeparture}
    public function show(TemporaryDeparture $temporaryDeparture)
    {
        return response()->json($temporaryDeparture->load('employe'));
    }

    // PUT /api/temporary-departures/{temporaryDeparture}
    public function update(Request $request, TemporaryDeparture $temporaryDeparture)
    {
        $data = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string'],
            'date' => ['sometimes', 'date'],
            'departure_time' => ['sometimes', 'date_format:H:i'],
        ]);

        $temporaryDeparture->fill($data)->save();
        return response()->json($temporaryDeparture->refresh()->load('employe'));
    }

    // DELETE /api/temporary-departures/{temporaryDeparture}
    public function destroy(TemporaryDeparture $temporaryDeparture)
    {
        $temporaryDeparture->delete();
        return response()->json(null, 204);
    }
}
