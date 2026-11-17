<?php

namespace App\Http\Controllers\Cuestionarios;

use App\Enums\EstadoIntentoCuestionario;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuestionarios\CalificarRespuestaRequest;
use App\Models\Curso;
use App\Models\IntentoCuestionario;
use App\Models\Leccion;
use App\Models\RespuestaCuestionario;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Evaluacion\IntentoCuestionarioService;
use App\Services\Multimedia\MediaStorageService;
use App\Support\Paginacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Calificacion manual de las respuestas de tipo "respuesta_corta" que
 * quedaron pendientes tras enviarse un intento (las de opcion/verdadero-falso
 * ya se calificaron solas). Un intento pasa a "calificado" automaticamente en
 * cuanto su ultima respuesta pendiente recibe calificacion.
 */
class CalificacionCuestionarioController extends Controller
{
    public function __construct(
        private readonly IntentoCuestionarioService $intentos,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly MediaStorageService $storage,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('respuestas.ver');

        $idsColaboradores = $this->alcance->idsColaboradoresParaRevision($request->user());

        // ?curso= llega desde el aula («Calificar los de este curso»).
        $curso = $request->integer('curso') > 0
            ? Curso::query()->where('id', $request->integer('curso'))->first(['id', 'titulo'])
            : null;

        $intentos = IntentoCuestionario::query()
            ->where('estado', EstadoIntentoCuestionario::Enviado->value)
            ->when($idsColaboradores !== null, fn ($query) => $query->whereIn('user_id', $idsColaboradores))
            ->when($curso !== null, fn ($query) => $query->whereHas('cuestionario.leccion.modulo', fn ($q) => $q->where('curso_id', $curso?->id)))
            ->with(['usuario:id,name,apellidos', 'cuestionario:id,titulo,leccion_id', 'cuestionario.leccion:id,curso_modulo_id,titulo', 'cuestionario.leccion.curso'])
            ->orderBy('enviado_en')
            ->paginate(Paginacion::porPagina())
            ->withQueryString()
            ->through(fn (IntentoCuestionario $intento): array => [
                'id' => $intento->id,
                'numero_intento' => $intento->numero_intento,
                'estado' => $intento->estado,
                'enviado_en' => $intento->enviado_en?->toIso8601String(),
                'usuario' => $intento->usuario?->only(['id', 'name', 'apellidos']),
                'cuestionario' => $intento->cuestionario?->only(['id', 'titulo', 'leccion_id']),
                'curso' => $this->cursoDe($intento->cuestionario?->leccion),
            ]);

        return Inertia::render('Cuestionarios/Calificaciones/Index', [
            'intentos' => $intentos,
            'cursoFiltro' => $curso === null ? null : ['id' => $curso->id, 'titulo' => $curso->titulo],
        ]);
    }

    /**
     * @return array{id: int, titulo: string}|null
     */
    private function cursoDe(?Leccion $leccion): ?array
    {
        $curso = $leccion?->curso;

        return $curso === null ? null : ['id' => $curso->id, 'titulo' => $curso->titulo];
    }

    public function show(Request $request, IntentoCuestionario $intento): Response
    {
        $this->authorize('respuestas.ver');
        $this->autorizarAlcance($request, $intento);

        $intento->load(['usuario:id,name,apellidos', 'cuestionario:id,titulo,leccion_id', 'cuestionario.leccion:id,curso_modulo_id,titulo', 'cuestionario.leccion.curso', 'respuestas.pregunta.opciones']);

        return Inertia::render('Cuestionarios/Calificaciones/Show', [
            'intento' => $intento,
            'curso' => $this->cursoDe($intento->cuestionario?->leccion),
        ]);
    }

    public function calificar(CalificarRespuestaRequest $request, RespuestaCuestionario $respuesta): RedirectResponse
    {
        $respuesta->loadMissing('intento.usuario');
        $this->autorizarAlcance($request, $respuesta->intento);

        $this->intentos->calificarRespuestaManual(
            $respuesta,
            $request->boolean('es_correcta'),
            $request->input('puntos_obtenidos'),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Respuesta calificada correctamente.']);
    }

    public function descargar(Request $request, RespuestaCuestionario $respuesta): StreamedResponse
    {
        $this->authorize('respuestas.ver');
        $respuesta->loadMissing(['intento.usuario', 'recursoMultimedia']);
        $this->autorizarAlcance($request, $respuesta->intento);

        abort_unless($respuesta->recursoMultimedia !== null, 404);

        return $this->storage->respuesta($respuesta->recursoMultimedia->ruta_original, [
            'Content-Disposition' => 'attachment; filename="'.$respuesta->recursoMultimedia->nombre_original.'"',
        ]);
    }

    private function autorizarAlcance(Request $request, IntentoCuestionario $intento): void
    {
        $intento->loadMissing('usuario');

        abort_unless($this->alcance->puedeRevisarColaborador($request->user(), $intento->usuario), 403);
    }
}
