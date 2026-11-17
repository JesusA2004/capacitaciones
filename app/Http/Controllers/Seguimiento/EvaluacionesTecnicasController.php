<?php

namespace App\Http\Controllers\Seguimiento;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\EvaluacionTecnica;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\AlcanceOrganizacionalService;
use App\Services\EvaluacionesTecnicas\EvaluacionTecnicaService;
use App\Services\EvaluacionesTecnicas\ProgramacionEvaluacionTecnicaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Evaluaciones técnicas por puesto («Seguimiento 15 / 30 / 60…»):
 * tablero, línea de tiempo por colaborador, resultado y contestar. Delgado:
 * reglas y alcance en App\Services\EvaluacionesTecnicas\*; autorización en
 * EvaluacionTecnicaPolicy (docs/EVALUACIONES_TECNICAS.md).
 */
class EvaluacionesTecnicasController extends Controller
{
    public function __construct(
        private readonly EvaluacionTecnicaService $evaluaciones,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', EvaluacionTecnica::class);
        $usuario = $request->user();
        $filtros = [
            'buscar' => $request->string('buscar')->trim()->value() ?: null,
            'estado' => $request->string('estado')->value() ?: null,
            'puesto_id' => $request->integer('puesto') ?: null,
            'sucursal_id' => $request->integer('sucursal') ?: null,
            'contesta' => $request->string('contesta')->value() ?: null,
        ];

        return Inertia::render('Seguimiento/EvaluacionesTecnicas/Index', [
            'filtros' => [
                'buscar' => $filtros['buscar'],
                'estado' => $filtros['estado'],
                'puesto' => $filtros['puesto_id'],
                'sucursal' => $filtros['sucursal_id'],
                'contesta' => $filtros['contesta'],
            ],
            'puestos' => Puesto::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre'])
                ->map(fn (Puesto $p): array => ['value' => $p->id, 'label' => $p->nombre])->values(),
            'sucursales' => Sucursal::query()->whereIn('id', $this->alcance->sucursalesVisiblesIds($usuario))->orderBy('nombre')->get(['id', 'nombre'])
                ->map(fn (Sucursal $s): array => ['value' => $s->id, 'label' => $s->nombre])->values(),
            'puedeConfigurar' => $usuario->can('configurar', EvaluacionTecnica::class),
            'porEvaluar' => $this->evaluaciones->porEvaluar($usuario),
            'tablero' => Inertia::defer(fn (): array => $this->evaluaciones->tablero($usuario, $filtros)),
        ]);
    }

    public function colaborador(Request $request, Colaborador $colaborador): Response
    {
        $this->authorize('viewAny', EvaluacionTecnica::class);
        abort_unless($this->alcance->alcanzaColaboradorEnModulo($request->user(), $colaborador, EvaluacionTecnicaService::MODULO), 403);

        return Inertia::render('Seguimiento/EvaluacionesTecnicas/Colaborador', [
            'linea' => $this->evaluaciones->lineaDeTiempo($colaborador),
        ]);
    }

    public function show(Request $request, EvaluacionTecnica $evaluacion): Response
    {
        $this->authorize('view', $evaluacion);
        $usuario = $request->user();

        return Inertia::render('Seguimiento/EvaluacionesTecnicas/Resultado', [
            'evaluacion' => $usuario->can('verRespuestas', $evaluacion)
                ? $this->evaluaciones->detalle($evaluacion, true)
                : $this->evaluaciones->detalleParaDueno($evaluacion),
            'puedeContestar' => $usuario->can('contestar', $evaluacion),
            'puedeVerLinea' => $usuario->can('viewAny', EvaluacionTecnica::class)
                && $this->alcance->alcanzaColaboradorEnModulo($usuario, $evaluacion->colaborador, EvaluacionTecnicaService::MODULO),
        ]);
    }

    public function contestar(Request $request, EvaluacionTecnica $evaluacion): Response|RedirectResponse
    {
        $this->authorize('view', $evaluacion);

        if (! $request->user()->can('contestar', $evaluacion)) {
            return to_route('evaluaciones-tecnicas.show', $evaluacion->id);
        }

        $evaluacion->loadMissing(['colaborador.puesto:id,nombre', 'colaborador.sucursalPrincipal:id,nombre']);

        return Inertia::render('Seguimiento/EvaluacionesTecnicas/Contestar', [
            'evaluacion' => $this->evaluaciones->detalle($evaluacion, false),
            'contenido' => $this->evaluaciones->contenido($evaluacion),
            'esPropia' => $request->user()->colaborador_id === $evaluacion->colaborador_id,
        ]);
    }

    public function responder(Request $request, EvaluacionTecnica $evaluacion): RedirectResponse
    {
        $this->authorize('contestar', $evaluacion);
        $datos = $request->validate(
            ['respuestas' => ['present', 'array'], 'comentarios' => ['nullable', 'string', 'max:3000']],
            ['comentarios.max' => 'Los comentarios pueden tener hasta 3000 caracteres.'],
        );

        $contestada = $this->evaluaciones->contestar($evaluacion, $request->user(), (array) $datos['respuestas'], $datos['comentarios'] ?? null);

        return to_route('evaluaciones-tecnicas.show', $contestada->id)->with('toast', [
            'type' => 'success',
            'message' => sprintf('Listo: «%s» quedó contestada.', $contestada->nombre),
        ]);
    }

    /** «Generar ahora»: corre el programador (idempotente) sin esperar al de la madrugada. */
    public function programar(Request $request, ProgramacionEvaluacionTecnicaService $programacion): RedirectResponse
    {
        $this->authorize('configurar', EvaluacionTecnica::class);
        $resultado = $programacion->programar();

        $mensaje = $resultado['generadas'] > 0
            ? sprintf('Se generaron %d evaluaciones técnicas y se avisó a quien las contesta.', $resultado['generadas'])
            : 'No había evaluaciones nuevas por generar: el calendario ya está al día.';

        if ($resultado['sin_formulario'] > 0) {
            $mensaje .= sprintf(' %d no se generaron porque su formulario no tiene versión publicada.', $resultado['sin_formulario']);
        }

        return back()->with('toast', ['type' => $resultado['sin_formulario'] > 0 ? 'warning' : 'success', 'message' => $mensaje]);
    }
}
