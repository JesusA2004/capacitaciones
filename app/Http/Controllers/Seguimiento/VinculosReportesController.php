<?php

namespace App\Http\Controllers\Seguimiento;

use App\Enums\MetodoVinculoReportes;
use App\Enums\TipoVinculoReportes;
use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\VinculoReportes;
use App\Services\Seguimiento\ReportesApiCliente;
use App\Services\Seguimiento\ReportesNoDisponibleException;
use App\Services\Seguimiento\SeguimientoService;
use App\Services\Seguimiento\VinculoReportesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Vínculos con Reportes» (seguimiento.vincular): qué sucursal de PEOPLE
 * corresponde a cada sucursal/ruta de Reportes y qué colaborador de PEOPLE
 * es cada colaborador de Reportes. Reglas en VinculoReportesService.
 */
class VinculosReportesController extends Controller
{
    public function __construct(
        private readonly VinculoReportesService $vinculos,
        private readonly SeguimientoService $seguimiento,
        private readonly ReportesApiCliente $cliente,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('vincular', VinculoReportes::class);

        return Inertia::render('Seguimiento/Vinculos', [
            'configurado' => $this->cliente->estaConfigurado(),
            'datos' => Inertia::defer(fn (): array => $this->seguimiento->intentar(function (): array {
                $mes = $this->seguimiento->periodo(null)['mes'];

                return [
                    'mes' => $mes['etiqueta'] ?? null,
                    ...$this->vinculos->pantallaDesdeReportes($mes['id'] ?? null),
                ];
            })),
        ]);
    }

    public function vincularSucursal(Request $request): RedirectResponse
    {
        $this->authorize('vincular', VinculoReportes::class);
        $datos = $request->validate([
            'reportes_id' => ['required', 'integer', 'min:1'],
            'sucursal_id' => ['required', 'integer', Rule::exists('sucursales', 'id')->whereNull('deleted_at')],
            'sugerencia' => ['sometimes', 'boolean'],
        ], [], ['reportes_id' => 'sucursal de Reportes', 'sucursal_id' => 'sucursal de PEOPLE']);

        $sucursal = Sucursal::query()->where('id', (int) $datos['sucursal_id'])->firstOrFail();

        return $this->conReportes(fn () => $this->vinculos->vincularSucursalDesdeReportes(
            $this->usuario($request),
            $sucursal,
            (int) $datos['reportes_id'],
            ($datos['sugerencia'] ?? false) ? MetodoVinculoReportes::SugerenciaConfirmada : MetodoVinculoReportes::Manual,
        ), sprintf('Listo: la sucursal de Reportes quedó vinculada a %s.', $sucursal->nombre));
    }

    public function vincularColaborador(Request $request): RedirectResponse
    {
        $this->authorize('vincular', VinculoReportes::class);
        $datos = $request->validate([
            'reportes_id' => ['required', 'integer', 'min:1'],
            'colaborador_id' => ['required', 'integer', Rule::exists('colaboradores', 'id')->whereNull('deleted_at')],
            'sugerencia' => ['sometimes', 'boolean'],
        ], [], ['reportes_id' => 'colaborador de Reportes', 'colaborador_id' => 'colaborador de PEOPLE']);

        $colaborador = Colaborador::query()->where('id', (int) $datos['colaborador_id'])->firstOrFail();

        return $this->conReportes(fn () => $this->vinculos->vincularColaboradorDesdeReportes(
            $this->usuario($request),
            $colaborador,
            (int) $datos['reportes_id'],
            ($datos['sugerencia'] ?? false) ? MetodoVinculoReportes::SugerenciaConfirmada : MetodoVinculoReportes::Manual,
        ), sprintf('Listo: %s quedó vinculado con Reportes.', $colaborador->nombreCompleto()));
    }

    public function confirmarSugerencias(Request $request): RedirectResponse
    {
        $this->authorize('vincular', VinculoReportes::class);
        $datos = $request->validate([
            'tipo' => ['required', Rule::enum(TipoVinculoReportes::class)],
        ]);
        $tipo = TipoVinculoReportes::from((string) $datos['tipo']);

        try {
            $creados = $this->vinculos->confirmarSugerencias($this->usuario($request), $tipo);
        } catch (ReportesNoDisponibleException $e) {
            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => $creados === 0 ? 'No había sugerencias pendientes.' : sprintf('Listo: %d vínculo(s) confirmados.', $creados),
        ]);
    }

    public function desvincular(Request $request, VinculoReportes $vinculo): RedirectResponse
    {
        $this->authorize('desvincular', $vinculo);
        $datos = $request->validate(['motivo' => ['nullable', 'string', 'max:255']]);

        $this->vinculos->desvincular($this->usuario($request), $vinculo, $datos['motivo'] ?? null);

        return back()->with('toast', ['type' => 'success', 'message' => 'Vínculo desactivado. El historial se conserva.']);
    }

    /**
     * @param  callable(): mixed  $accion
     */
    private function conReportes(callable $accion, string $exito): RedirectResponse
    {
        try {
            $accion();
        } catch (ReportesNoDisponibleException $e) {
            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => $exito]);
    }

    private function usuario(Request $request): User
    {
        $usuario = $request->user();
        abort_unless($usuario instanceof User, 403);

        return $usuario;
    }
}
