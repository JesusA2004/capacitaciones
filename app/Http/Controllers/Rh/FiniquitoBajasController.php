<?php

namespace App\Http\Controllers\Rh;

use App\Enums\FormaPagoFiniquito;
use App\Enums\TipoFiniquito;
use App\Http\Controllers\Controller;
use App\Http\Requests\CicloLaboral\EntregaFiniquitoRequest;
use App\Http\Requests\Rh\AjustarConceptoFiniquitoRequest;
use App\Http\Requests\Rh\CorregirFiniquitoRequest;
use App\Http\Requests\Rh\SubirFiniquitoFirmadoRequest;
use App\Models\FiniquitoCalculo;
use App\Models\Sucursal;
use App\Services\Bajas\BajaConsultaService;
use App\Services\Bajas\TableroRotacionService;
use App\Services\Finiquitos\FiniquitoConsultaService;
use App\Services\Finiquitos\FiniquitoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Bajas → pestaña «Finiquitos». Delgado: autoriza con FiniquitoCalculoPolicy
 * y delega en FiniquitoConsultaService / FiniquitoService (mismo registro que
 * el expediente de la baja).
 */
class FiniquitoBajasController extends Controller
{
    public function __construct(
        private readonly FiniquitoConsultaService $consulta,
        private readonly FiniquitoService $finiquitos,
        private readonly BajaConsultaService $bajas,
        private readonly TableroRotacionService $tablero,
    ) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();
        abort_unless($usuario->can('finiquitos.ver'), 403);
        $filtros = $request->only(['busqueda', 'estado', 'sucursal_id', 'tipo', 'per_page']);
        $seleccionado = is_numeric($request->query('finiquito')) ? FiniquitoCalculo::query()->where('id', (int) $request->query('finiquito'))->first() : null;

        return Inertia::render('Rh/Bajas/Finiquitos', [
            'pestanas' => $this->bajas->pestanas($usuario),
            'finiquitos' => $this->consulta->listado($usuario, $filtros),
            'resumen' => $this->consulta->resumen($usuario),
            'seleccionado' => $seleccionado !== null && $usuario->can('ver', $seleccionado) ? $this->consulta->detalle($seleccionado, $usuario) : null,
            'filtros' => [
                'busqueda' => (string) ($filtros['busqueda'] ?? ''),
                'estado' => is_string($filtros['estado'] ?? null) ? $filtros['estado'] : null,
                'sucursal_id' => is_numeric($filtros['sucursal_id'] ?? null) ? (int) $filtros['sucursal_id'] : null,
                'tipo' => is_string($filtros['tipo'] ?? null) ? $filtros['tipo'] : null,
            ],
            'sucursales' => Sucursal::query()->whereIn('id', $this->tablero->sucursalesVisibles($usuario)->all())->orderBy('nombre')->get(['id', 'nombre'])
                ->map(fn (Sucursal $s): array => ['value' => $s->id, 'label' => $s->nombre])->values()->all(),
            'formas_pago' => array_map(fn (FormaPagoFiniquito $f): array => ['value' => $f->value, 'label' => $f->etiqueta()], FormaPagoFiniquito::cases()),
        ]);
    }

    public function recalcular(Request $request, FiniquitoCalculo $finiquito): RedirectResponse
    {
        $this->authorize('editarAjustes', $finiquito);
        $this->finiquitos->recalcular($finiquito, $request->user(), (float) $finiquito->sueldo_mensual, (float) $finiquito->sueldo_pendiente);

        return $this->ok('Finiquito recalculado: vuelve a «Calculado» y conserva los ajustes.');
    }

    public function revisar(Request $request, FiniquitoCalculo $finiquito): RedirectResponse
    {
        $this->authorize('revisar', $finiquito);
        $this->finiquitos->aprobarCalculo($finiquito, $request->user());

        return $this->ok('Finiquito marcado como revisado.');
    }

    public function ajustar(AjustarConceptoFiniquitoRequest $request, FiniquitoCalculo $finiquito): RedirectResponse
    {
        $this->authorize('editarAjustes', $finiquito);
        $this->finiquitos->ajustarConceptoAutomatico($finiquito, (string) $request->validated('concepto_clave'), (float) $request->validated('importe'), (string) $request->validated('motivo'), $request->user());

        return $this->ok('Importe ajustado; quedó en la bitácora y el total se recalculó.');
    }

    /** Regenera el PDF con los importes que RH está corrigiendo; no guarda nada. */
    public function vistaPrevia(CorregirFiniquitoRequest $request, FiniquitoCalculo $finiquito): HttpResponse
    {
        $this->authorize('editarAjustes', $finiquito);

        return response($this->finiquitos->vistaPreviaCorregida($finiquito, $request->cambios()), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Vista previa - Finiquito.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function corregir(CorregirFiniquitoRequest $request, FiniquitoCalculo $finiquito): RedirectResponse
    {
        $this->authorize('editarAjustes', $finiquito);
        $this->consulta->corregir($finiquito, $request->cambios(), (string) $request->input('motivo'), $request->user());

        return $this->ok('Correcciones guardadas: el total se recalculó y el finiquito vuelve a «Calculado» para revisarse.');
    }

    public function cambiarTipo(Request $request, FiniquitoCalculo $finiquito): RedirectResponse
    {
        $this->authorize('editarAjustes', $finiquito);
        $datos = $request->validate([
            'tipo' => ['required', Rule::enum(TipoFiniquito::class)],
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ], ['motivo.required' => 'Indica el motivo del cambio de tipo.', 'motivo.min' => 'Indica el motivo del cambio de tipo.']);
        $this->finiquitos->cambiarTipo($finiquito, TipoFiniquito::from($datos['tipo']), $datos['motivo'], $request->user());

        return $this->ok('Tipo del documento actualizado; el cambio quedó en la bitácora.');
    }

    public function recibo(Request $request, FiniquitoCalculo $finiquito): HttpResponse
    {
        $this->authorize('descargar', $finiquito);

        return $this->consulta->recibo($finiquito, $request->user());
    }

    public function subirFirmado(SubirFiniquitoFirmadoRequest $request, FiniquitoCalculo $finiquito): RedirectResponse
    {
        $this->authorize('subirFirmado', $finiquito);
        $archivo = $request->file('archivo');
        abort_unless($archivo instanceof UploadedFile, 422, 'Elige un archivo.');
        $this->consulta->subirFirmado($finiquito, $archivo, $request->user());

        return $this->ok('Recibo firmado guardado: el finiquito pasó a «Firmado».');
    }

    public function registrarPago(EntregaFiniquitoRequest $request, FiniquitoCalculo $finiquito): RedirectResponse
    {
        abort_unless($this->consulta->puedeRegistrarPago($finiquito, $request->user()), 403);
        $this->consulta->registrarPago($finiquito, $request->user(), $request->datosEntrega());

        return $this->ok('Pago registrado: el finiquito quedó «Pagado» y es de solo lectura.');
    }

    private function ok(string $mensaje): RedirectResponse
    {
        return back()->with('toast', ['type' => 'success', 'message' => $mensaje]);
    }
}
