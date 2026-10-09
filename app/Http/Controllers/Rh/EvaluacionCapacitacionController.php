<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoEvaluacionPrueba;
use App\Http\Controllers\Controller;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\GeneratedDocument;
use App\Services\Contratos\EvaluacionPeriodoPruebaService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Evaluación de capacitación inicial»: tablero de las evaluaciones que se
 * abren 15 días antes de que venza el contrato de capacitación
 * (VencimientoContratosService). La captura y la autorización viven en el
 * ciclo de cada persona; aquí se ve qué toca, a quién y cuándo. Misma
 * fuente (EvaluacionPeriodoPruebaService) que la API.
 */
class EvaluacionCapacitacionController extends Controller
{
    public function __construct(private readonly EvaluacionPeriodoPruebaService $evaluaciones) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();
        abort_unless($usuario->can('evaluaciones.capturar') || $usuario->can('evaluaciones.autorizar'), 403);

        $estado = EstadoEvaluacionPrueba::tryFrom($request->string('estado')->toString());
        $pagina = $this->evaluaciones->listar($usuario, ['estado' => $estado?->value, 'per_page' => 30])->withQueryString();
        $hoy = Carbon::today('America/Mexico_City');

        // Una sola consulta por relación (antes: loadMissing por fila).
        (new EloquentCollection($pagina->items()))->load(['colaborador.puesto:id,nombre', 'colaborador.sucursalPrincipal:id,nombre']);

        // «✓ Evaluación aprobada · Contrato indeterminado listo [Previsualizar]
        // [Imprimir]»: el documento vigente del contrato de renovación (el
        // contrato se crea una sola vez; aquí solo se localiza su PDF).
        $idsRenovacion = $pagina->getCollection()->pluck('contrato_renovacion_id')->filter()->values();
        $documentos = ContratoLaboral::query()
            ->with('documento:id,original_name')
            ->whereIn('id', $idsRenovacion)
            ->get(['id', 'generated_document_id'])
            ->mapWithKeys(fn (ContratoLaboral $c) => [$c->id => $c->documento]);
        // Respaldo: contratos cuyo PDF quedó ligado solo por documentable.
        $sinDocumento = $idsRenovacion->filter(fn ($id) => $documentos->get($id) === null)->values();

        if ($sinDocumento->isNotEmpty()) {
            GeneratedDocument::query()
                ->where('documentable_type', (new ContratoLaboral)->getMorphClass())
                ->whereIn('documentable_id', $sinDocumento)
                ->orderByDesc('id')
                ->get(['id', 'documentable_id', 'original_name'])
                ->unique('documentable_id')
                ->each(function (GeneratedDocument $d) use ($documentos): void {
                    $documentos->put($d->documentable_id, $d);
                });
        }

        $pagina->getCollection()->transform(function (EvaluacionPeriodoPrueba $e) use ($hoy, $documentos) {
            $datos = $this->evaluaciones->aArray($e);
            $documento = $e->contrato_renovacion_id !== null ? $documentos->get($e->contrato_renovacion_id) : null;

            return [
                ...$datos,
                'puesto' => $e->colaborador->puesto?->nombre,
                'sucursal' => $e->colaborador->sucursalPrincipal?->nombre,
                'dias_restantes' => $e->fecha_limite !== null ? (int) $hoy->diffInDays($e->fecha_limite, false) : null,
                'url' => route('rh.colaboradores.ciclo', $e->colaborador_id),
                'documento_renovacion' => $documento instanceof GeneratedDocument ? [
                    'id' => $documento->id,
                    'nombre' => $documento->original_name,
                    'url' => route('rh.documentos-laborales.descargar', $documento->id),
                ] : null,
            ];
        });

        return Inertia::render('Rh/Evaluaciones/Index', [
            'evaluaciones' => $pagina,
            'estados' => array_map(fn (EstadoEvaluacionPrueba $e) => ['value' => $e->value, 'label' => $e->etiqueta()], EstadoEvaluacionPrueba::cases()),
            'filtros' => ['estado' => $estado?->value],
            'puedeAutorizar' => $usuario->can('evaluaciones.autorizar'),
        ]);
    }
}
