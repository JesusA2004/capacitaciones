<?php

namespace App\Http\Controllers\Reportes;

use App\Enums\AccionPeriodoRotacion;
use App\Enums\EstadoCargaRotacion;
use App\Enums\NivelHallazgoRotacion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reportes\AnalizarRotacionRequest;
use App\Models\RotacionCarga;
use App\Services\Rotacion\RotacionConsultaService;
use App\Services\Rotacion\RotacionFormatoService;
use App\Services\Rotacion\RotacionImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rotación de personal cargada desde el Excel de RH (Reportes → Rotación
 * de personal). Delgado: lectura/aplicación en RotacionImportService,
 * consulta en RotacionConsultaService, formato en RotacionFormatoService.
 */
class RotacionPersonalController extends Controller
{
    public function __construct(
        private readonly RotacionImportService $importador,
        private readonly RotacionConsultaService $consulta,
        private readonly RotacionFormatoService $formato,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', RotacionCarga::class);
        $usuario = $request->user();
        $puedeCargar = $usuario->can('create', RotacionCarga::class);
        $filtros = $request->only(['sucursal_id', 'hasta']);

        $seleccionada = null;
        if ($puedeCargar && is_numeric($request->query('carga'))) {
            $carga = RotacionCarga::query()->with(['subidoPor:id,name,apellidos', 'aplicadoPor:id,name,apellidos'])->find((int) $request->query('carga'));
            if ($carga instanceof RotacionCarga) {
                $seleccionada = $this->cargaDetalle($carga);
            }
        }

        return Inertia::render('Reportes/RotacionPersonal', [
            'tablero' => $this->consulta->tablero($usuario, $filtros),
            'filtros' => [
                'sucursal_id' => is_numeric($filtros['sucursal_id'] ?? null) ? (int) $filtros['sucursal_id'] : null,
                'hasta' => is_string($filtros['hasta'] ?? null) ? $filtros['hasta'] : null,
            ],
            'sucursales' => $this->consulta->sucursalesConDatos($usuario),
            'puedeCargar' => $puedeCargar,
            'cargas' => $puedeCargar
                ? RotacionCarga::query()->with(['subidoPor:id,name,apellidos'])->latest('id')->limit(15)->get()
                    ->map(fn (RotacionCarga $c): array => $this->cargaResumen($c))->all()
                : [],
            'cargaSeleccionada' => $seleccionada,
            'anioFormato' => (int) now()->year,
        ]);
    }

    public function analizar(AnalizarRotacionRequest $request): RedirectResponse
    {
        $carga = $this->importador->analizar($request->file('archivo'), $request->user());

        return to_route('reportes.rotacion.index', ['carga' => $carga->id])->with('toast', [
            'type' => 'success',
            'message' => sprintf('Archivo analizado: %d mes(es) por sucursal listos y %d hallazgo(s). Revisa la vista previa antes de confirmar.', $carga->periodos_detectados, $carga->hallazgos),
        ]);
    }

    public function aplicar(Request $request, RotacionCarga $carga): RedirectResponse
    {
        $this->authorize('aplicar', $carga);
        $resultado = $this->importador->aplicar($carga, $request->user());

        return to_route('reportes.rotacion.index', ['carga' => $carga->id])->with('toast', [
            'type' => 'success',
            'message' => sprintf('Rotación cargada: %d nuevo(s), %d reemplazado(s), %d sin cambios.', $resultado['nuevos'], $resultado['reemplazados'], $resultado['sin_cambios']),
        ]);
    }

    public function descartar(Request $request, RotacionCarga $carga): RedirectResponse
    {
        $this->authorize('descartar', $carga);
        $this->importador->descartar($carga, $request->user());

        return to_route('reportes.rotacion.index')->with('toast', ['type' => 'success', 'message' => sprintf('Se descartó «%s»; no se cargó nada.', $carga->nombre_archivo)]);
    }

    public function archivo(RotacionCarga $carga): StreamedResponse
    {
        $this->authorize('view', $carga);
        $disco = Storage::disk((string) config('rotacion.disk', 'nas'));
        abort_unless($disco->exists($carga->archivo_ruta), 404, 'El archivo original ya no está en el NAS.');

        return $disco->download($carga->archivo_ruta, $carga->nombre_archivo);
    }

    public function formato(Request $request): BinaryFileResponse
    {
        $this->authorize('viewAny', RotacionCarga::class);
        $anio = (int) $request->integer('anio', (int) now()->year);
        abort_unless($anio >= 2000 && $anio <= 2100, 422, 'Año no válido.');

        $ruta = $this->formato->generar($request->user(), $anio);

        return response()->download($ruta, $this->formato->nombreArchivo($anio))->deleteFileAfterSend();
    }

    /**
     * @return array<string, mixed>
     */
    private function cargaResumen(RotacionCarga $carga): array
    {
        return [
            'id' => $carga->id,
            'nombre_archivo' => $carga->nombre_archivo,
            'estado' => $carga->estado->value,
            'estado_etiqueta' => $carga->estado->etiqueta(),
            'periodos_detectados' => $carga->periodos_detectados,
            'periodos_nuevos' => $carga->periodos_nuevos,
            'periodos_reemplazados' => $carga->periodos_reemplazados,
            'periodos_sin_cambios' => $carga->periodos_sin_cambios,
            'hallazgos' => $carga->hallazgos,
            'subido_por' => trim(sprintf('%s %s', $carga->subidoPor->name, $carga->subidoPor->apellidos)),
            'creado_en' => $carga->created_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cargaDetalle(RotacionCarga $carga): array
    {
        $detalle = $carga->detalle ?? [];

        return [
            ...$this->cargaResumen($carga),
            'aplicado_por' => $carga->aplicadoPor === null ? null : trim(sprintf('%s %s', $carga->aplicadoPor->name, $carga->aplicadoPor->apellidos)),
            'aplicado_en' => $carga->aplicado_en?->toDateTimeString(),
            'puede_aplicar' => $carga->estado === EstadoCargaRotacion::Analizada && ($detalle['periodos'] ?? []) !== [],
            'resumen' => $carga->resumen ?? [],
            'periodos' => array_map(fn (array $p): array => [
                ...$p,
                'accion_etiqueta' => AccionPeriodoRotacion::tryFrom((string) ($p['accion'] ?? ''))?->etiqueta(),
            ], $detalle['periodos'] ?? []),
            'hallazgos' => array_map(fn (array $h): array => [
                ...$h,
                'nivel_etiqueta' => NivelHallazgoRotacion::tryFrom((string) ($h['nivel'] ?? ''))?->etiqueta(),
            ], $detalle['hallazgos'] ?? []),
        ];
    }
}
