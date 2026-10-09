<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoLoteNomina;
use App\Enums\PeriodicidadNomina;
use App\Http\Controllers\Controller;
use App\Models\NominaLote;
use App\Models\ReciboNomina;
use App\Services\Nomina\LoteNominaService;
use App\Services\Nomina\ReciboNominaService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lotes de recibos de nómina: PREPARAR → REVISAR → EMITIR. Toda la lógica
 * vive en LoteNominaService (la misma que usa Api\V1\Rh\LoteNominaController).
 */
class LoteNominaController extends Controller
{
    public function __construct(
        private readonly LoteNominaService $lotes,
        private readonly ReciboNominaService $recibos,
    ) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();
        abort_unless($usuario->can('nomina.recibos.ver'), 403);

        $hoy = now('America/Mexico_City');

        return Inertia::render('Rh/Nomina/Lotes', [
            'lotes' => $this->lotes->listar($usuario)->through(fn (NominaLote $l) => $this->lotes->resumen($l)),
            'periodos' => [
                'semanal' => $this->periodoArray($this->lotes->periodo(PeriodicidadNomina::Semanal, $hoy)),
                'quincenal' => $this->periodoArray($this->lotes->periodo(PeriodicidadNomina::Quincenal, $hoy)),
            ],
            'puedeCrear' => $usuario->can('nomina.recibos.crear'),
            'puedeImportar' => $usuario->can('nomina.recibos.importar'),
        ]);
    }

    /**
     * Crea el lote: desde el sueldo de cada colaborador o importando el
     * archivo de RH. Solo borradores; nada se publica.
     */
    public function store(Request $request): RedirectResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('nomina.recibos.crear'), 403);

        $datos = $request->validate([
            'periodicidad' => ['required', Rule::enum(PeriodicidadNomina::class)],
            'fecha' => ['required', 'date'],
            'origen' => ['required', Rule::in([NominaLote::ORIGEN_SUELDOS, NominaLote::ORIGEN_IMPORTACION])],
            'archivo' => ['required_if:origen,'.NominaLote::ORIGEN_IMPORTACION, 'nullable', 'file', 'mimes:csv,txt,xlsx,xls', 'max:5120'],
        ], [
            'archivo.required_if' => 'Elige el archivo CSV o Excel del lote.',
            'archivo.mimes' => 'El archivo debe ser CSV o Excel (.xlsx).',
        ]);

        $periodicidad = PeriodicidadNomina::from($datos['periodicidad']);
        $fecha = Carbon::parse($datos['fecha']);

        if ($datos['origen'] === NominaLote::ORIGEN_IMPORTACION) {
            abort_unless($usuario->can('nomina.recibos.importar'), 403);
            $lote = $this->lotes->importar($request->file('archivo'), $periodicidad, $fecha, $usuario)['lote'];
        } else {
            $lote = $this->lotes->preparar($periodicidad, $fecha, $usuario);
        }

        abort_if($lote === null, 422);

        return redirect()->route('rh.nomina.lotes.show', $lote)->with('toast', [
            'type' => ($lote->errores ?? []) === [] ? 'success' : 'warning',
            'message' => sprintf('Lote preparado: %d de %d recibos listos para revisar. Todavía NO se publican ni se avisa a nadie.', $lote->preparados, $lote->esperados),
        ]);
    }

    public function show(Request $request, NominaLote $lote): Response
    {
        $usuario = $request->user();
        abort_unless($usuario->can('nomina.recibos.ver'), 403);

        $filtro = in_array($request->string('filtro')->toString(), ['todos', 'correctos', 'advertencias', 'errores'], true)
            ? $request->string('filtro')->toString()
            : 'todos';

        $resumen = $this->lotes->resumen($lote);
        // Emitido con recibos en borrador = una emisión que se cortó: se puede reintentar.
        $emitible = $lote->estado === EstadoLoteNomina::Preparado
            || ($lote->estado === EstadoLoteNomina::Emitido && $resumen['por_emitir'] > 0);

        return Inertia::render('Rh/Nomina/Lote', [
            'lote' => $resumen,
            'recibos' => $this->lotes->recibos($lote, $usuario, $filtro),
            'conteos' => [
                'todos' => count($this->lotes->recibos($lote, $usuario)),
                'correctos' => count($this->lotes->recibos($lote, $usuario, 'correctos')),
                'advertencias' => count($this->lotes->recibos($lote, $usuario, 'advertencias')),
                'errores' => count($lote->errores ?? []),
            ],
            'filtro' => $filtro,
            'puedeEmitir' => $usuario->can('nomina.recibos.crear') && $emitible,
            'puedeCancelar' => $usuario->can('nomina.recibos.crear') && $lote->estado !== EstadoLoteNomina::Cancelado,
            'puedeEditar' => $usuario->can('nomina.recibos.crear') && $lote->estado === EstadoLoteNomina::Preparado,
        ]);
    }

    /**
     * PDF REAL del recibo con el formato oficial (borrador: no se guarda, no
     * se emite, no se avisa). Emitido: el PDF congelado del expediente.
     */
    public function pdf(Request $request, NominaLote $lote, ReciboNomina $recibo): HttpResponse|StreamedResponse
    {
        abort_unless($recibo->nomina_lote_id === $lote->id, 404);
        $this->authorize('ver', $recibo);

        if ($recibo->pdf_path !== null) {
            return $this->recibos->respuestaPdf($recibo);
        }

        return response($this->lotes->vistaPreviaPdf($recibo), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s-vista-previa.pdf"', $recibo->folio ?? 'recibo'),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function conceptos(Request $request, NominaLote $lote, ReciboNomina $recibo): JsonResponse
    {
        abort_unless($recibo->nomina_lote_id === $lote->id, 404);
        $this->authorize('ver', $recibo);

        return response()->json(['conceptos' => $this->recibos->conceptosEditables($recibo), 'observaciones' => $recibo->observaciones]);
    }

    public function emitir(Request $request, NominaLote $lote): RedirectResponse
    {
        abort_unless($request->user()->can('nomina.recibos.crear'), 403);

        $resultado = $this->lotes->emitir($lote, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('Se publicaron %d recibos y se avisó a cada colaborador.', $resultado['emitidos'])]);
    }

    public function cancelar(Request $request, NominaLote $lote): RedirectResponse
    {
        abort_unless($request->user()->can('nomina.recibos.crear'), 403);
        $datos = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']], ['motivo.required' => 'Indica el motivo de la cancelación.']);

        $this->lotes->cancelar($lote, $datos['motivo'], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Lote cancelado. El periodo se puede volver a preparar.']);
    }

    /**
     * @param  array{inicio: CarbonImmutable, fin: CarbonImmutable, pago: CarbonImmutable, numero: int, etiqueta: string}  $periodo
     * @return array{inicio: string, fin: string, pago: string, numero: int, etiqueta: string}
     */
    private function periodoArray(array $periodo): array
    {
        return [
            'inicio' => $periodo['inicio']->toDateString(),
            'fin' => $periodo['fin']->toDateString(),
            'pago' => $periodo['pago']->toDateString(),
            'numero' => $periodo['numero'],
            'etiqueta' => $periodo['etiqueta'],
        ];
    }
}
