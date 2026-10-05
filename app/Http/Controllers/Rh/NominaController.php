<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoReciboNomina;
use App\Http\Controllers\Controller;
use App\Http\Requests\Nomina\ActualizarReciboNominaRequest;
use App\Http\Requests\Nomina\CambioMasivoRecibosRequest;
use App\Models\ReciboNomina;
use App\Services\Nomina\NominaQuincenalService;
use App\Services\Nomina\ReciboNominaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Recibos de nómina quincenales (docs/NOMINA_QUINCENAL.md): centro de RH
 * para revisar la quincena, ajustar recibos uno por uno o en bloque,
 * emitirlos y descargarlos todos (ZIP o un PDF para imprimir). Más la
 * pantalla «Mis recibos» del colaborador. Toda la lógica vive en
 * NominaQuincenalService/ReciboNominaService (los mismos que usa la API).
 */
class NominaController extends Controller
{
    public function __construct(
        private readonly NominaQuincenalService $quincenas,
        private readonly ReciboNominaService $recibos,
    ) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();
        abort_unless($usuario->can('nomina.recibos.ver'), 403);

        $periodo = $request->filled('periodo')
            ? $this->quincenas->periodoPorClave($request->string('periodo')->toString())
            : $this->quincenas->periodo(now('America/Mexico_City'));

        $base = $this->quincenas->recibosDelPeriodo($periodo, $usuario);
        $resumen = (clone $base)->toBase()
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN estado = ? THEN 1 ELSE 0 END) as borradores, SUM(neto) as neto, SUM(CASE WHEN estado = ? AND pdf_path IS NULL THEN 1 ELSE 0 END) as sin_pdf', [EstadoReciboNomina::Borrador->value, EstadoReciboNomina::Emitido->value])
            ->first();

        $q = trim($request->string('q')->toString());
        $estado = $request->string('estado')->toString();

        $pagina = (clone $base)
            ->with(['colaborador' => fn ($c) => $c->select('id', 'name', 'apellidos', 'numero_empleado', 'sucursal_principal_id', 'puesto_id'), 'colaborador.sucursalPrincipal:id,nombre', 'colaborador.puesto:id,nombre'])
            ->when(in_array($estado, ['borrador', 'emitido'], true), fn ($query) => $query->where('estado', $estado))
            ->when($q !== '', fn ($query) => $query->whereHas('colaborador', fn ($c) => $c->where(fn ($w) => $w
                ->where('name', 'like', "%{$q}%")
                ->orWhere('apellidos', 'like', "%{$q}%")
                ->orWhere('numero_empleado', 'like', "%{$q}%"))))
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (ReciboNomina $r) => [
                ...$this->recibos->aArray($r, true),
                'colaborador' => [
                    'id' => $r->colaborador->id,
                    'nombre' => $r->colaborador->nombreCompleto(),
                    'numero_empleado' => $r->colaborador->numero_empleado,
                    'sucursal' => $r->colaborador->sucursalPrincipal?->nombre,
                    'puesto' => $r->colaborador->puesto?->nombre,
                ],
                'pdf_url' => $r->pdf_path !== null ? route('rh.nomina.recibos.pdf', $r) : null,
            ]);

        return Inertia::render('Rh/Nomina/Index', [
            'periodo' => [
                'clave' => $periodo['clave'],
                'etiqueta' => $periodo['etiqueta'],
                'inicio' => $periodo['inicio']->toDateString(),
                'fin' => $periodo['fin']->toDateString(),
                'pago' => $periodo['pago']->toDateString(),
            ],
            'periodos' => $this->quincenas->periodosParaSelector(),
            'resumen' => [
                'total' => (int) ($resumen->total ?? 0),
                'borradores' => (int) ($resumen->borradores ?? 0),
                'emitidos' => (int) ($resumen->total ?? 0) - (int) ($resumen->borradores ?? 0),
                'neto' => round((float) ($resumen->neto ?? 0), 2),
                'sin_pdf' => (int) ($resumen->sin_pdf ?? 0),
            ],
            'recibos' => $pagina,
            'filtros' => ['q' => $q, 'estado' => $estado],
            'config' => [
                'emision_automatica' => (bool) config('nomina.quincenal.emision_automatica', true),
                'dias_anticipacion' => (int) config('nomina.quincenal.dias_anticipacion', 3),
            ],
            'puedeEditar' => $usuario->can('nomina.recibos.crear'),
        ]);
    }

    public function preparar(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('nomina.recibos.crear'), 403);
        $periodo = $this->quincenas->periodoPorClave((string) $request->validate(['periodo' => ['required', 'string']])['periodo']);

        $resultado = $this->quincenas->preparar($periodo, $request->user());
        $mensaje = sprintf('%d recibo(s) preparados como borrador; %d ya existían.', $resultado['creados'], $resultado['existentes']);

        if ($resultado['omitidos'] !== []) {
            $mensaje .= sprintf(' %d sin recibo: %s', count($resultado['omitidos']), implode('; ', array_map(fn (array $o) => "{$o['nombre']} ({$o['motivo']})", array_slice($resultado['omitidos'], 0, 5))));
        }

        return back()->with('toast', ['type' => $resultado['omitidos'] === [] ? 'success' : 'warning', 'message' => $mensaje]);
    }

    public function emitir(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('nomina.recibos.crear'), 403);
        $datos = $request->validate(['periodo' => ['required', 'string'], 'ids' => ['nullable', 'array'], 'ids.*' => ['integer']]);
        $periodo = $this->quincenas->periodoPorClave($datos['periodo']);

        $emitidos = $this->quincenas->emitirPeriodo($periodo, $request->user(), isset($datos['ids']) ? array_map('intval', $datos['ids']) : null);

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('%d recibo(s) emitidos. Cada colaborador ya puede verlo en la app y en su portal.', $emitidos)]);
    }

    public function masivo(CambioMasivoRecibosRequest $request): RedirectResponse
    {
        abort_unless($request->user()->can('nomina.recibos.crear'), 403);
        $datos = $request->validated();
        $periodo = $this->quincenas->periodoPorClave($datos['periodo']);

        $modificados = $this->quincenas->aplicarMasivo($periodo, $request->user(), [
            'accion' => $datos['accion'],
            'tipo' => $datos['tipo'],
            'concepto' => $datos['concepto'],
            'importe' => $datos['importe'] ?? null,
        ], isset($datos['ids']) ? array_map('intval', $datos['ids']) : null);

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('Cambio aplicado a %d recibo(s).', $modificados)]);
    }

    public function actualizar(ActualizarReciboNominaRequest $request, ReciboNomina $recibo): RedirectResponse
    {
        $this->authorize('editar', $recibo);

        $this->recibos->actualizar($recibo, $request->validated(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => $recibo->estado === EstadoReciboNomina::Emitido
            ? 'Recibo actualizado; su PDF se volvió a generar.'
            : 'Recibo actualizado.']);
    }

    public function emitirUno(Request $request, ReciboNomina $recibo): RedirectResponse
    {
        $this->authorize('editar', $recibo);

        $this->recibos->emitir($recibo, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Recibo emitido.']);
    }

    public function pdf(ReciboNomina $recibo): StreamedResponse
    {
        $this->authorize('ver', $recibo);

        return $this->recibos->respuestaPdf($recibo);
    }

    /**
     * Centro de descarga: todos los recibos emitidos de la quincena (o los
     * seleccionados) en un ZIP (un PDF por persona, por sucursal) o en un
     * solo PDF para imprimir y recabar firmas de recibido.
     */
    public function descargar(Request $request): HttpResponse
    {
        abort_unless($request->user()->can('nomina.recibos.ver'), 403);
        $datos = $request->validate([
            'periodo' => ['required', 'string'],
            'formato' => ['nullable', 'in:zip,pdf'],
            'ids' => ['nullable', 'array'],
            'ids.*' => ['integer'],
        ]);
        $periodo = $this->quincenas->periodoPorClave($datos['periodo']);
        /** @var Collection<int, ReciboNomina> $recibos */
        $recibos = $this->quincenas->descargables($periodo, $request->user(), isset($datos['ids']) ? array_map('intval', $datos['ids']) : null);

        if ($recibos->isEmpty()) {
            return back()->with('toast', ['type' => 'warning', 'message' => 'No hay recibos emitidos para descargar en esta quincena.']);
        }

        $nombre = sprintf('Recibos de nomina - Quincena %s', $periodo['clave']);

        if (($datos['formato'] ?? 'zip') === 'pdf') {
            return response($this->quincenas->pdfCombinado($recibos), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => sprintf('attachment; filename="%s.pdf"', $nombre),
            ]);
        }

        return $this->enviarZip($this->quincenas->zip($recibos, $periodo['clave']), $nombre.'.zip');
    }

    /**
     * «Mis recibos» del colaborador (solo emitidos, ver ReciboNominaPolicy).
     */
    public function misRecibos(Request $request): Response
    {
        $colaborador = $request->user()->colaborador;
        abort_if($colaborador === null, 403);

        return Inertia::render('Portal/MisRecibos', [
            'recibos' => $this->recibos->delColaborador($colaborador, 24)
                ->through(fn (ReciboNomina $r) => [
                    ...$this->recibos->aArray($r, true),
                    'pdf_url' => $r->pdf_path !== null ? route('portal.recibos.pdf', $r) : null,
                ]),
        ]);
    }

    private function enviarZip(string $ruta, string $nombre): BinaryFileResponse
    {
        return response()->download($ruta, $nombre, ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }
}
