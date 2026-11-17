<?php

namespace App\Http\Controllers\Seguimiento;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VinculoReportes;
use App\Services\Seguimiento\AlcanceSeguimientoService;
use App\Services\Seguimiento\ReportesApiCliente;
use App\Services\Seguimiento\ReportesNoDisponibleException;
use App\Services\Seguimiento\SeguimientoService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Seguimiento (Fase 2): tableros con datos duros de Reportes por sucursal
 * y por gestor. Delgado: reglas, alcance y lectura de Reportes viven en
 * App\Services\Seguimiento\* (docs/SEGUIMIENTO.md). Los datos se mandan
 * DIFERIDOS: la pantalla pinta skeletons mientras Reportes contesta.
 */
class SeguimientoController extends Controller
{
    public function __construct(
        private readonly SeguimientoService $seguimiento,
        private readonly AlcanceSeguimientoService $alcance,
        private readonly ReportesApiCliente $cliente,
    ) {}

    public function sucursales(Request $request): Response
    {
        $usuario = $this->usuario($request);
        $this->authorize('ver', VinculoReportes::class);
        [$mesId, $semanaId] = $this->filtrosPeriodo($request);

        return Inertia::render('Seguimiento/Sucursales', [
            ...$this->comunes($usuario, $mesId, $semanaId),
            'datos' => Inertia::defer(fn (): array => $this->seguimiento->intentar(function () use ($usuario, $mesId, $semanaId): array {
                $periodo = $this->seguimiento->periodo($mesId);

                return [
                    'periodo' => $periodo,
                    'tablero' => $periodo['mes'] === null ? null : $this->seguimiento->tableroSucursales($usuario, $periodo['mes']['id'], $semanaId),
                ];
            })),
        ]);
    }

    public function gestores(Request $request): Response
    {
        $usuario = $this->usuario($request);
        $this->authorize('ver', VinculoReportes::class);
        [$mesId, $semanaId] = $this->filtrosPeriodo($request);
        $sucursalId = $request->integer('sucursal') ?: null;

        return Inertia::render('Seguimiento/Gestores', [
            ...$this->comunes($usuario, $mesId, $semanaId),
            'sucursalId' => $sucursalId,
            'datos' => Inertia::defer(fn (): array => $this->seguimiento->intentar(function () use ($usuario, $mesId, $semanaId, $sucursalId): array {
                $periodo = $this->seguimiento->periodo($mesId);

                return [
                    'periodo' => $periodo,
                    'tablero' => $periodo['mes'] === null ? null : $this->seguimiento->gestores($usuario, $periodo['mes']['id'], $semanaId, $sucursalId),
                ];
            })),
        ]);
    }

    public function gestor(Request $request, int $empleado): Response
    {
        $usuario = $this->usuario($request);
        $this->authorize('ver', VinculoReportes::class);
        [$mesId] = $this->filtrosPeriodo($request);

        $datos = null;
        $error = null;

        try {
            $periodo = $this->seguimiento->periodo($mesId);
            $datos = [
                'periodo' => $periodo,
                // AuthorizationException (fuera de alcance) se propaga: 403.
                'detalle' => $periodo['mes'] === null ? null : $this->seguimiento->gestor($usuario, $empleado, $periodo['mes']['id'], $periodo['meses']),
            ];
        } catch (ReportesNoDisponibleException $e) {
            $error = $e->getMessage();
        }

        return Inertia::render('Seguimiento/Gestor', [
            ...$this->comunes($usuario, $mesId, null),
            'empleadoId' => $empleado,
            'datos' => ['datos' => $datos, 'error' => $error],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function comunes(User $usuario, ?int $mesId, ?int $semanaId): array
    {
        return [
            'filtros' => ['mes' => $mesId, 'semana' => $semanaId],
            'configurado' => $this->cliente->estaConfigurado(),
            'puedeVincular' => $usuario->can('vincular', VinculoReportes::class),
            'veTodo' => $this->alcance->veTodo($usuario),
        ];
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function filtrosPeriodo(Request $request): array
    {
        return [$request->integer('mes') ?: null, $request->integer('semana') ?: null];
    }

    private function usuario(Request $request): User
    {
        $usuario = $request->user();
        abort_unless($usuario instanceof User, 403);

        return $usuario;
    }
}
