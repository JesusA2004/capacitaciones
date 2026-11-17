<?php

namespace App\Http\Controllers\Actividades;

use App\Enums\EstadoEntregaActividad;
use App\Http\Controllers\Controller;
use App\Http\Requests\Actividades\CalificarEntregaActividadRequest;
use App\Models\Curso;
use App\Models\EntregaActividad;
use App\Models\Leccion;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Evaluacion\EntregaActividadService;
use App\Services\Multimedia\MediaStorageService;
use App\Support\Paginacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CalificacionActividadController extends Controller
{
    public function __construct(
        private readonly EntregaActividadService $service,
        private readonly MediaStorageService $storage,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('respuestas.ver');

        $idsColaboradores = $this->alcance->idsColaboradoresParaRevision($request->user());

        // ?curso= llega desde el aula («Calificar los de este curso»).
        $curso = $request->integer('curso') > 0
            ? Curso::query()->where('id', $request->integer('curso'))->first(['id', 'titulo'])
            : null;

        $entregas = EntregaActividad::query()
            ->where('estado', EstadoEntregaActividad::Entregada->value)
            ->when($idsColaboradores !== null, fn ($query) => $query->whereIn('user_id', $idsColaboradores))
            ->when($curso !== null, fn ($query) => $query->whereHas('actividad.leccion.modulo', fn ($q) => $q->where('curso_id', $curso?->id)))
            ->with(['usuario:id,name,apellidos', 'actividad:id,titulo,leccion_id', 'actividad.leccion:id,curso_modulo_id,titulo', 'actividad.leccion.curso'])
            ->orderBy('entregado_en')
            ->paginate(Paginacion::porPagina())
            ->withQueryString()
            ->through(fn (EntregaActividad $entrega): array => [
                'id' => $entrega->id,
                'version' => $entrega->version,
                'entregado_en' => $entrega->entregado_en->toIso8601String(),
                'usuario' => $entrega->usuario?->only(['id', 'name', 'apellidos']),
                'actividad' => $entrega->actividad?->only(['id', 'titulo', 'leccion_id']),
                'curso' => $this->cursoDe($entrega->actividad?->leccion),
            ]);

        return Inertia::render('Actividades/Calificaciones/Index', [
            'entregas' => $entregas,
            'cursoFiltro' => $curso === null ? null : ['id' => $curso->id, 'titulo' => $curso->titulo],
        ]);
    }

    public function show(Request $request, EntregaActividad $entrega): Response
    {
        $this->authorize('respuestas.ver');
        $this->autorizarAlcance($request, $entrega);

        $entrega->load(['usuario:id,name,apellidos', 'actividad', 'actividad.leccion:id,curso_modulo_id,titulo', 'actividad.leccion.curso', 'recursoMultimedia:id,nombre_original']);

        return Inertia::render('Actividades/Calificaciones/Show', [
            'entrega' => $entrega,
            'curso' => $this->cursoDe($entrega->actividad?->leccion),
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

    public function descargar(Request $request, EntregaActividad $entrega): StreamedResponse
    {
        $this->authorize('respuestas.ver');
        $this->autorizarAlcance($request, $entrega);

        $entrega->loadMissing('recursoMultimedia');
        abort_unless($entrega->recursoMultimedia !== null, 404);

        return $this->storage->respuesta($entrega->recursoMultimedia->ruta_original, [
            'Content-Disposition' => 'attachment; filename="'.$entrega->recursoMultimedia->nombre_original.'"',
        ]);
    }

    public function calificar(CalificarEntregaActividadRequest $request, EntregaActividad $entrega): RedirectResponse
    {
        $this->autorizarAlcance($request, $entrega);

        $this->service->calificar(
            $entrega,
            $request->boolean('aprobada'),
            $request->input('calificacion'),
            $request->input('retroalimentacion'),
            $request->user(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Entrega calificada correctamente.']);
    }

    /**
     * Un revisor con alcance de sucursal (gerente_sucursal, supervisor) solo
     * puede calificar/ver/descargar entregas de colaboradores de su propia
     * sucursal. Ver AlcanceOrganizacionalService::puedeRevisarColaborador().
     */
    private function autorizarAlcance(Request $request, EntregaActividad $entrega): void
    {
        $entrega->loadMissing('usuario');

        abort_unless($this->alcance->puedeRevisarColaborador($request->user(), $entrega->usuario), 403);
    }
}
