<?php

namespace App\Http\Controllers\Seguimiento;

use App\Enums\CuadranteNineBox;
use App\Enums\NivelNineBox;
use App\Enums\TipoPeriodoNineBox;
use App\Exports\Sheets\ReporteDetalleSheet;
use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\NineBoxCalibracion;
use App\Models\User;
use App\Services\NineBox\CalibracionNineBoxService;
use App\Services\NineBox\ConfiguracionNineBoxService;
use App\Services\NineBox\NineBoxService;
use App\Services\Seguimiento\ReportesApiCliente;
use App\Services\Seguimiento\ReportesNoDisponibleException;
use App\Services\Seguimiento\SeguimientoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Nine Box de Seguimiento (docs/NINE_BOX.md). Delgado: el cálculo, el
 * alcance y las reglas viven en App\Services\NineBox\*. La matriz se manda
 * DIFERIDA (skeleton mientras Reportes contesta) y si Reportes cae la
 * pantalla muestra el motivo, nunca truena.
 */
class NineBoxController extends Controller
{
    public function __construct(
        private readonly NineBoxService $nineBox,
        private readonly CalibracionNineBoxService $calibraciones,
        private readonly ConfiguracionNineBoxService $configuracion,
        private readonly SeguimientoService $seguimiento,
        private readonly ReportesApiCliente $cliente,
    ) {}

    public function index(Request $request): Response
    {
        $usuario = $this->usuario($request);
        $this->authorize('ver', NineBoxCalibracion::class);
        [$tipo, $clave, $sucursalId] = $this->filtros($request);

        return Inertia::render('Seguimiento/NineBox', [
            'filtros' => ['tipo' => $tipo->value, 'periodo' => $clave, 'sucursal' => $sucursalId],
            'configurado' => $this->cliente->estaConfigurado(),
            'puedeConfigurar' => $usuario->can('configurar', NineBoxCalibracion::class),
            'puedeCalibrar' => $usuario->can('calibrar', NineBoxCalibracion::class),
            'niveles' => NivelNineBox::opciones(),
            'configuracion' => $usuario->can('configurar', NineBoxCalibracion::class) ? $this->configuracion->vigente() : null,
            'datos' => Inertia::defer(fn (): array => $this->seguimiento->intentar(
                fn (): array => $this->nineBox->matriz($usuario, $tipo, $clave, $sucursalId),
            )),
        ]);
    }

    /** Evaluaciones técnicas e historial de calibraciones de una persona (panel lateral). */
    public function persona(Request $request, Colaborador $colaborador): JsonResponse
    {
        $usuario = $this->usuario($request);
        $this->authorize('ver', NineBoxCalibracion::class);

        return response()->json($this->nineBox->detallePersona($usuario, $colaborador));
    }

    public function calibrar(Request $request): RedirectResponse
    {
        $usuario = $this->usuario($request);
        $this->authorize('calibrar', NineBoxCalibracion::class);
        $datos = $request->validate([
            'colaborador_id' => ['required', 'integer', Rule::exists('colaboradores', 'id')],
            'periodo_tipo' => ['required', Rule::enum(TipoPeriodoNineBox::class)],
            'periodo_clave' => ['required', 'string', 'max:30'],
            'nivel' => ['required', Rule::enum(NivelNineBox::class)],
            'comentario' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'comentario.min' => 'Explica en al menos 10 caracteres por qué eliges ese potencial.',
        ], [
            'colaborador_id' => 'colaborador', 'periodo_tipo' => 'tipo de periodo', 'periodo_clave' => 'periodo',
            'nivel' => 'potencial', 'comentario' => 'comentario',
        ]);

        $colaborador = Colaborador::query()->where('id', (int) $datos['colaborador_id'])->firstOrFail();

        try {
            $this->calibraciones->calibrar(
                $usuario,
                $colaborador,
                TipoPeriodoNineBox::from((string) $datos['periodo_tipo']),
                (string) $datos['periodo_clave'],
                NivelNineBox::from((string) $datos['nivel']),
                (string) $datos['comentario'],
            );
        } catch (ReportesNoDisponibleException $e) {
            throw ValidationException::withMessages(['periodo_clave' => $e->getMessage()]);
        }

        return back()->with('success', sprintf('Potencial de %s calibrado.', $colaborador->nombreCompleto()));
    }

    public function configurar(Request $request): RedirectResponse
    {
        $usuario = $this->usuario($request);
        $this->authorize('configurar', NineBoxCalibracion::class);
        $numero = ['required', 'numeric', 'min:0', 'max:100'];
        $datos = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:255'],
            'valores.desempeno.pesos.colocacion' => $numero,
            'valores.desempeno.pesos.recuperacion' => $numero,
            'valores.desempeno.pesos.mora' => $numero,
            'valores.desempeno.meta_colocacion_mensual' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'valores.desempeno.meta_recuperacion_mensual' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'valores.desempeno.mora_excelente' => $numero,
            'valores.desempeno.mora_critica' => $numero,
            'valores.potencial.pesos.evaluaciones' => $numero,
            'valores.potencial.pesos.calibracion' => $numero,
            'valores.potencial.ventana_evaluaciones_meses' => ['required', 'integer', 'min:1', 'max:24'],
            'valores.potencial.valores_calibracion.bajo' => $numero,
            'valores.potencial.valores_calibracion.medio' => $numero,
            'valores.potencial.valores_calibracion.alto' => $numero,
            'valores.cortes.desempeno.medio' => $numero,
            'valores.cortes.desempeno.alto' => $numero,
            'valores.cortes.potencial.medio' => $numero,
            'valores.cortes.potencial.alto' => $numero,
        ], [], ['motivo' => 'motivo del cambio']);

        $this->configuracion->guardar((array) $datos['valores'], (string) $datos['motivo'], $usuario);

        return back()->with('success', 'Configuración de la Nine Box guardada (versión nueva).');
    }

    public function exportar(Request $request): BinaryFileResponse|RedirectResponse
    {
        $usuario = $this->usuario($request);
        $this->authorize('ver', NineBoxCalibracion::class);
        [$tipo, $clave, $sucursalId] = $this->filtros($request);

        try {
            $matriz = $this->nineBox->matriz($usuario, $tipo, $clave, $sucursalId);
        } catch (ReportesNoDisponibleException $e) {
            return back()->with('error', $e->getMessage());
        }

        /** @var array{etiqueta: string}|null $periodo */
        $periodo = $matriz['periodo'];
        /** @var list<array<string, mixed>> $personas */
        $personas = $matriz['personas'];
        $estados = ['ubicado' => 'En la matriz', 'sin_vincular' => 'Sin vincular a PEOPLE', 'sin_potencial' => 'Por calibrar'];

        $filas = array_map(function (array $p) use ($estados): array {
            /** @var array{puntaje: float|null, nivel: string|null} $d */
            $d = $p['desempeno'];
            /** @var array{puntaje: float|null, nivel: string|null, evaluaciones: float|null, calibracion: array{etiqueta: string, comentario: string}|null} $pot */
            $pot = $p['potencial'];
            /** @var array{colocacion: float, recuperacion: float, mora: float|null} $k */
            $k = $p['kpis'];

            return [
                $p['nombre'], $p['puesto'], $p['sucursal'], $p['branch'],
                $estados[(string) $p['estado']] ?? $p['estado'],
                CuadranteNineBox::tryFrom((string) $p['cuadrante'])?->etiqueta(),
                $d['puntaje'], $d['nivel'] === null ? null : NivelNineBox::from($d['nivel'])->etiqueta(),
                $pot['puntaje'], $pot['nivel'] === null ? null : NivelNineBox::from($pot['nivel'])->etiqueta(),
                $pot['evaluaciones'], $pot['calibracion']['etiqueta'] ?? null, $pot['calibracion']['comentario'] ?? null,
                $k['colocacion'], $k['recuperacion'], $k['mora'],
            ];
        }, $personas);

        $hoja = new ReporteDetalleSheet([
            'Gestor', 'Puesto', 'Sucursal', 'Ruta / branch de Reportes', 'Estado', 'Cuadrante',
            'Desempeño (0-100)', 'Nivel de desempeño', 'Potencial (0-100)', 'Nivel de potencial',
            'Promedio de evaluaciones técnicas', 'Calibración', 'Comentario de la calibración',
            'Colocación', 'Recuperación', 'Mora %',
        ], $filas, 'Nine Box');

        return Excel::download($hoja, sprintf('nine-box-%s.xlsx', str((string) ($periodo['etiqueta'] ?? 'periodo'))->slug()));
    }

    /**
     * @return array{0: TipoPeriodoNineBox, 1: string|null, 2: int|null}
     */
    private function filtros(Request $request): array
    {
        $tipo = TipoPeriodoNineBox::tryFrom((string) $request->query('tipo', 'mes')) ?? TipoPeriodoNineBox::Mes;
        $clave = $request->query('periodo');

        return [$tipo, is_string($clave) && $clave !== '' ? mb_substr($clave, 0, 30) : null, $request->integer('sucursal') ?: null];
    }

    private function usuario(Request $request): User
    {
        $usuario = $request->user();
        abort_unless($usuario instanceof User, 403);

        return $usuario;
    }
}
