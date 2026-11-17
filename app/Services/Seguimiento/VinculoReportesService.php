<?php

namespace App\Services\Seguimiento;

use App\Enums\MetodoVinculoReportes;
use App\Enums\TipoVinculoReportes;
use App\Models\Colaborador;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\VinculoReportes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Correspondencia AUDITABLE PEOPLE ↔ Reportes (tabla vinculos_reportes).
 *
 *   - Sucursal de PEOPLE ↔ varios `branches` de Reportes (oficina y rutas);
 *     un branch pertenece a una sola sucursal a la vez.
 *   - Colaborador de PEOPLE ↔ un `employee` de Reportes (uno a uno).
 *
 * Nunca vincula solo: PEOPLE SUGIERE (nombre idéntico) y una persona con
 * seguimiento.vincular confirma. Cambiar un vínculo desactiva el anterior
 * (quién, cuándo, por qué) y crea uno nuevo: el historial no se borra.
 */
class VinculoReportesService
{
    public function __construct(private readonly ReportesCatalogoService $catalogo) {}

    /**
     * Vincula una sucursal de PEOPLE con un branch que EXISTA en Reportes
     * (el nombre y la clave se toman de Reportes, nunca del formulario).
     *
     * @throws ReportesNoDisponibleException
     */
    public function vincularSucursalDesdeReportes(User $usuario, Sucursal $sucursal, int $branchId, MetodoVinculoReportes $metodo): VinculoReportes
    {
        $branch = collect($this->catalogo->branches())->firstWhere('id', $branchId);

        if ($branch === null) {
            throw ValidationException::withMessages(['reportes_id' => 'Esa sucursal/ruta ya no existe en Reportes (o está inactiva).']);
        }

        return $this->vincularSucursal($usuario, $sucursal, $branchId, $branch['nombre'], $branch['codigo'] !== '' ? $branch['codigo'] : null, $metodo);
    }

    /**
     * Vincula un colaborador de PEOPLE con un colaborador que EXISTA en Reportes.
     *
     * @throws ReportesNoDisponibleException
     */
    public function vincularColaboradorDesdeReportes(User $usuario, Colaborador $colaborador, int $empleadoId, MetodoVinculoReportes $metodo): VinculoReportes
    {
        $empleado = collect($this->catalogo->empleados())->firstWhere('id', $empleadoId);

        if ($empleado === null) {
            throw ValidationException::withMessages(['reportes_id' => 'Ese colaborador ya no existe en Reportes (o está inactivo).']);
        }

        return $this->vincularColaborador($usuario, $colaborador, $empleadoId, $empleado['nombre'], $empleado['clave'], $metodo);
    }

    /**
     * Pantalla de vínculos con el catálogo vigente de Reportes; lo que tuvo
     * actividad en $mesId (el mes más reciente) se marca «con datos».
     *
     * @return array{branches: list<array<string, mixed>>, empleados: list<array<string, mixed>>, opciones_sucursales: list<array{value: int, label: string}>, opciones_colaboradores: list<array{value: int, label: string, descripcion: string|null}>, totales: array<string, int>}
     *
     * @throws ReportesNoDisponibleException
     */
    public function pantallaDesdeReportes(?int $mesId): array
    {
        $actividad = $mesId === null ? [] : $this->catalogo->actividadSemanal($mesId);
        $branchesConDatos = [];
        $empleadosConDatos = [];

        foreach (DatoReportes::lista(DatoReportes::ruta($actividad, 'branches.data')) as $fila) {
            $id = DatoReportes::entero($fila['branch_id'] ?? null);

            if ($id !== null) {
                $branchesConDatos[] = $id;
            }
        }

        foreach (DatoReportes::lista(DatoReportes::ruta($actividad, 'employees.data')) as $fila) {
            array_push($empleadosConDatos, ...DatoReportes::enteros(DatoReportes::ruta($fila, 'employee.employee_ids')));
        }

        return $this->pantalla($this->catalogo->branches(), $this->catalogo->empleados(), $branchesConDatos, $empleadosConDatos);
    }

    /**
     * Confirma, de una vez, TODAS las sugerencias vigentes de un tipo (las
     * recalcula aquí; nunca confía en lo que mande el navegador).
     *
     * @return int vínculos creados
     *
     * @throws ReportesNoDisponibleException
     */
    public function confirmarSugerencias(User $usuario, TipoVinculoReportes $tipo): int
    {
        $branches = $this->catalogo->branches();
        $empleados = $this->catalogo->empleados();
        $pantalla = $this->pantalla($branches, $empleados);
        $creados = 0;

        if ($tipo === TipoVinculoReportes::Sucursal) {
            foreach ($pantalla['branches'] as $fila) {
                $sucursalId = DatoReportes::entero(DatoReportes::ruta($fila, 'sugerencia.sucursal_id'));
                $sucursal = $sucursalId === null ? null : Sucursal::query()->where('id', $sucursalId)->first();
                $branch = collect($branches)->firstWhere('id', DatoReportes::entero($fila['reportes_id'] ?? null));

                if ($sucursal !== null && $branch !== null) {
                    $this->vincularSucursal($usuario, $sucursal, $branch['id'], $branch['nombre'], $branch['codigo'] !== '' ? $branch['codigo'] : null, MetodoVinculoReportes::SugerenciaConfirmada);
                    $creados++;
                }
            }

            return $creados;
        }

        foreach ($pantalla['empleados'] as $fila) {
            $colaboradorId = DatoReportes::entero(DatoReportes::ruta($fila, 'sugerencia.colaborador_id'));
            $colaborador = $colaboradorId === null ? null : Colaborador::query()->where('id', $colaboradorId)->first();
            $empleado = collect($empleados)->firstWhere('id', DatoReportes::entero($fila['reportes_id'] ?? null));

            if ($colaborador !== null && $empleado !== null) {
                $this->vincularColaborador($usuario, $colaborador, $empleado['id'], $empleado['nombre'], $empleado['clave'], MetodoVinculoReportes::SugerenciaConfirmada);
                $creados++;
            }
        }

        return $creados;
    }

    /**
     * branch de Reportes => sucursal de PEOPLE (vínculos activos).
     *
     * @return array<int, int>
     */
    public function sucursalPorBranch(): array
    {
        $mapa = [];

        VinculoReportes::query()->activos()->deTipo(TipoVinculoReportes::Sucursal)
            ->whereNotNull('sucursal_id')
            ->get(['reportes_id', 'sucursal_id'])
            ->each(function (VinculoReportes $v) use (&$mapa): void {
                $mapa[$v->reportes_id] = (int) $v->sucursal_id;
            });

        return $mapa;
    }

    /**
     * employee de Reportes => colaborador de PEOPLE (vínculos activos).
     *
     * @return array<int, int>
     */
    public function colaboradorPorEmpleado(): array
    {
        $mapa = [];

        VinculoReportes::query()->activos()->deTipo(TipoVinculoReportes::Colaborador)
            ->whereNotNull('colaborador_id')
            ->get(['reportes_id', 'colaborador_id'])
            ->each(function (VinculoReportes $v) use (&$mapa): void {
                $mapa[$v->reportes_id] = (int) $v->colaborador_id;
            });

        return $mapa;
    }

    public function empleadoDeColaborador(int $colaboradorId): ?int
    {
        $vinculo = VinculoReportes::query()->activos()->deTipo(TipoVinculoReportes::Colaborador)
            ->where('colaborador_id', $colaboradorId)
            ->first(['reportes_id']);

        return $vinculo?->reportes_id;
    }

    public function vincularSucursal(User $usuario, Sucursal $sucursal, int $branchId, string $nombre, ?string $clave, MetodoVinculoReportes $metodo): VinculoReportes
    {
        return DB::transaction(function () use ($usuario, $sucursal, $branchId, $nombre, $clave, $metodo): VinculoReportes {
            $actual = VinculoReportes::query()->activos()->deTipo(TipoVinculoReportes::Sucursal)
                ->where('reportes_id', $branchId)
                ->lockForUpdate()
                ->first();

            if ($actual !== null && $actual->sucursal_id === $sucursal->id) {
                return $actual;
            }

            if ($actual !== null) {
                $this->desactivar($actual, $usuario, sprintf('Reasignado a la sucursal %s.', $sucursal->nombre));
            }

            return VinculoReportes::query()->create([
                'tipo' => TipoVinculoReportes::Sucursal->value,
                'sucursal_id' => $sucursal->id,
                'reportes_id' => $branchId,
                'reportes_clave' => $clave,
                'reportes_nombre' => mb_substr($nombre !== '' ? $nombre : sprintf('Branch %d', $branchId), 0, 200),
                'metodo' => $metodo->value,
                'vinculado_por' => $usuario->id,
            ]);
        });
    }

    public function vincularColaborador(User $usuario, Colaborador $colaborador, int $empleadoId, string $nombre, ?string $clave, MetodoVinculoReportes $metodo): VinculoReportes
    {
        return DB::transaction(function () use ($usuario, $colaborador, $empleadoId, $nombre, $clave, $metodo): VinculoReportes {
            $delEmpleado = VinculoReportes::query()->activos()->deTipo(TipoVinculoReportes::Colaborador)
                ->where('reportes_id', $empleadoId)
                ->lockForUpdate()
                ->first();

            if ($delEmpleado !== null && $delEmpleado->colaborador_id === $colaborador->id) {
                return $delEmpleado;
            }

            if ($delEmpleado !== null) {
                $this->desactivar($delEmpleado, $usuario, sprintf('Reasignado a %s.', $colaborador->nombreCompleto()));
            }

            // Uno a uno: si la persona de PEOPLE ya estaba ligada a otro
            // colaborador de Reportes, ese vínculo se cierra.
            VinculoReportes::query()->activos()->deTipo(TipoVinculoReportes::Colaborador)
                ->where('colaborador_id', $colaborador->id)
                ->lockForUpdate()
                ->get()
                ->each(fn (VinculoReportes $v) => $this->desactivar($v, $usuario, sprintf('Se vinculó al colaborador %d de Reportes.', $empleadoId)));

            return VinculoReportes::query()->create([
                'tipo' => TipoVinculoReportes::Colaborador->value,
                'colaborador_id' => $colaborador->id,
                'reportes_id' => $empleadoId,
                'reportes_clave' => $clave,
                'reportes_nombre' => mb_substr($nombre !== '' ? $nombre : sprintf('Colaborador %d', $empleadoId), 0, 200),
                'metodo' => $metodo->value,
                'vinculado_por' => $usuario->id,
            ]);
        });
    }

    public function desvincular(User $usuario, VinculoReportes $vinculo, ?string $motivo): void
    {
        if (! $vinculo->activo) {
            throw ValidationException::withMessages(['vinculo' => 'Este vínculo ya estaba desactivado.']);
        }

        DB::transaction(fn () => $this->desactivar($vinculo, $usuario, $motivo !== null && trim($motivo) !== '' ? trim($motivo) : 'Desvinculado a mano.'));
    }

    /**
     * Datos de la pantalla «Vínculos con Reportes»: cada branch y cada
     * colaborador de Reportes con su vínculo actual y, si no tiene, una
     * sugerencia por nombre idéntico (que una persona debe confirmar).
     *
     * @param  list<array{id: int, codigo: string, nombre: string, sucursal_reportes: string|null, region: string|null}>  $branches
     * @param  list<array{id: int, clave: string|null, nombre: string, puesto: string|null, branch_id: int|null, branch: string|null}>  $empleados
     * @param  list<int>  $branchesConDatos  branches con actividad en el mes más reciente
     * @param  list<int>  $empleadosConDatos  colaboradores de Reportes con actividad en ese mes
     * @return array{branches: list<array<string, mixed>>, empleados: list<array<string, mixed>>, opciones_sucursales: list<array{value: int, label: string}>, opciones_colaboradores: list<array{value: int, label: string, descripcion: string|null}>, totales: array<string, int>}
     */
    public function pantalla(array $branches, array $empleados, array $branchesConDatos = [], array $empleadosConDatos = []): array
    {
        $branchesConDatos = array_flip($branchesConDatos);
        $empleadosConDatos = array_flip($empleadosConDatos);
        $sucursales = Sucursal::query()->orderBy('nombre')->get(['id', 'nombre']);
        $colaboradores = Colaborador::query()
            ->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre'])
            ->orderBy('name')
            ->get(['id', 'name', 'apellidos', 'puesto_id', 'sucursal_principal_id', 'estatus']);

        $vinculosSucursal = VinculoReportes::query()->activos()->deTipo(TipoVinculoReportes::Sucursal)
            ->with(['sucursal:id,nombre', 'vinculadoPor:id,name'])->get()->keyBy('reportes_id');
        $vinculosColaborador = VinculoReportes::query()->activos()->deTipo(TipoVinculoReportes::Colaborador)
            ->with(['colaborador:id,name,apellidos', 'vinculadoPor:id,name'])->get()->keyBy('reportes_id');

        $sucursalPorNombre = $this->indicePorNombre($sucursales->map(fn (Sucursal $s): array => ['id' => $s->id, 'nombre' => $s->nombre]));
        $colaboradorPorFirma = $this->indicePorFirma($colaboradores->map(fn (Colaborador $c): array => ['id' => $c->id, 'nombre' => $c->nombreCompleto()]));
        $colaboradoresYaVinculados = $vinculosColaborador->pluck('colaborador_id')->filter()->map(fn ($id): int => (int) $id)->flip();

        $filasBranches = [];

        foreach ($branches as $branch) {
            $vinculo = $vinculosSucursal->get($branch['id']);
            $sugerida = $vinculo === null ? $this->sugerirSucursal($branch, $sucursalPorNombre) : null;

            $filasBranches[] = [
                'reportes_id' => $branch['id'],
                'codigo' => $branch['codigo'],
                'nombre' => $branch['nombre'],
                'sucursal_reportes' => $branch['sucursal_reportes'],
                'region' => $branch['region'],
                'con_datos' => isset($branchesConDatos[$branch['id']]),
                'vinculo' => $vinculo === null ? null : [
                    'id' => $vinculo->id,
                    'sucursal_id' => $vinculo->sucursal_id,
                    'sucursal' => $vinculo->sucursal?->nombre,
                    'metodo' => $vinculo->metodo->etiqueta(),
                    'vinculado_por' => $vinculo->vinculadoPor?->name,
                    'vinculado_en' => $vinculo->created_at?->toIso8601String(),
                ],
                'sugerencia' => $sugerida === null ? null : [
                    'sucursal_id' => $sugerida,
                    'sucursal' => $sucursales->firstWhere('id', $sugerida)?->nombre,
                ],
            ];
        }

        $filasEmpleados = [];
        $firmasReportes = array_count_values(array_map(fn (array $e): string => DatoReportes::firmaNombre($e['nombre']), $empleados));

        foreach ($empleados as $empleado) {
            $vinculo = $vinculosColaborador->get($empleado['id']);
            $firma = DatoReportes::firmaNombre($empleado['nombre']);
            $candidatos = $vinculo === null ? ($colaboradorPorFirma[$firma] ?? []) : [];
            // Solo se sugiere una coincidencia ÚNICA y libre en AMBOS lados:
            // con homónimos (en PEOPLE o en Reportes) decide una persona.
            $sugerido = count($candidatos) === 1 && ($firmasReportes[$firma] ?? 0) === 1 && ! $colaboradoresYaVinculados->has($candidatos[0]) ? $candidatos[0] : null;

            $filasEmpleados[] = [
                'reportes_id' => $empleado['id'],
                'clave' => $empleado['clave'],
                'nombre' => $empleado['nombre'],
                'puesto' => $empleado['puesto'],
                'branch' => $empleado['branch'],
                'con_datos' => isset($empleadosConDatos[$empleado['id']]),
                'vinculo' => $vinculo === null ? null : [
                    'id' => $vinculo->id,
                    'colaborador_id' => $vinculo->colaborador_id,
                    'colaborador' => $vinculo->colaborador?->nombreCompleto(),
                    'metodo' => $vinculo->metodo->etiqueta(),
                    'vinculado_por' => $vinculo->vinculadoPor?->name,
                    'vinculado_en' => $vinculo->created_at?->toIso8601String(),
                ],
                'sugerencia' => $sugerido === null ? null : [
                    'colaborador_id' => $sugerido,
                    'colaborador' => $colaboradores->firstWhere('id', $sugerido)?->nombreCompleto(),
                ],
                'homonimos' => count($candidatos) > 1 || ($firmasReportes[$firma] ?? 0) > 1,
            ];
        }

        return [
            'branches' => $filasBranches,
            'empleados' => $filasEmpleados,
            'opciones_sucursales' => array_values($sucursales->map(fn (Sucursal $s): array => ['value' => $s->id, 'label' => $s->nombre])->all()),
            'opciones_colaboradores' => array_values($colaboradores->map(fn (Colaborador $c): array => [
                'value' => $c->id,
                'label' => $c->nombreCompleto(),
                'descripcion' => $this->descripcionColaborador($c),
            ])->all()),
            'totales' => [
                'branches' => count($filasBranches),
                'branches_vinculados' => count(array_filter($filasBranches, fn (array $f): bool => $f['vinculo'] !== null)),
                'empleados' => count($filasEmpleados),
                'empleados_vinculados' => count(array_filter($filasEmpleados, fn (array $f): bool => $f['vinculo'] !== null)),
                'sugerencias' => count(array_filter($filasBranches, fn (array $f): bool => $f['sugerencia'] !== null))
                    + count(array_filter($filasEmpleados, fn (array $f): bool => $f['sugerencia'] !== null)),
            ],
        ];
    }

    private function descripcionColaborador(Colaborador $colaborador): ?string
    {
        $partes = array_values(array_filter([$colaborador->puesto?->nombre, $colaborador->sucursalPrincipal?->nombre], fn (?string $p): bool => $p !== null && $p !== ''));

        return $partes === [] ? null : implode(' · ', $partes);
    }

    private function desactivar(VinculoReportes $vinculo, User $usuario, string $motivo): void
    {
        $vinculo->forceFill([
            'activo' => false,
            'desactivado_por' => $usuario->id,
            'desactivado_en' => now(),
            'motivo_desactivacion' => mb_substr($motivo, 0, 255),
        ])->save();
    }

    /**
     * Sugerencia de sucursal para un branch: el nombre de la sucursal
     * institucional de Reportes (si la trae) o el del branch, idéntico al
     * de una sucursal de PEOPLE, o que empiece con él («TULA CENTRO» → Tula).
     *
     * @param  array{id: int, codigo: string, nombre: string, sucursal_reportes: string|null, region: string|null}  $branch
     * @param  array<string, int>  $sucursalPorNombre
     */
    private function sugerirSucursal(array $branch, array $sucursalPorNombre): ?int
    {
        foreach ([$branch['sucursal_reportes'], $branch['nombre']] as $nombre) {
            if ($nombre === null || $nombre === '') {
                continue;
            }

            $comparable = DatoReportes::nombreComparable($nombre);

            if (isset($sucursalPorNombre[$comparable])) {
                return $sucursalPorNombre[$comparable];
            }
        }

        $comparable = DatoReportes::nombreComparable($branch['nombre']);
        $coincidencias = array_values(array_filter(
            $sucursalPorNombre,
            fn (string $nombreSucursal): bool => $nombreSucursal !== '' && str_starts_with($comparable, sprintf('%s ', $nombreSucursal)),
            ARRAY_FILTER_USE_KEY,
        ));

        return count($coincidencias) === 1 ? $coincidencias[0] : null;
    }

    /**
     * @param  Collection<int, array{id: int, nombre: string}>  $filas
     * @return array<string, int>
     */
    private function indicePorNombre(Collection $filas): array
    {
        $indice = [];

        foreach ($filas as $fila) {
            $indice[DatoReportes::nombreComparable($fila['nombre'])] = $fila['id'];
        }

        return $indice;
    }

    /**
     * @param  Collection<int, array{id: int, nombre: string}>  $filas
     * @return array<string, list<int>>
     */
    private function indicePorFirma(Collection $filas): array
    {
        $indice = [];

        foreach ($filas as $fila) {
            $indice[DatoReportes::firmaNombre($fila['nombre'])][] = $fila['id'];
        }

        return $indice;
    }
}
