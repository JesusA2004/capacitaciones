<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoContratoCorrespondiente;
use App\Enums\ResultadoGeneracionContratos;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contratos\CompletarDatosContratoRequest;
use App\Models\Colaborador;
use App\Models\LoteGeneracionContratos;
use App\Services\Contratos\LoteContratosService;
use App\Services\Contratos\RegularizacionContratosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Módulo «Contratos» (Etapa 2): contratos que le corresponden a cada
 * colaborador activo según su fecha de ingreso, su estado en el expediente y
 * la regularización (individual o «Generar todos» en la cola). Controlador
 * delgado: la lógica vive en RegularizacionContratosService y
 * LoteContratosService.
 */
class ContratosController extends Controller
{
    public function __construct(
        private readonly RegularizacionContratosService $regularizacion,
        private readonly LoteContratosService $lotes,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $usuario = $request->user();
        abort_unless($this->regularizacion->puedeVer($usuario), 403);

        $filtros = $request->only(['busqueda', 'sucursal_id', 'puesto_id', 'estado']);
        $listado = $this->regularizacion->listar($usuario, $filtros);
        $lote = $this->lotes->ultimo();

        return Inertia::render('Rh/Contratos/Index', [
            'filas' => $listado['filas'],
            'resumen' => $listado['resumen'],
            'faltantesComunes' => $listado['faltantes_comunes'],
            'opciones' => [
                ...$this->regularizacion->opcionesFiltro($usuario),
                'estados' => array_map(fn (EstadoContratoCorrespondiente $e): array => ['value' => $e->value, 'label' => $e->etiqueta()], EstadoContratoCorrespondiente::deFila()),
            ],
            'filtros' => $filtros,
            'lote' => $lote !== null ? $this->lotes->aArray($lote) : null,
            'puedeGenerar' => $this->regularizacion->puedeGenerar($usuario),
            'puedeCompletar' => $usuario->can('expedientes.editar') || $usuario->can('colaboradores.alta'),
        ]);
    }

    public function generar(Request $request, Colaborador $colaborador): RedirectResponse
    {
        $resultado = $this->regularizacion->generarColaborador($colaborador, $request->user());
        $exito = in_array($resultado['resultado'], [ResultadoGeneracionContratos::Generado->value, ResultadoGeneracionContratos::SinCambios->value], true);
        $mensaje = match ($resultado['resultado']) {
            ResultadoGeneracionContratos::Generado->value => sprintf('Contratos de %s generados: %s.%s', $resultado['nombre'], $resultado['documentos'] !== [] ? implode(', ', $resultado['documentos']) : 'contrato registrado', $resultado['motivo'] !== null ? ' '.$resultado['motivo'] : ''),
            ResultadoGeneracionContratos::SinCambios->value => sprintf('%s ya tiene sus contratos en el expediente.', $resultado['nombre']),
            default => sprintf('%s: %s', $resultado['resultado_etiqueta'], (string) $resultado['motivo']),
        };

        return back()->with('toast', ['type' => $exito ? 'success' : 'error', 'message' => $mensaje]);
    }

    public function generarTodos(Request $request): RedirectResponse
    {
        $this->lotes->iniciar($request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Generación de contratos en proceso. Puedes seguir el avance en esta pantalla.']);
    }

    public function lote(Request $request, LoteGeneracionContratos $lote): JsonResponse
    {
        abort_unless($this->regularizacion->puedeVer($request->user()), 403);

        return response()->json(['data' => $this->lotes->aArray($lote->loadMissing('usuario:id,name,apellidos'))]);
    }

    public function vistaPrevia(Request $request, Colaborador $colaborador, string $clave): Response
    {
        // ?pendientes=1 (expediente): modo permisivo, cada dato faltante se
        // imprime «PENDIENTE: <dato>». Generar sigue siendo estricto.
        $previa = $this->regularizacion->vistaPrevia($colaborador, $clave, $request->user(), marcarPendientes: $request->boolean('pendientes'));
        // Sin «/», «\», «%» ni comillas: no son válidos en Content-Disposition.
        $nombre = sprintf('Vista previa - %s.pdf', preg_replace('#[/\\\\%"]+#', '-', $previa['nombre']));

        return response($previa['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $nombre, Str::ascii($nombre)),
            'Cache-Control' => 'no-store',
            'X-Datos-Faltantes' => (string) $previa['faltantes'],
        ]);
    }

    public function completarDatos(CompletarDatosContratoRequest $request, Colaborador $colaborador): RedirectResponse
    {
        $this->regularizacion->completarDatos($colaborador, $request->datos(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('Datos de %s guardados en su expediente.', $colaborador->nombreCompleto())]);
    }
}
