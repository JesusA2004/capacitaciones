<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Jobs\AplicarMigracionExpedientesJob;
use App\Models\Colaborador;
use App\Models\ExpedienteHistorico;
use App\Models\MigracionExpedientes;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Expedientes\MigracionInicial\EjecutorMigracion;
use App\Services\Expedientes\MigracionInicial\ExpedientesInitialMigrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * RH → Expedientes → «Migración inicial» (docs/MIGRACION_INICIAL_EXPEDIENTES.md).
 * Delgado: todo vive en ExpedientesInitialMigrationService. Permiso
 * `expedientes.migrar` validado aquí en cada endpoint (no solo en la UI).
 */
class MigracionExpedientesController extends Controller
{
    public function __construct(
        private readonly ExpedientesInitialMigrationService $migracion,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    public function index(Request $request): Response
    {
        $this->exigir($request);
        $actual = $request->filled('migracion') ? MigracionExpedientes::query()->find($request->integer('migracion')) : null;

        return Inertia::render('Rh/Expedientes/MigracionInicial', [
            'migracion' => $actual !== null ? $this->resumen($actual, true) : null,
            // Accesos generados: solo para quien migra (permiso validado arriba).
            'credenciales' => $actual !== null ? $this->migracion->credenciales($actual) : [],
            'historial' => MigracionExpedientes::query()->with('usuario:id,name,apellidos')->latest('id')->limit(10)->get()->map(fn (MigracionExpedientes $m) => $this->resumen($m, false))->values(),
            'colaboradores' => Colaborador::withTrashed()->with('sucursalPrincipal:id,nombre')->orderBy('name')->get(['id', 'name', 'apellidos', 'numero_empleado', 'sucursal_principal_id'])
                ->map(fn (Colaborador $c) => ['id' => $c->id, 'nombre' => $c->nombreCompleto(), 'numero_empleado' => $c->numero_empleado, 'sucursal' => $c->sucursalPrincipal?->nombre])->values(),
            'config' => [
                'origen' => $this->migracion->inventario()->enSitio() ? 'sitio' : 'legacy',
                'ruta_origen' => $this->migracion->inventario()->raiz(),
                'sucursales_permitidas' => (array) config('expedientes.migracion_inicial.sucursales_permitidas', []),
                'sucursales_excluidas' => (array) config('expedientes.migracion_inicial.sucursales_excluidas', []),
            ],
        ]);
    }

    public function analizar(Request $request): RedirectResponse
    {
        $this->exigir($request);
        $request->validate(['archivo' => ['required', 'file', 'mimes:xlsx,xls', 'max:20480']]);
        $archivo = $request->file('archivo');
        abort_unless($archivo instanceof UploadedFile, 422);

        $migracion = $this->migracion->analizar((string) $archivo->getRealPath(), $archivo->getClientOriginalName(), $request->user());

        return redirect()->route('rh.expedientes.migracion.index', ['migracion' => $migracion->id])
            ->with('toast', ['type' => 'success', 'message' => 'Análisis listo: revisa el resumen y los conflictos antes de ejecutar. No se modificó nada.']);
    }

    public function decidir(Request $request, MigracionExpedientes $migracion): RedirectResponse
    {
        $this->exigir($request);
        $datos = $request->validate([
            'filas' => ['sometimes', 'array'],
            'filas.*.ruta' => ['nullable', 'string', 'max:1000'],
            'carpetas' => ['sometimes', 'array'],
            'carpetas.*.accion' => ['required', Rule::in(['historico', 'pendiente', 'vincular', 'omitir'])],
            'carpetas.*.colaborador_id' => ['nullable', 'integer'],
        ]);

        $this->migracion->decidir($migracion, $datos);

        return back()->with('toast', ['type' => 'success', 'message' => 'Decisión guardada.']);
    }

    public function aplicar(Request $request, MigracionExpedientes $migracion): RedirectResponse
    {
        $this->exigir($request);
        $datos = $request->validate([
            'modo' => ['required', Rule::in([EjecutorMigracion::MODO_COPIAR, EjecutorMigracion::MODO_MOVER])],
            'confirmacion' => ['accepted'],
        ]);

        abort_if(in_array($migracion->estado, ['aplicando', 'en_cola'], true), 422, 'Esta migración ya está en proceso.');
        $migracion->update(['estado' => 'en_cola', 'etapa' => 'En cola', 'progreso' => 0, 'error' => null]);
        AplicarMigracionExpedientesJob::dispatch($migracion->id, (int) $request->user()->id, $datos['modo']);

        return back()->with('toast', ['type' => 'success', 'message' => 'Migración en proceso. Puedes seguir el avance en esta pantalla.']);
    }

    public function credenciales(Request $request, MigracionExpedientes $migracion): StreamedResponse
    {
        $this->exigir($request);

        return $this->migracion->credencialesCsv($migracion);
    }

    public function estado(Request $request, MigracionExpedientes $migracion): JsonResponse
    {
        $this->exigir($request);

        return response()->json(['data' => $this->resumen($migracion, false)]);
    }

    public function reporte(Request $request, MigracionExpedientes $migracion): StreamedResponse
    {
        $this->exigir($request);

        return $this->migracion->reporte($migracion);
    }

    /**
     * Ver/descargar el PDF histórico: quien puede ver el expediente del
     * colaborador; los pendientes de vincular, solo quien migra.
     */
    public function verHistorico(Request $request, ExpedienteHistorico $historico): StreamedResponse
    {
        $usuario = $request->user();
        $colaborador = $historico->colaborador;
        $permitido = $colaborador !== null
            ? $this->alcance->puedeVerExpediente($usuario, $colaborador)
            : $usuario->can('expedientes.migrar');
        abort_unless($permitido, 403);

        return $this->migracion->respuesta($historico, $request->boolean('descargar'));
    }

    public function vincularHistorico(Request $request, ExpedienteHistorico $historico): RedirectResponse
    {
        $this->exigir($request);
        $datos = $request->validate(['colaborador_id' => ['required', 'integer']]);
        $colaborador = Colaborador::withTrashed()->where('id', (int) $datos['colaborador_id'])->firstOrFail();

        $this->migracion->vincular($historico, $colaborador, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Expediente histórico vinculado.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function resumen(MigracionExpedientes $m, bool $conPlan): array
    {
        return [
            'id' => $m->id,
            'archivo' => $m->archivo_nombre,
            'hash' => $m->archivo_hash,
            'estado' => $m->estado,
            'modo' => $m->modo,
            'totales' => $m->totales,
            'resultado' => $m->resultado,
            'progreso' => $m->progreso,
            'progreso_total' => $m->progreso_total,
            'etapa' => $m->etapa,
            'error' => $m->error,
            'usuario' => $m->usuario?->nombreCompleto(),
            'creada_en' => $m->created_at?->toIso8601String(),
            'terminada_en' => $m->terminada_en?->toIso8601String(),
            'decisiones' => $m->decisiones,
            'plan' => $conPlan ? $m->planArray() : null,
        ];
    }

    private function exigir(Request $request): void
    {
        abort_unless($request->user()?->can('expedientes.migrar'), 403);
    }
}
