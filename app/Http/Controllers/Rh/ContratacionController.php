<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Services\CicloLaboral\ContratacionListaService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bandejas-lista de la Etapa 2 (contratación y expediente). Controlador
 * delgado: toda la lógica vive en ContratacionListaService, que reutiliza los
 * servicios del ciclo, del expediente y de los contratos — nunca se duplica
 * una regla de negocio aquí.
 */
class ContratacionController extends Controller
{
    public function __construct(
        private readonly ContratacionListaService $lista,
    ) {}

    /**
     * «Personas en contratación» (absorbe las altas digitales pendientes).
     */
    public function index(Request $request): Response
    {
        $usuario = $request->user();

        abort_unless($usuario->can('contratos.ver'), 403);

        $filtros = $request->only(['busqueda', 'sucursal_id']);

        return Inertia::render('Rh/Contratacion/Index', [
            'personas' => $this->lista->personasEnContratacion($usuario, $filtros),
            'altasDigitales' => $this->lista->altasDigitalesPendientes($usuario),
            'filtros' => $filtros,
        ]);
    }

    /**
     * «Validación de documentos»: bandeja de documentos de expediente por
     * revisar. Las acciones (aprobar/rechazar/solicitar corrección) se postean
     * a los endpoints rh.documentos.* ya existentes, con su propia Policy.
     */
    public function validacionDocumentos(Request $request): Response
    {
        $usuario = $request->user();

        abort_unless($usuario->can('documentos.revisar'), 403);

        $filtros = $request->only(['busqueda', 'sucursal_id']);

        return Inertia::render('Rh/Contratacion/ValidacionDocumentos', [
            'documentos' => $this->lista->documentosPorRevisar($usuario, $filtros),
            'filtros' => $filtros,
            'puedeAprobar' => $usuario->can('documentos.aprobar'),
        ]);
    }

    /**
     * «Contratos de ingreso»: contrato vigente y documentos laborales iniciales
     * por persona en contratación. Solo lectura; enlaza a la generación y
     * gestión física por-persona (Documentos del proceso).
     */
    public function contratosIngreso(Request $request): Response
    {
        $usuario = $request->user();

        abort_unless($usuario->can('contratos.ver'), 403);

        $filtros = $request->only(['busqueda', 'sucursal_id']);

        return Inertia::render('Rh/Contratacion/ContratosIngreso', [
            'contratos' => $this->lista->contratosDeIngreso($usuario, $filtros),
            'filtros' => $filtros,
        ]);
    }
}
