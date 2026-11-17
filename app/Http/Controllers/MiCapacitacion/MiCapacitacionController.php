<?php

namespace App\Http\Controllers\MiCapacitacion;

use App\Enums\TipoLeccion;
use App\Http\Controllers\Controller;
use App\Models\Certificado;
use App\Models\Curso;
use App\Models\Leccion;
use App\Services\Capacitacion\MiCapacitacionService;
use App\Services\Capacitacion\ProgresoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MiCapacitacionController extends Controller
{
    public function __construct(
        private readonly ProgresoService $progresoService,
        private readonly MiCapacitacionService $miCapacitacion,
    ) {}

    /**
     * «Mi capacitación»: mis aulas, mis pendientes y mis certificados en una
     * sola pantalla con tres secciones (cada una con su URL para que el menú
     * y los avisos puedan abrir directo la que corresponde).
     */
    public function index(Request $request): Response
    {
        return $this->panel($request, 'aulas');
    }

    public function pendientes(Request $request): Response
    {
        return $this->panel($request, 'pendientes');
    }

    public function certificados(Request $request): Response
    {
        return $this->panel($request, 'certificados');
    }

    private function panel(Request $request, string $seccion): Response
    {
        $usuario = $request->user();

        return Inertia::render('MiCapacitacion/Index', [
            'seccion' => $seccion,
            'panel' => Inertia::defer(fn () => $this->miCapacitacion->panel($usuario)),
        ]);
    }

    public function show(Request $request, Curso $curso): Response
    {
        $usuario = $request->user();

        $inscripcion = $usuario->inscripcionesCurso()->where('curso_id', $curso->id)->firstOrFail();

        $curso->load(['modulos.lecciones.requisitos:id,titulo', 'modulos.lecciones.recursoMultimedia:id,duracion_segundos']);

        // ProgresoService::estadoBloqueoLeccion() necesita subir de leccion a
        // modulo y de modulo a curso (para revisar requiere_orden y el resto
        // de la secuencia). Eloquent no hidrata automaticamente esa relacion
        // inversa al cargar curso->modulos->lecciones, asi que sin esto cada
        // leccion dispararia sus propias consultas para resolverla (N+1).
        foreach ($curso->modulos as $modulo) {
            $modulo->setRelation('curso', $curso);

            foreach ($modulo->lecciones as $leccion) {
                $leccion->setRelation('modulo', $modulo);
            }
        }

        $lecciones = $curso->modulos->flatMap(fn ($modulo) => $modulo->lecciones);

        $estadoLecciones = $lecciones->mapWithKeys(function (Leccion $leccion) use ($usuario) {
            $bloqueo = $this->progresoService->estadoBloqueoLeccion($usuario, $leccion);

            return [$leccion->id => [
                'completada' => $this->progresoService->leccionCompletada($usuario, $leccion),
                'bloqueada' => $bloqueo['bloqueada'],
                'motivo_bloqueo' => $bloqueo['motivo'],
            ]];
        });

        $certificado = Certificado::query()->where('inscripcion_curso_id', $inscripcion->id)->first();

        return Inertia::render('MiCapacitacion/Show', [
            'curso' => $curso,
            'inscripcion' => $inscripcion,
            'estadoLecciones' => $estadoLecciones,
            'certificado' => $certificado,
        ]);
    }

    public function completarLeccion(Request $request, Leccion $leccion): RedirectResponse
    {
        $usuario = $request->user();
        $curso = $leccion->modulo->curso;

        $tieneAcceso = $usuario->inscripcionesCurso()->where('curso_id', $curso->id)->exists();

        if (! $tieneAcceso) {
            abort(403, 'No tienes esta lección asignada.');
        }

        if ($leccion->tipo === TipoLeccion::Video) {
            return back()->with('toast', ['type' => 'error', 'message' => 'Las lecciones de video se completan automáticamente al verlas.']);
        }

        if ($leccion->tipo === TipoLeccion::Cuestionario) {
            return back()->with('toast', ['type' => 'error', 'message' => 'Las lecciones de cuestionario se completan al aprobar un intento.']);
        }

        if ($leccion->tipo === TipoLeccion::SesionEnVivo) {
            return back()->with('toast', ['type' => 'error', 'message' => 'Las sesiones en vivo se completan cuando el instructor registra tu asistencia.']);
        }

        try {
            $this->progresoService->completarLeccion($usuario, $leccion);
        } catch (\RuntimeException $excepcion) {
            return back()->with('toast', ['type' => 'error', 'message' => $excepcion->getMessage()]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'Lección completada.']);
    }
}
