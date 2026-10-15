<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Jobs\DividirExpedienteHistoricoJob;
use App\Models\Colaborador;
use App\Models\DivisionExpedienteHistorico;
use App\Models\DocumentType;
use App\Models\ExpedienteHistorico;
use App\Models\ParteDivisionHistorico;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Expedientes\DivisionHistorico\DivisionExpedienteHistoricoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * RH → Expediente → «Dividir PDF histórico» (docs/DIVISION_EXPEDIENTE_HISTORICO.md).
 * Delgado: todo vive en DivisionExpedienteHistoricoService. Cada endpoint
 * valida permiso + alcance organizacional y que no sea el propio expediente.
 */
class DivisionExpedienteHistoricoController extends Controller
{
    public function __construct(
        private readonly DivisionExpedienteHistoricoService $division,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    public function show(Request $request, Colaborador $colaborador): Response
    {
        $usuario = $this->exigir($request, $colaborador, 'rh.documentos.extraccion.ver');

        return Inertia::render('Rh/Expedientes/DivisionHistorico', [
            'colaborador' => [
                'id' => $colaborador->id,
                'nombre' => $colaborador->nombreCompleto(),
                'numero_empleado' => $colaborador->numero_empleado,
                'sucursal' => $colaborador->sucursalPrincipal?->nombre,
                'expediente_url' => route('rh.expedientes.show', $colaborador->id),
            ],
            'detalle' => $this->division->detalle($colaborador),
            'tiposChecklist' => $this->division->tiposChecklist()->map(fn (DocumentType $t): array => ['id' => $t->id, 'nombre' => $t->nombre])->values()->all(),
            'permisos' => [
                'dividir' => $usuario->can('rh.documentos.extraccion.reprocesar'),
                'asignar' => $usuario->can('expedientes.editar'),
                'aplicar' => $usuario->can('rh.documentos.extraccion.aplicar'),
            ],
        ]);
    }

    public function dividir(Request $request, ExpedienteHistorico $historico): RedirectResponse
    {
        abort_if($historico->colaborador === null, 422, 'Este expediente histórico no está vinculado a un colaborador.');
        $usuario = $this->exigir($request, $historico->colaborador, 'rh.documentos.extraccion.reprocesar');
        abort_unless($this->division->elegible($historico), 422, 'Este expediente histórico no está vinculado a un colaborador.');

        DividirExpedienteHistoricoJob::dispatch($historico, $usuario, $request->boolean('forzar'));

        return back()->with('toast', ['type' => 'success', 'message' => 'Se está dividiendo el PDF histórico. Con muchas páginas escaneadas puede tardar unos minutos; actualiza la página para ver el resultado.']);
    }

    public function verParte(Request $request, ParteDivisionHistorico $parte): StreamedResponse
    {
        $this->exigir($request, $parte->division->colaborador, 'rh.documentos.extraccion.ver');

        return $this->division->respuestaParte($parte);
    }

    public function asignarParte(Request $request, ParteDivisionHistorico $parte): RedirectResponse
    {
        $usuario = $this->exigir($request, $parte->division->colaborador, 'expedientes.editar');
        $datos = $request->validate([
            'document_type_id' => ['required', 'integer', Rule::exists('document_types', 'id')->where('requerido', true)->where('activo', true)],
        ]);

        $tipo = DocumentType::query()->where('id', (int) $datos['document_type_id'])->firstOrFail();
        $this->division->asignarParte($parte, $tipo, $usuario);

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('Parte cargada como «%s», en revisión.', $tipo->nombre)]);
    }

    public function aplicarDato(Request $request, DivisionExpedienteHistorico $division): RedirectResponse
    {
        $usuario = $this->exigir($request, $division->colaborador, 'rh.documentos.extraccion.aplicar');
        $datos = $request->validate([
            'campo' => ['required', 'string', 'max:60'],
            'valor' => ['required', 'string', 'max:255'],
        ]);

        $this->division->aplicarDato($division, $datos['campo'], $datos['valor'], $usuario);

        return back()->with('toast', ['type' => 'success', 'message' => 'Dato aplicado al colaborador.']);
    }

    /**
     * Permiso + alcance organizacional + nunca el propio expediente.
     */
    private function exigir(Request $request, Colaborador $colaborador, string $permiso): User
    {
        $usuario = $request->user();
        abort_unless($usuario instanceof User, 403);
        abort_unless($usuario->can($permiso) && $this->alcance->puedeVerExpediente($usuario, $colaborador), 403);
        abort_if($usuario->colaborador_id !== null && $usuario->colaborador_id === $colaborador->id, 403, 'No puedes revisar tu propio expediente; solicita la corrección a RH.');

        return $usuario;
    }
}
