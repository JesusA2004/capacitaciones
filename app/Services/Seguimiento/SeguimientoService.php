<?php

namespace App\Services\Seguimiento;

use App\Models\Colaborador;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

/**
 * Seguimiento (Fase 2): datos duros de Reportes por SUCURSAL y por GESTOR,
 * por semana y por mes, acotados al alcance del usuario.
 *
 * PEOPLE NO calcula ningún KPI: suma/agrupa lo que Reportes ya calculó.
 *
 *   - Mes: KPIs OFICIALES de la radiografía (/periods/{mes}/branches y
 *     /periods/{mes}/employees): colocación, recuperación, cartera, vencida, mora.
 *   - Semana: /periods/{mes}/weekly-activity — colocación (fecha de operación)
 *     y recuperación (fecha de pago, mismas exclusiones del reporte) de cada
 *     semana componente del mes. Cartera/mora son un corte de MES: no
 *     existen por semana y nunca se inventan.
 *
 * Una sucursal de PEOPLE suma los branches de Reportes vinculados a ella
 * (VinculoReportesService). Un gestor se ubica por su vínculo con un
 * colaborador de PEOPLE (su sucursal principal) o, si aún no está
 * vinculado, por el branch que Reportes le asignó en ese mes.
 */
class SeguimientoService
{
    public function __construct(
        private readonly ReportesCatalogoService $catalogo,
        private readonly VinculoReportesService $vinculos,
        private readonly AlcanceSeguimientoService $alcance,
    ) {}

    /**
     * Ejecuta una lectura de Reportes y, si Reportes no está disponible,
     * devuelve el motivo en español en vez de tronar la pantalla.
     *
     * @template T
     *
     * @param  callable(): T  $lectura
     * @return array{datos: T|null, error: string|null}
     */
    public function intentar(callable $lectura): array
    {
        try {
            return ['datos' => $lectura(), 'error' => null];
        } catch (ReportesNoDisponibleException $e) {
            return ['datos' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Meses para el selector y el mes/semana elegidos (validados contra Reportes).
     *
     * @return array{meses: list<array{id: int, codigo: string, etiqueta: string, inicio: string|null, fin: string|null, radiografia: bool, semanas_ids: list<int>}>, mes: array{id: int, codigo: string, etiqueta: string, inicio: string|null, fin: string|null, radiografia: bool, semanas_ids: list<int>}|null}
     *
     * @throws ReportesNoDisponibleException
     */
    public function periodo(?int $mesId): array
    {
        $meses = array_slice($this->catalogo->meses(), 0, max(1, (int) config('seguimiento.meses_tendencia', 6)) * 2);
        $mes = null;

        foreach ($meses as $candidato) {
            if ($candidato['id'] === $mesId) {
                $mes = $candidato;
            }
        }

        if ($mes === null) {
            foreach ($meses as $candidato) {
                if ($candidato['radiografia']) {
                    $mes = $candidato;

                    break;
                }
            }
        }

        return ['meses' => $meses, 'mes' => $mes ?? ($meses[0] ?? null)];
    }

    /**
     * Tablero por sucursal de un mes (o de una de sus semanas).
     *
     * @return array<string, mixed>
     *
     * @throws ReportesNoDisponibleException
     */
    public function tableroSucursales(User $usuario, int $mesId, ?int $semanaId): array
    {
        $visibles = $this->alcance->sucursalesVisibles($usuario);
        $actividad = $this->catalogo->actividadSemanal($mesId);
        $oficial = $this->catalogo->kpisSucursales($mesId);
        $semanas = $this->semanas($actividad);
        $semanaId = $this->semanaValida($semanas, $semanaId);
        $sucursalPorBranch = $this->vinculos->sucursalPorBranch();

        $nombres = Sucursal::query()->pluck('nombre', 'id');
        /** @var array<int, array<string, mixed>> $porSucursal */
        $porSucursal = [];
        $sinVincular = ['branches' => [], 'colocacion' => 0.0, 'recuperacion' => 0.0];

        $nueva = fn (int $id): array => [
            'id' => $id,
            'nombre' => (string) ($nombres[$id] ?? sprintf('Sucursal %d', $id)),
            'branches' => [],
            'semanas' => array_map(fn (array $s): array => ['semana_id' => $s['id'], 'colocacion' => 0.0, 'operaciones' => 0, 'recuperacion' => 0.0], $semanas),
            'fuera_semanas' => ['colocacion' => 0.0, 'recuperacion' => 0.0],
            'mes' => ['colocacion' => null, 'creditos' => null, 'recuperacion' => null, 'cartera' => null, 'vencida' => null],
            'gestores' => 0,
        ];

        // Semanas: actividad por branch (atribución por branch de la fila de Reportes).
        foreach (DatoReportes::lista(DatoReportes::ruta($actividad, 'branches.data')) as $fila) {
            $branchId = DatoReportes::entero($fila['branch_id'] ?? null);
            $sucursalId = $branchId === null ? null : ($sucursalPorBranch[$branchId] ?? null);

            if ($sucursalId === null) {
                $sinVincular['branches'][] = DatoReportes::texto(DatoReportes::ruta($fila, 'branch.name')) ?: sprintf('Branch %s', $branchId ?? '—');
                $sinVincular['colocacion'] += DatoReportes::decimal(DatoReportes::ruta($fila, 'month_total.placement.amount'));
                $sinVincular['recuperacion'] += DatoReportes::decimal(DatoReportes::ruta($fila, 'month_total.recovery.total'));

                continue;
            }

            if (! $this->alcance->alcanzaSucursal($visibles, $sucursalId)) {
                continue;
            }

            $porSucursal[$sucursalId] ??= $nueva($sucursalId);
            $porSucursal[$sucursalId]['branches'][] = DatoReportes::texto(DatoReportes::ruta($fila, 'branch.name'));
            $porSucursal[$sucursalId]['semanas'] = $this->sumarSemanas($porSucursal[$sucursalId]['semanas'], DatoReportes::lista($fila['weeks'] ?? null));
            $porSucursal[$sucursalId]['fuera_semanas']['colocacion'] += DatoReportes::decimal(DatoReportes::ruta($fila, 'outside_weeks.placement.amount'));
            $porSucursal[$sucursalId]['fuera_semanas']['recuperacion'] += DatoReportes::decimal(DatoReportes::ruta($fila, 'outside_weeks.recovery.total'));
        }

        // Mes: KPIs oficiales de la radiografía por branch vinculado.
        foreach (DatoReportes::lista($oficial['data'] ?? null) as $fila) {
            $branchId = DatoReportes::entero($fila['branch_id'] ?? null);
            $sucursalId = $branchId === null ? null : ($sucursalPorBranch[$branchId] ?? null);

            if ($sucursalId === null || ! $this->alcance->alcanzaSucursal($visibles, $sucursalId)) {
                continue;
            }

            $porSucursal[$sucursalId] ??= $nueva($sucursalId);
            $mes = $porSucursal[$sucursalId]['mes'];
            $porSucursal[$sucursalId]['mes'] = [
                'colocacion' => $this->sumar($mes['colocacion'], DatoReportes::decimalONulo(DatoReportes::ruta($fila, 'kpis.placement.amount'))),
                'creditos' => $this->sumar($mes['creditos'], DatoReportes::decimalONulo(DatoReportes::ruta($fila, 'kpis.placement.credits'))),
                'recuperacion' => $this->sumar($mes['recuperacion'], DatoReportes::decimalONulo(DatoReportes::ruta($fila, 'kpis.recovery.total'))),
                'cartera' => $this->sumar($mes['cartera'], DatoReportes::decimalONulo(DatoReportes::ruta($fila, 'kpis.portfolio.total'))),
                'vencida' => $this->sumar($mes['vencida'], DatoReportes::decimalONulo(DatoReportes::ruta($fila, 'kpis.portfolio.overdue'))),
            ];
        }

        // Gestores con actividad en el mes, por sucursal.
        foreach ($this->gestoresUbicados($actividad) as $gestor) {
            if ($gestor['sucursal_id'] !== null && isset($porSucursal[$gestor['sucursal_id']])) {
                $porSucursal[$gestor['sucursal_id']]['gestores']++;
            }
        }

        $filas = [];

        foreach ($porSucursal as $sucursal) {
            $filas[] = $this->filaSucursal($sucursal, $semanaId);
        }

        usort($filas, fn (array $a, array $b): int => ($b['colocacion'] ?? 0) <=> ($a['colocacion'] ?? 0));

        return [
            'estado' => DatoReportes::texto($actividad['status'] ?? null) === 'ok' ? 'ok' : 'sin_datos',
            'mensaje' => DatoReportes::textoONulo($actividad['message'] ?? null),
            'calculado_en' => DatoReportes::textoONulo($actividad['calculated_at'] ?? null),
            'semanas' => $semanas,
            'semana_id' => $semanaId,
            'totales' => $this->totales($filas),
            'serie_semanal' => $this->serieSemanal($semanas, $filas),
            'sucursales' => $filas,
            'sin_vincular' => $this->alcance->veTodo($usuario) && $sinVincular['branches'] !== [] ? [
                'branches' => count($sinVincular['branches']),
                'nombres' => array_slice($sinVincular['branches'], 0, 12),
                'colocacion' => round($sinVincular['colocacion'], 2),
                'recuperacion' => round($sinVincular['recuperacion'], 2),
            ] : null,
        ];
    }

    /**
     * Gestores del mes (o de una semana), con su tendencia semanal.
     *
     * @return array<string, mixed>
     *
     * @throws ReportesNoDisponibleException
     */
    public function gestores(User $usuario, int $mesId, ?int $semanaId, ?int $sucursalId): array
    {
        $visibles = $this->alcance->sucursalesVisibles($usuario);
        $actividad = $this->catalogo->actividadSemanal($mesId);
        $semanas = $this->semanas($actividad);
        $semanaId = $this->semanaValida($semanas, $semanaId);
        $oficialPorEmpleado = $this->kpisOficialesPorEmpleado($mesId);
        $nombres = Sucursal::query()->pluck('nombre', 'id');

        $filas = [];
        // Opciones del filtro: TODAS las sucursales de su alcance con
        // gestores (antes salían de las filas ya filtradas y, al elegir una
        // sucursal, el filtro solo ofrecía esa).
        $sucursalesConGestores = [];

        foreach ($this->gestoresUbicados($actividad) as $gestor) {
            if (! $this->alcance->alcanzaSucursal($visibles, $gestor['sucursal_id'])) {
                continue;
            }

            if ($gestor['sucursal_id'] !== null) {
                $sucursalesConGestores[$gestor['sucursal_id']] = (string) ($nombres[$gestor['sucursal_id']] ?? '');
            }

            if ($sucursalId !== null && $gestor['sucursal_id'] !== $sucursalId) {
                continue;
            }

            $oficial = $oficialPorEmpleado[$gestor['reportes_id']] ?? null;
            $semanasGestor = $this->sumarSemanas(
                array_map(fn (array $s): array => ['semana_id' => $s['id'], 'colocacion' => 0.0, 'operaciones' => 0, 'recuperacion' => 0.0], $semanas),
                $gestor['semanas'],
            );
            $semana = $semanaId === null ? null : collect($semanasGestor)->firstWhere('semana_id', $semanaId);
            $cartera = $oficial === null ? null : DatoReportes::decimalONulo(DatoReportes::ruta($oficial, 'kpis.portfolio.total'));
            $vencida = $oficial === null ? null : DatoReportes::decimalONulo(DatoReportes::ruta($oficial, 'kpis.portfolio.overdue'));

            $filas[] = [
                'reportes_id' => $gestor['reportes_id'],
                'nombre' => $gestor['nombre'],
                'puesto' => $gestor['puesto'],
                'colaborador_id' => $gestor['colaborador_id'],
                'vinculado' => $gestor['colaborador_id'] !== null,
                'sucursal_id' => $gestor['sucursal_id'],
                'sucursal' => $gestor['sucursal_id'] === null ? null : (string) ($nombres[$gestor['sucursal_id']] ?? ''),
                'branch' => $gestor['branch'],
                'colocacion' => $semana !== null ? $semana['colocacion'] : $gestor['mes_colocacion'],
                'operaciones' => $semana !== null ? $semana['operaciones'] : $gestor['mes_operaciones'],
                'recuperacion' => $semana !== null ? $semana['recuperacion'] : $gestor['mes_recuperacion'],
                'cartera' => $cartera,
                'vencida' => $vencida,
                'mora' => $this->mora($cartera, $vencida),
                'semanas' => $semanasGestor,
            ];
        }

        usort($filas, fn (array $a, array $b): int => ($b['colocacion'] ?? 0) <=> ($a['colocacion'] ?? 0));

        $opcionesSucursal = collect($sucursalesConGestores)->filter()->sort()
            ->map(fn (string $nombre, int $id): array => ['value' => $id, 'label' => $nombre])->values()->all();

        return [
            'estado' => DatoReportes::texto($actividad['status'] ?? null) === 'ok' ? 'ok' : 'sin_datos',
            'mensaje' => DatoReportes::textoONulo($actividad['message'] ?? null),
            'calculado_en' => DatoReportes::textoONulo($actividad['calculated_at'] ?? null),
            'semanas' => $semanas,
            'semana_id' => $semanaId,
            'gestores' => $filas,
            'opciones_sucursal' => $opcionesSucursal,
            'totales' => [
                'gestores' => count($filas),
                'sin_vincular' => count(array_filter($filas, fn (array $f): bool => ! $f['vinculado'])),
                'colocacion' => round(array_sum(array_map(fn (array $f): float => (float) ($f['colocacion'] ?? 0), $filas)), 2),
                'recuperacion' => round(array_sum(array_map(fn (array $f): float => (float) ($f['recuperacion'] ?? 0), $filas)), 2),
                'cartera' => round(array_sum(array_map(fn (array $f): float => (float) ($f['cartera'] ?? 0), $filas)), 2),
                'vencida' => round(array_sum(array_map(fn (array $f): float => (float) ($f['vencida'] ?? 0), $filas)), 2),
            ],
        ];
    }

    /**
     * Detalle de UN gestor: KPIs del mes, tendencia semanal (12 semanas) y
     * mensual (6 meses). 403 si está fuera del alcance del usuario.
     *
     * @param  list<array{id: int, codigo: string, etiqueta: string, inicio: string|null, fin: string|null, radiografia: bool, semanas_ids: list<int>}>  $meses
     * @return array<string, mixed>
     *
     * @throws ReportesNoDisponibleException
     * @throws AuthorizationException
     */
    public function gestor(User $usuario, int $empleadoId, int $mesId, array $meses): array
    {
        $actividad = $this->catalogo->actividadSemanal($mesId);
        $gestor = collect($this->gestoresUbicados($actividad))
            ->first(fn (array $g): bool => $g['reportes_id'] === $empleadoId || in_array($empleadoId, $g['ids'], true));

        $colaboradorId = $gestor['colaborador_id'] ?? ($this->vinculos->colaboradorPorEmpleado()[$empleadoId] ?? null);
        $colaborador = $colaboradorId === null ? null : Colaborador::query()->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre'])->where('id', $colaboradorId)->first();

        $permitido = $colaborador !== null
            ? $this->alcance->alcanzaColaborador($usuario, $colaborador)
            : ($gestor !== null && $this->alcance->alcanzaSucursal($this->alcance->sucursalesVisibles($usuario), $gestor['sucursal_id']))
                || $this->alcance->veTodo($usuario);

        if (! $permitido) {
            throw new AuthorizationException('Este gestor está fuera de tu alcance en Seguimiento.');
        }

        // Tendencia mensual: hasta N meses (con radiografía) que terminan en el elegido.
        $limite = max(1, (int) config('seguimiento.meses_tendencia', 6));
        $mesesTendencia = array_values(array_filter($meses, fn (array $m): bool => $m['radiografia']));
        $posicion = array_search($mesId, array_column($mesesTendencia, 'id'), true);
        $mesesTendencia = array_slice($mesesTendencia, $posicion === false ? 0 : (int) $posicion, $limite);
        $mesesTendencia = array_reverse($mesesTendencia);

        $mensual = [];

        if ($mesesTendencia !== []) {
            $respuesta = $this->catalogo->colaboradorEnMeses($empleadoId, array_column($mesesTendencia, 'id'));

            foreach (DatoReportes::lista($respuesta['data'] ?? null) as $entrada) {
                $cartera = DatoReportes::decimalONulo(DatoReportes::ruta($entrada, 'kpis.portfolio.total'));
                $vencida = DatoReportes::decimalONulo(DatoReportes::ruta($entrada, 'kpis.portfolio.overdue'));
                $mensual[] = [
                    'mes_id' => DatoReportes::entero(DatoReportes::ruta($entrada, 'period.id')),
                    'etiqueta' => DatoReportes::texto(DatoReportes::ruta($entrada, 'period.label')),
                    'estado' => DatoReportes::texto($entrada['status'] ?? null),
                    'colocacion' => DatoReportes::decimalONulo(DatoReportes::ruta($entrada, 'kpis.placement.amount')),
                    'recuperacion' => DatoReportes::decimalONulo(DatoReportes::ruta($entrada, 'kpis.recovery.total')),
                    'cartera' => $cartera,
                    'vencida' => $vencida,
                    'mora' => $this->mora($cartera, $vencida),
                ];
            }
        }

        // Tendencia semanal: las semanas del mes elegido y de los anteriores.
        $semanal = [];
        $limiteSemanas = max(4, (int) config('seguimiento.semanas_tendencia', 12));
        $indice = array_search($mesId, array_column($meses, 'id'), true);

        foreach (array_slice($meses, $indice === false ? 0 : (int) $indice) as $mes) {
            if (count($semanal) >= $limiteSemanas) {
                break;
            }

            $actividadMes = $mes['id'] === $mesId ? $actividad : $this->catalogo->actividadSemanal($mes['id']);
            $semanasMes = $this->semanas($actividadMes);
            $delGestor = collect($this->gestoresUbicados($actividadMes))
                ->first(fn (array $g): bool => $g['reportes_id'] === $empleadoId || in_array($empleadoId, $g['ids'], true));
            $valores = $this->sumarSemanas(
                array_map(fn (array $s): array => ['semana_id' => $s['id'], 'colocacion' => 0.0, 'operaciones' => 0, 'recuperacion' => 0.0], $semanasMes),
                $delGestor['semanas'] ?? [],
            );

            $bloque = [];

            foreach ($semanasMes as $i => $semana) {
                $bloque[] = [
                    'semana_id' => $semana['id'],
                    'etiqueta' => $semana['etiqueta'],
                    'mes' => $mes['etiqueta'],
                    'sin_datos' => DatoReportes::texto($actividadMes['status'] ?? null) !== 'ok',
                    'colocacion' => $valores[$i]['colocacion'],
                    'operaciones' => $valores[$i]['operaciones'],
                    'recuperacion' => $valores[$i]['recuperacion'],
                ];
            }

            $semanal = [...$bloque, ...$semanal];
        }

        $semanal = array_slice($semanal, -$limiteSemanas);
        $mesActual = collect($mensual)->firstWhere('mes_id', $mesId);

        return [
            'gestor' => [
                'reportes_id' => $empleadoId,
                'nombre' => $colaborador?->nombreCompleto() ?? ($gestor['nombre'] ?? sprintf('Colaborador %d de Reportes', $empleadoId)),
                'nombre_reportes' => $gestor['nombre_reportes'] ?? null,
                'puesto' => $colaborador?->puesto->nombre ?? ($gestor['puesto'] ?? null),
                'sucursal' => $colaborador?->sucursalPrincipal?->nombre,
                'branch' => $gestor['branch'] ?? null,
                'colaborador_id' => $colaborador?->id,
                'vinculado' => $colaborador !== null,
            ],
            'mes' => [
                'colocacion' => $mesActual['colocacion'] ?? ($gestor['mes_colocacion'] ?? null),
                'operaciones' => $gestor['mes_operaciones'] ?? null,
                'recuperacion' => $mesActual['recuperacion'] ?? ($gestor['mes_recuperacion'] ?? null),
                'cartera' => $mesActual['cartera'] ?? null,
                'vencida' => $mesActual['vencida'] ?? null,
                'mora' => $mesActual['mora'] ?? null,
            ],
            'semanal' => $semanal,
            'mensual' => $mensual,
            'con_actividad' => $gestor !== null,
        ];
    }

    /**
     * Semanas del mes según Reportes, en orden.
     *
     * @param  array<string, mixed>  $actividad
     * @return list<array{id: int, indice: int, etiqueta: string, nombre: string, inicio: string|null, fin: string|null}>
     */
    public function semanas(array $actividad): array
    {
        $semanas = [];

        foreach (DatoReportes::lista($actividad['weeks'] ?? null) as $i => $fila) {
            $id = DatoReportes::entero($fila['id'] ?? null);

            if ($id === null) {
                continue;
            }

            $indice = DatoReportes::entero($fila['index'] ?? null) ?? ($i + 1);
            $semanas[] = [
                'id' => $id,
                'indice' => $indice,
                'etiqueta' => sprintf('Semana %d', $indice),
                'nombre' => DatoReportes::texto($fila['label'] ?? null),
                'inicio' => DatoReportes::textoONulo($fila['start_date'] ?? null),
                'fin' => DatoReportes::textoONulo($fila['end_date'] ?? null),
            ];
        }

        return $semanas;
    }

    /**
     * Actividad de los gestores de UN mes, sin acotar por alcance (la usa la
     * Nine Box, que calcula percentiles contra toda la empresa y acota
     * después): semanas del mes, cada gestor ubicado con sus semanas y los
     * KPIs oficiales de la radiografía por id de empleado de Reportes.
     *
     * @return array{estado: string, semanas: list<array{id: int, indice: int, etiqueta: string, nombre: string, inicio: string|null, fin: string|null}>, gestores: list<array{reportes_id: int, ids: list<int>, nombre: string, nombre_reportes: string, puesto: string|null, colaborador_id: int|null, sucursal_id: int|null, branch: string|null, semanas: list<array<string, mixed>>, mes_colocacion: float|null, mes_operaciones: int|null, mes_recuperacion: float|null}>, oficiales: array<int, array<string, mixed>>}
     *
     * @throws ReportesNoDisponibleException
     */
    public function actividadGestoresMes(int $mesId): array
    {
        $actividad = $this->catalogo->actividadSemanal($mesId);

        return [
            'estado' => DatoReportes::texto($actividad['status'] ?? null) === 'ok' ? 'ok' : 'sin_datos',
            'semanas' => $this->semanas($actividad),
            'gestores' => $this->gestoresUbicados($actividad),
            'oficiales' => $this->kpisOficialesPorEmpleado($mesId),
        ];
    }

    /**
     * Cada gestor de la actividad semanal con su colaborador de PEOPLE (si
     * está vinculado) y la sucursal de PEOPLE donde cae.
     *
     * @param  array<string, mixed>  $actividad
     * @return list<array{reportes_id: int, ids: list<int>, nombre: string, nombre_reportes: string, puesto: string|null, colaborador_id: int|null, sucursal_id: int|null, branch: string|null, semanas: list<array<string, mixed>>, mes_colocacion: float|null, mes_operaciones: int|null, mes_recuperacion: float|null}>
     */
    private function gestoresUbicados(array $actividad): array
    {
        $colaboradorPorEmpleado = $this->vinculos->colaboradorPorEmpleado();
        $sucursalPorBranch = $this->vinculos->sucursalPorBranch();
        $filas = DatoReportes::lista(DatoReportes::ruta($actividad, 'employees.data'));

        $colaboradoresIds = [];
        $preparados = [];

        foreach ($filas as $fila) {
            $id = DatoReportes::entero(DatoReportes::ruta($fila, 'employee.id'));

            if ($id === null) {
                continue;
            }

            $ids = array_values(array_unique([$id, ...DatoReportes::enteros(DatoReportes::ruta($fila, 'employee.employee_ids'))]));
            $colaboradorId = null;

            foreach ($ids as $candidato) {
                $colaboradorId ??= $colaboradorPorEmpleado[$candidato] ?? null;
            }

            if ($colaboradorId !== null) {
                $colaboradoresIds[] = $colaboradorId;
            }

            $preparados[] = [$id, $ids, $colaboradorId, $fila];
        }

        /** @var Collection<int, Colaborador> $colaboradores */
        $colaboradores = Colaborador::query()->with('puesto:id,nombre')
            ->whereIn('id', array_values(array_unique($colaboradoresIds)))
            ->get(['id', 'name', 'apellidos', 'puesto_id', 'sucursal_principal_id'])
            ->keyBy('id');

        $resultado = [];

        foreach ($preparados as [$id, $ids, $colaboradorId, $fila]) {
            $colaborador = $colaboradorId === null ? null : $colaboradores->get($colaboradorId);
            $branchId = DatoReportes::entero($fila['assigned_branch_id'] ?? null) ?? DatoReportes::entero(DatoReportes::ruta($fila, 'branch.id'));
            $nombreReportes = DatoReportes::texto(DatoReportes::ruta($fila, 'employee.full_name'));
            $operaciones = DatoReportes::entero(DatoReportes::ruta($fila, 'month_total.placement.operations'));

            $resultado[] = [
                'reportes_id' => $id,
                'ids' => $ids,
                'nombre' => $colaborador?->nombreCompleto() ?? $nombreReportes,
                'nombre_reportes' => $nombreReportes,
                'puesto' => $colaborador?->puesto->nombre ?? DatoReportes::textoONulo(DatoReportes::ruta($fila, 'employee.position')),
                'colaborador_id' => $colaborador?->id,
                'sucursal_id' => $colaborador !== null
                    ? ($colaborador->sucursal_principal_id === null ? null : (int) $colaborador->sucursal_principal_id)
                    : ($branchId === null ? null : ($sucursalPorBranch[$branchId] ?? null)),
                'branch' => DatoReportes::textoONulo(DatoReportes::ruta($fila, 'branch.name')),
                'semanas' => DatoReportes::lista($fila['weeks'] ?? null),
                'mes_colocacion' => DatoReportes::decimalONulo(DatoReportes::ruta($fila, 'month_total.placement.amount')),
                'mes_operaciones' => $operaciones,
                'mes_recuperacion' => DatoReportes::decimalONulo(DatoReportes::ruta($fila, 'month_total.recovery.total')),
            ];
        }

        return $resultado;
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws ReportesNoDisponibleException
     */
    private function kpisOficialesPorEmpleado(int $mesId): array
    {
        $porEmpleado = [];

        foreach ($this->catalogo->kpisColaboradores($mesId) as $fila) {
            foreach ([DatoReportes::entero(DatoReportes::ruta($fila, 'employee.id')), ...DatoReportes::enteros(DatoReportes::ruta($fila, 'employee.employee_ids'))] as $id) {
                if ($id !== null) {
                    $porEmpleado[$id] ??= $fila;
                }
            }
        }

        return $porEmpleado;
    }

    /**
     * Suma las semanas de una fila de Reportes sobre el acumulado (por semana_id).
     *
     * @param  list<array<string, mixed>>  $acumulado
     * @param  list<array<string, mixed>>  $semanasFila
     * @return list<array{semana_id: int, colocacion: float, operaciones: int, recuperacion: float}>
     */
    private function sumarSemanas(array $acumulado, array $semanasFila): array
    {
        $porId = [];

        foreach ($semanasFila as $semana) {
            $id = DatoReportes::entero($semana['period_id'] ?? null);

            if ($id !== null) {
                $porId[$id] = $semana;
            }
        }

        $resultado = [];

        foreach ($acumulado as $semana) {
            $id = (int) DatoReportes::entero($semana['semana_id'] ?? null);
            $fila = $porId[$id] ?? null;
            $resultado[] = [
                'semana_id' => $id,
                'colocacion' => round(DatoReportes::decimal($semana['colocacion'] ?? null) + DatoReportes::decimal(DatoReportes::ruta($fila, 'placement.amount')), 2),
                'operaciones' => (int) (DatoReportes::entero($semana['operaciones'] ?? null) ?? 0) + (int) (DatoReportes::entero(DatoReportes::ruta($fila, 'placement.operations')) ?? 0),
                'recuperacion' => round(DatoReportes::decimal($semana['recuperacion'] ?? null) + DatoReportes::decimal(DatoReportes::ruta($fila, 'recovery.total')), 2),
            ];
        }

        return $resultado;
    }

    /**
     * @param  list<array{id: int, indice: int, etiqueta: string, nombre: string, inicio: string|null, fin: string|null}>  $semanas
     */
    private function semanaValida(array $semanas, ?int $semanaId): ?int
    {
        return $semanaId !== null && in_array($semanaId, array_column($semanas, 'id'), true) ? $semanaId : null;
    }

    /**
     * Fila final de una sucursal: valores de la semana elegida (colocación y
     * recuperación) o los oficiales del mes; cartera/mora siempre del mes.
     *
     * @param  array<string, mixed>  $sucursal
     * @return array<string, mixed>
     */
    private function filaSucursal(array $sucursal, ?int $semanaId): array
    {
        /** @var list<array{semana_id: int, colocacion: float, operaciones: int, recuperacion: float}> $semanas */
        $semanas = $sucursal['semanas'];
        /** @var array{colocacion: float|null, creditos: float|null, recuperacion: float|null, cartera: float|null, vencida: float|null} $mes */
        $mes = $sucursal['mes'];
        $semana = $semanaId === null ? null : collect($semanas)->firstWhere('semana_id', $semanaId);

        return [
            'id' => $sucursal['id'],
            'nombre' => $sucursal['nombre'],
            'branches' => array_values(array_unique(array_filter((array) $sucursal['branches']))),
            'colocacion' => $semana !== null ? $semana['colocacion'] : $mes['colocacion'],
            'operaciones' => $semana !== null ? $semana['operaciones'] : ($mes['creditos'] === null ? null : (int) $mes['creditos']),
            'recuperacion' => $semana !== null ? $semana['recuperacion'] : $mes['recuperacion'],
            'cartera' => $mes['cartera'],
            'vencida' => $mes['vencida'],
            'mora' => $this->mora($mes['cartera'], $mes['vencida']),
            'gestores' => $sucursal['gestores'],
            'semanas' => $semanas,
            'mes_oficial' => $mes['colocacion'] !== null || $mes['cartera'] !== null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     * @return array<string, float|int|null>
     */
    private function totales(array $filas): array
    {
        $suma = fn (string $clave): ?float => collect($filas)->every(fn (array $f): bool => $f[$clave] === null)
            ? null
            : round((float) collect($filas)->sum(fn (array $f): float => (float) ($f[$clave] ?? 0)), 2);

        $cartera = $suma('cartera');
        $vencida = $suma('vencida');

        return [
            'sucursales' => count($filas),
            'colocacion' => $suma('colocacion'),
            'operaciones' => $suma('operaciones'),
            'recuperacion' => $suma('recuperacion'),
            'cartera' => $cartera,
            'vencida' => $vencida,
            'mora' => $this->mora($cartera, $vencida),
            'gestores' => (int) collect($filas)->sum('gestores'),
        ];
    }

    /**
     * @param  list<array{id: int, indice: int, etiqueta: string, nombre: string, inicio: string|null, fin: string|null}>  $semanas
     * @param  list<array<string, mixed>>  $filas
     * @return list<array{semana_id: int, etiqueta: string, colocacion: float, recuperacion: float}>
     */
    private function serieSemanal(array $semanas, array $filas): array
    {
        $serie = [];

        foreach ($semanas as $i => $semana) {
            $colocacion = 0.0;
            $recuperacion = 0.0;

            foreach ($filas as $fila) {
                $valores = DatoReportes::lista($fila['semanas'] ?? null)[$i] ?? [];
                $colocacion += DatoReportes::decimal($valores['colocacion'] ?? null);
                $recuperacion += DatoReportes::decimal($valores['recuperacion'] ?? null);
            }

            $serie[] = [
                'semana_id' => $semana['id'],
                'etiqueta' => $semana['etiqueta'],
                'colocacion' => round($colocacion, 2),
                'recuperacion' => round($recuperacion, 2),
            ];
        }

        return $serie;
    }

    private function sumar(?float $acumulado, ?float $valor): ?float
    {
        if ($valor === null) {
            return $acumulado;
        }

        return round(($acumulado ?? 0.0) + $valor, 2);
    }

    public function mora(?float $cartera, ?float $vencida): ?float
    {
        if ($cartera === null || $vencida === null || $cartera <= 0) {
            return null;
        }

        return round($vencida / $cartera * 100, 2);
    }
}
