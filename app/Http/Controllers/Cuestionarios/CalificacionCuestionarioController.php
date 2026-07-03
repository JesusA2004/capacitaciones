<?php

namespace App\Http\Controllers\Cuestionarios;

use App\Enums\EstadoIntentoCuestionario;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuestionarios\CalificarRespuestaRequest;
use App\Models\IntentoCuestionario;
use App\Models\RespuestaCuestionario;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Evaluacion\IntentoCuestionarioService;
use App\Services\Multimedia\MediaStorageService;
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

        $intentos = IntentoCuestionario::query()
            ->where('estado', EstadoIntentoCuestionario::Enviado->value)
            ->when($idsColaboradores !== null, fn ($query) => $query->whereIn('user_id', $idsColaboradores))
            ->with(['usuario:id,name,apellidos', 'cuestionario:id,titulo,leccion_id'])
            ->orderBy('enviado_en')
            ->paginate(15);

        return Inertia::render('Cuestionarios/Calificaciones/Index', [
            'intentos' => $intentos,
        ]);
    }

    public function show(Request $request, IntentoCuestionario $intento): Response
    {
        $this->authorize('respuestas.ver');
        $this->autorizarAlcance($request, $intento);

        $intento->load(['usuario:id,name,apellidos', 'cuestionario:id,titulo', 'respuestas.pregunta.opciones']);

        return Inertia::render('Cuestionarios/Calificaciones/Show', [
            'intento' => $intento,
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
