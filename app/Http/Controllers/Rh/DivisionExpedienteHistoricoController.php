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
use App\Services\Expedientes\DivisionHistorico\EditorPaginasDivisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
        private readonly EditorPaginasDivisionService $editor,
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
     * Editor de hojas del PDF histórico (asignar por hoja, continuar, partir,
     * mover, reordenar). Nada cambia en el expediente hasta «Guardar».
     */
    public function editor(Request $request, DivisionExpedienteHistorico $division): Response|RedirectResponse
    {
        $colaborador = $division->colaborador;
        $usuario = $this->exigir($request, $colaborador, 'rh.documentos.extraccion.ver');

        try {
            $estado = $this->editor->estado($division);
        } catch (ValidationException $e) {
            return redirect()->route('rh.expedientes.division-historico.show', $colaborador->id)
                ->with('toast', ['type' => 'error', 'message' => collect($e->errors())->flatten()->first() ?? 'Esta división no se puede corregir.']);
        }

        return Inertia::render('Rh/Expedientes/EditorHojasDivision', [
            'colaborador' => [
                'id' => $colaborador->id,
                'nombre' => $colaborador->nombreCompleto(),
                'numero_empleado' => $colaborador->numero_empleado,
                'division_url' => route('rh.expedientes.division-historico.show', $colaborador->id),
            ],
            ...$estado,
            'permisos' => ['guardar' => $usuario->can('expedientes.editar')],
        ]);
    }

    public function vistaPrevia(Request $request, DivisionExpedienteHistorico $division): JsonResponse
    {
        $this->exigir($request, $division->colaborador, 'rh.documentos.extraccion.ver');
        $previa = $this->editor->vistaPrevia($division, $this->planDe($request));

        return response()->json([
            ...$previa,
            'documentos' => array_map(fn (array $d): array => [
                ...$d,
                'accion' => $d['accion']->value,
                'accion_etiqueta' => $d['accion']->etiqueta(),
                'pdf_url' => route('rh.expedientes.division-historico.editor.pdf', ['division' => $division->id, 'paginas' => implode(',', $d['paginas'])]),
            ], $previa['documentos']),
        ]);
    }

    public function pdfVistaPrevia(Request $request, DivisionExpedienteHistorico $division): HttpResponse
    {
        $this->exigir($request, $division->colaborador, 'rh.documentos.extraccion.ver');
        $datos = $request->validate(['paginas' => ['required', 'string', 'max:2000', 'regex:/^\d+(,\d+)*$/']]);
        $paginas = array_map('intval', explode(',', $datos['paginas']));

        return response($this->editor->pdfVistaPrevia($division, $paginas), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="vista-previa.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=600',
        ]);
    }

    public function confirmarEditor(Request $request, DivisionExpedienteHistorico $division): RedirectResponse
    {
        $usuario = $this->exigir($request, $division->colaborador, 'expedientes.editar');
        $documentos = $this->planDe($request, true);

        $revision = $this->editor->confirmar($division, $documentos, $request->integer('revision_base'), $usuario);

        return back()->with('toast', $revision === null
            ? ['type' => 'info', 'message' => 'No había cambios: el expediente quedó igual.']
            : ['type' => 'success', 'message' => sprintf('Corrección guardada (revisión %d): %d hoja(s) reasignada(s).', $revision->version, count($revision->cambios))]);
    }

    /**
     * Plan del editor ([{document_type_id, paginas}]) validado en forma; las
     * reglas de negocio (cada hoja una vez, tipos del checklist) las valida
     * EditorPaginasDivisionService.
     *
     * @return array<int|string, mixed>
     */
    private function planDe(Request $request, bool $conRevision = false): array
    {
        $datos = $request->validate([
            'documentos' => ['required', 'array', 'min:1', 'max:1000'],
            'documentos.*.document_type_id' => ['nullable', 'integer'],
            'documentos.*.paginas' => ['required', 'array', 'min:1'],
            'documentos.*.paginas.*' => ['integer', 'min:1'],
            ...($conRevision ? ['revision_base' => ['required', 'integer', 'min:0']] : []),
        ]);

        return is_array($datos['documentos'] ?? null) ? $datos['documentos'] : [];
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
