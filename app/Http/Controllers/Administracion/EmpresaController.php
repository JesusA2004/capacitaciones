<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\StoreEmpresaRequest;
use App\Http\Requests\Administracion\UpdateEmpresaRequest;
use App\Models\Empresa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El logo de empresa es una imagen ligera de marca (no un documento laboral
 * pesado): se guarda en el disco publico normal, no en el NAS reservado
 * para expedientes/documentos (ver App\Services\Expedientes\DocumentoStorageService).
 */
class EmpresaController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Empresa::class);

        $empresas = Empresa::query()
            ->withCount('sucursales')
            ->when($request->string('busqueda')->toString(), function ($query, string $busqueda) {
                $query->where(function ($sub) use ($busqueda) {
                    $sub->where('nombre', 'like', "%{$busqueda}%")
                        ->orWhere('razon_social', 'like', "%{$busqueda}%")
                        ->orWhere('rfc', 'like', "%{$busqueda}%");
                });
            })
            ->orderBy('nombre')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Administracion/Empresas/Index', [
            'empresas' => $empresas,
            'filtros' => $request->only('busqueda'),
            'estadisticas' => [
                'total' => Empresa::count(),
                'activos' => Empresa::where('activo', true)->count(),
                'inactivos' => Empresa::where('activo', false)->count(),
            ],
        ]);
    }

    public function store(StoreEmpresaRequest $request): RedirectResponse
    {
        $datos = $request->safe()->except('logo');

        if ($request->hasFile('logo')) {
            $datos['logo_path'] = $request->file('logo')->store('empresas/logos', 'public');
        }

        Empresa::create($datos);

        return back()->with('toast', ['type' => 'success', 'message' => 'Empresa creada correctamente.']);
    }

    public function update(UpdateEmpresaRequest $request, Empresa $empresa): RedirectResponse
    {
        $datos = $request->safe()->except('logo');

        if ($request->hasFile('logo')) {
            if ($empresa->logo_path) {
                Storage::disk('public')->delete($empresa->logo_path);
            }

            $datos['logo_path'] = $request->file('logo')->store('empresas/logos', 'public');
        }

        $empresa->update($datos);

        return back()->with('toast', ['type' => 'success', 'message' => 'Empresa actualizada correctamente.']);
    }

    public function destroy(Empresa $empresa): RedirectResponse
    {
        $this->authorize('delete', $empresa);

        if ($empresa->sucursales()->exists()) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'No se puede eliminar: la empresa tiene sucursales asociadas.',
            ]);
        }

        $empresa->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Empresa eliminada correctamente.']);
    }
}
