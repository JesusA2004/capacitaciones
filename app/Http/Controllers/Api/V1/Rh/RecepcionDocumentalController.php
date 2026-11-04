<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Api\V1\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecepcionDocumental\MarcarRecepcionRequest;
use App\Models\RecepcionDocumentalCorte;
use App\Models\RecepcionDocumentalRenglon;
use App\Services\RecepcionDocumental\RecepcionDocumentalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recepción documental RH en la app móvil: MISMO RecepcionDocumentalService
 * y mismas Policies que la web. La app solo muestra y palomea; nunca
 * calcula reglas, métricas ni estados.
 */
class RecepcionDocumentalController extends Controller
{
    use RespondePaginado;

    private const FILTROS = ['sucursal_id', 'regla', 'tipo_documento', 'colaborador', 'estado', 'recibido'];

    public function __construct(private readonly RecepcionDocumentalService $servicio) {}

    /** Cortes (periodos) con sus métricas en el alcance del usuario. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', RecepcionDocumentalCorte::class);
        $usuario = $request->user();
        $cortes = $this->servicio->listarCortes($usuario);
        $metricas = $this->servicio->metricasPorCorte($usuario, array_values(array_map(fn (RecepcionDocumentalCorte $c): int => $c->id, $cortes->items())));

        return $this->paginado($cortes, fn (RecepcionDocumentalCorte $c): array => $this->servicio->filaCorte($c, $metricas[$c->id] ?? RecepcionDocumentalService::metricasVacias()));
    }

    /** Resumen del corte: métricas, sucursales con pendientes, tipos, reglas y permisos. */
    public function show(Request $request, RecepcionDocumentalCorte $corte): JsonResponse
    {
        $this->authorize('view', $corte);
        $usuario = $request->user();
        $corte->loadMissing(['cerradoPor:id,name', 'reabiertoPor:id,name']);
        $agrupaciones = $this->servicio->agrupaciones($corte, $usuario);

        return response()->json(['data' => [
            ...$this->servicio->filaCorte($corte, $this->servicio->metricas($corte, $usuario)),
            'sucursales_con_pendientes' => array_values(array_filter($agrupaciones['sucursales'], fn (array $s): bool => $s['metricas']['pendientes'] + $s['metricas']['no_recibidos'] > 0)),
            'agrupaciones' => $agrupaciones,
            'opciones' => $this->servicio->opcionesFiltros($corte, $usuario),
            'permisos' => $this->servicio->permisosDe($usuario, $corte),
        ]]);
    }

    /** Renglones paginados con los mismos filtros que la web. */
    public function renglones(Request $request, RecepcionDocumentalCorte $corte): JsonResponse
    {
        $this->authorize('view', $corte);
        $filtros = $request->only(self::FILTROS);

        return $this->paginado(
            $this->servicio->renglones($corte, $request->user(), $filtros),
            fn (RecepcionDocumentalRenglon $r): array => $this->servicio->filaRenglon($r),
            ['filtros' => $this->servicio->normalizarFiltros($filtros), 'metricas' => $this->servicio->metricas($corte, $request->user(), $filtros)],
        );
    }

    /** Detalle de un renglón con su historial. */
    public function renglon(RecepcionDocumentalRenglon $renglon): JsonResponse
    {
        $this->authorize('view', $renglon);
        $renglon->loadMissing(['recibidoPor:id,name', 'documento:id,estado_flujo,titulo,clave_plantilla']);

        return response()->json(['data' => [
            ...$this->servicio->filaRenglon($renglon),
            'historial' => $this->servicio->historial($renglon),
        ]]);
    }

    /** Marcar recepción / observación / no aplica (misma regla que la web). */
    public function marcar(MarcarRecepcionRequest $request, RecepcionDocumentalRenglon $renglon): JsonResponse
    {
        $actualizado = $this->servicio->marcar($renglon, $request->user(), $request->estado(), $request->datos());
        $corte = $actualizado->corte->refresh();

        return response()->json(['data' => [
            'renglon' => $this->servicio->filaRenglon($actualizado),
            'metricas' => $this->servicio->metricas($corte, $request->user()),
            'estado_corte' => ['value' => $corte->estado->value, 'label' => $corte->estado->etiqueta()],
        ]]);
    }
}
