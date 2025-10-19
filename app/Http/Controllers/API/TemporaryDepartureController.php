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

        // Role/tenant scoping
        $authRole = strtolower((string)$request->attributes->get('auth_role'));
        $authEnterpriseId = $request->attributes->get('auth_enterprise_id');
        $authTenantId = $request->attributes->get('auth_tenant_id');
        if ($authRole === 'supertenant') {
            // no restriction
        } elseif (in_array($authRole, ['superadmin','admin'], true)) {
            // Admin & Superadmin: visibilité sur toutes les entreprises du même tenant
            if ($authTenantId) {
                $q->whereHas('employe', function ($sub) use ($authTenantId) {
                    $sub->whereHas('entreprise', function($qq) use ($authTenantId) { $qq->where('tenant_id', $authTenantId); });
                });
            } else {
                $q->whereRaw('1=0');
            }
        } elseif ($authRole === 'user') {
            if ($authEnterpriseId) {
                $q->whereHas('employe', function ($sub) use ($authEnterpriseId) {
                    $sub->where('entreprise_id', $authEnterpriseId);
                });
            } else {
                $q->whereRaw('1=0');
            }
        } else {
            $q->whereRaw('1=0');
        }

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

        // Scope check
        $authRole = strtolower((string)$request->attributes->get('auth_role'));
        $authEnterpriseId = $request->attributes->get('auth_enterprise_id');
        $authTenantId = $request->attributes->get('auth_tenant_id');
        if (!in_array($authRole, ['supertenant','superadmin','admin','user'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $emp = \App\Models\Employe::with('entreprise')->find($data['employe_id']);
        if (!$emp) return response()->json(['message' => 'Employé introuvable'], 404);
        if (in_array($authRole, ['superadmin','admin'], true)) {
            // Admin/Superadmin: vérifier le tenant
            if ($authTenantId && (int)optional($emp->entreprise)->tenant_id !== (int)$authTenantId) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        } elseif ($authRole === 'user') {
            // User: limité à sa propre entreprise
            if ($authEnterpriseId && (int)$emp->entreprise_id !== (int)$authEnterpriseId) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

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

        // Scope check by role
        $authRole = strtolower((string)$request->attributes->get('auth_role'));
        $authEnterpriseId = $request->attributes->get('auth_enterprise_id');
        $authTenantId = $request->attributes->get('auth_tenant_id');
        $emp = \App\Models\Employe::with('entreprise')->find($temporaryDeparture->employe_id);
        if (in_array($authRole, ['superadmin','admin'], true)) {
            // Admin/Superadmin: vérifier le tenant
            if ($authTenantId && (int)optional(optional($emp)->entreprise)->tenant_id !== (int)$authTenantId) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        } elseif ($authRole === 'user') {
            // User: limité à sa propre entreprise
            if ($authEnterpriseId && (int)optional($emp)->entreprise_id !== (int)$authEnterpriseId) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }
        if (!in_array($authRole, ['supertenant','superadmin','admin','user'], true)) return response()->json(['message' => 'Unauthorized'], 403);

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
        // Scope check
        $authRole = strtolower((string)request()->attributes->get('auth_role'));
        $authEnterpriseId = request()->attributes->get('auth_enterprise_id');
        $authTenantId = request()->attributes->get('auth_tenant_id');
        $emp = \App\Models\Employe::with('entreprise')->find($temporaryDeparture->employe_id);
        if ($authRole === 'admin' && (int)optional($emp)->entreprise_id !== (int)$authEnterpriseId) return response()->json(['message' => 'Unauthorized'], 403);
        if ($authRole === 'superadmin' && $authTenantId && (int)optional(optional($emp)->entreprise)->tenant_id !== (int)$authTenantId) return response()->json(['message' => 'Unauthorized'], 403);
        if (!in_array($authRole, ['supertenant','superadmin','admin','user'], true)) return response()->json(['message' => 'Unauthorized'], 403);
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

        // Scope check
        $authRole = strtolower((string)$request->attributes->get('auth_role'));
        $authEnterpriseId = $request->attributes->get('auth_enterprise_id');
        $authTenantId = $request->attributes->get('auth_tenant_id');
        $emp = \App\Models\Employe::with('entreprise')->find($temporaryDeparture->employe_id);
        if ($authRole === 'admin' && (int)optional($emp)->entreprise_id !== (int)$authEnterpriseId) return response()->json(['message' => 'Unauthorized'], 403);
        if ($authRole === 'superadmin' && $authTenantId && (int)optional(optional($emp)->entreprise)->tenant_id !== (int)$authTenantId) return response()->json(['message' => 'Unauthorized'], 403);
        if (!in_array($authRole, ['supertenant','superadmin','admin'], true)) return response()->json(['message' => 'Unauthorized'], 403);

        $temporaryDeparture->fill($data)->save();
        return response()->json($temporaryDeparture->refresh()->load('employe'));
    }

    // DELETE /api/temporary-departures/{temporaryDeparture}
    public function destroy(TemporaryDeparture $temporaryDeparture)
    {
        // Scope check
        $authRole = strtolower((string)request()->attributes->get('auth_role'));
        $authEnterpriseId = request()->attributes->get('auth_enterprise_id');
        $authTenantId = request()->attributes->get('auth_tenant_id');
        $emp = \App\Models\Employe::with('entreprise')->find($temporaryDeparture->employe_id);
        if ($authRole === 'admin' && (int)optional($emp)->entreprise_id !== (int)$authEnterpriseId) return response()->json(['message' => 'Unauthorized'], 403);
        if ($authRole === 'superadmin' && $authTenantId && (int)optional(optional($emp)->entreprise)->tenant_id !== (int)$authTenantId) return response()->json(['message' => 'Unauthorized'], 403);
        if (!in_array($authRole, ['supertenant','superadmin','admin'], true)) return response()->json(['message' => 'Unauthorized'], 403);

        $temporaryDeparture->delete();
        return response()->json(null, 204);
    }
}
