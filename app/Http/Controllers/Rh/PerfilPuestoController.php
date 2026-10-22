<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Puestos\GuardarPerfilPuestoRequest;
use App\Models\DescriptivoPuesto;
use App\Models\Puesto;
use App\Services\Puestos\Descriptivos\DescriptivoPuestoService;
use App\Services\Puestos\PerfilPuestoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descriptivos y perfiles de puesto: el «deber ser» del puesto y lo que se
 * busca en el candidato. Ver lo puede RH y las gerencias; editar, solo RH.
 */
class PerfilPuestoController extends Controller
{
    public function __construct(
        private readonly PerfilPuestoService $perfiles,
        private readonly DescriptivoPuestoService $descriptivos,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('perfiles_puesto.ver'), 403);

        return Inertia::render('Rh/PerfilesPuesto/Index', [
            'puestos' => $this->perfiles->listado(),
            // El único botón de esta lista es «Importar descriptivos».
            'puedeImportar' => $request->user()->can('perfiles_puesto.importar'),
        ]);
    }

    public function show(Request $request, Puesto $puesto): Response
    {
        abort_unless($request->user()?->can('perfiles_puesto.ver'), 403);

        return Inertia::render('Rh/PerfilesPuesto/Show', [
            ...$this->perfiles->detalle($puesto),
            'puedeEditar' => $request->user()->can('perfiles_puesto.editar'),
            'puedeDescargar' => $request->user()->can('perfiles_puesto.descargar'),
        ]);
    }

    /** PDF oficial del descriptivo: en línea (visor) o descarga del archivo exacto. */
    public function pdf(Request $request, Puesto $puesto, DescriptivoPuesto $descriptivo): StreamedResponse
    {
        abort_unless($request->user()?->can('perfiles_puesto.ver') && $request->user()->can('perfiles_puesto.descargar'), 403);
        abort_unless($descriptivo->puestos()->whereKey($puesto->id)->exists(), 404);

        return $this->descriptivos->respuestaPdf($descriptivo, $request->boolean('descargar'));
    }

    /**
     * Importar descriptivos oficiales: lee los PDF y se confirma el puesto.
     * Carga masiva: solo super_admin (`perfiles_puesto.importar`, 2026-10-20);
     * editar un perfil sigue siendo `perfiles_puesto.editar`.
     */
    public function importacion(Request $request): Response
    {
        abort_unless($request->user()?->can('perfiles_puesto.importar'), 403);

        $archivos = array_map(
            fn (string $ruta): array => $this->descriptivos->analizar((string) file_get_contents($ruta), basename($ruta)),
            $this->descriptivos->archivosCarpeta(),
        );

        return Inertia::render('Rh/PerfilesPuesto/Importar', [
            'archivos' => array_map(fn (array $a): array => [...$a, 'datos' => null], $archivos),
            'puestos' => Puesto::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    public function importar(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('perfiles_puesto.importar'), 403);
        $datos = $request->validate([
            'sha256' => ['required', 'string', 'size:64'],
            'puestos' => ['required', 'array', 'min:1'],
            'puestos.*' => ['integer', 'exists:puestos,id'],
        ], ['puestos.required' => 'Elige a qué puesto(s) corresponde este descriptivo.']);

        $ruta = collect($this->descriptivos->archivosCarpeta())->first(fn (string $r) => hash_file('sha256', $r) === $datos['sha256']);
        abort_if($ruta === null, 404, 'El PDF ya no está en la carpeta de descriptivos.');

        $descriptivo = $this->descriptivos->importar((string) file_get_contents($ruta), basename($ruta), array_values(array_map('intval', $datos['puestos'])), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('Descriptivo importado (versión %d).', $descriptivo->version)]);
    }

    /** Edición en su propia página (la lectura nunca mezcla formularios). */
    public function edit(Request $request, Puesto $puesto): Response
    {
        abort_unless($request->user()?->can('perfiles_puesto.editar'), 403);

        return Inertia::render('Rh/PerfilesPuesto/Editar', $this->perfiles->detalle($puesto));
    }

    public function update(GuardarPerfilPuestoRequest $request, Puesto $puesto): RedirectResponse
    {
        $this->perfiles->guardar($puesto, $request->validated(), $request->user());

        return to_route('rh.perfiles-puesto.show', $puesto)->with('toast', ['type' => 'success', 'message' => 'Descriptivo guardado.']);
    }
}
