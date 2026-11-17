<?php

namespace App\Services\Seguimiento;

use Illuminate\Support\Carbon;

/**
 * Lecturas de la API v1 de Reportes que usa Seguimiento, ya normalizadas.
 * NO calcula KPIs: solo pide y ordena lo que Reportes ya calculó.
 *
 * Periodos (modelo de Reportes, respetado tal cual): la SEMANA es el
 * periodo base (type=weekly) y el MES (type=monthly) agrupa 4 semanas en
 * component_period_ids. Un mes sin semanas componentes (p. ej. periodos de
 * prueba) no se ofrece. La pertenencia de una semana a un mes sale SIEMPRE
 * de component_period_ids, nunca del campo `month` de la semana (la
 * «Semana 22 - Mayo» puede pertenecer a Junio).
 */
class ReportesCatalogoService
{
    /** Páginas máximas (de 100) al recorrer un listado paginado. */
    private const MAX_PAGINAS = 30;

    public function __construct(private readonly ReportesApiCliente $cliente) {}

    /**
     * Meses de Reportes, del más reciente al más antiguo.
     *
     * @return list<array{id: int, codigo: string, etiqueta: string, inicio: string|null, fin: string|null, radiografia: bool, semanas_ids: list<int>}>
     *
     * @throws ReportesNoDisponibleException
     */
    public function meses(): array
    {
        $meses = [];
        $hoy = Carbon::today()->toDateString();

        foreach ($this->todasLasPaginas('periods', ['type' => 'monthly']) as $fila) {
            $id = DatoReportes::entero($fila['id'] ?? null);
            $inicio = DatoReportes::textoONulo($fila['start_date'] ?? null);

            if ($id === null) {
                continue;
            }

            // Contrato aditivo: si Reportes ya manda component_period_ids, un
            // mes sin semanas no es operativo (periodos de prueba). Si no lo
            // manda (versión anterior), se descartan solo los meses futuros.
            $tieneComponentes = array_key_exists('component_period_ids', $fila);
            $semanas = DatoReportes::enteros($fila['component_period_ids'] ?? null);

            if (($tieneComponentes && $semanas === []) || ($inicio !== null && $inicio > $hoy)) {
                continue;
            }

            $meses[] = [
                'id' => $id,
                'codigo' => DatoReportes::texto($fila['code'] ?? null),
                'etiqueta' => DatoReportes::texto($fila['label'] ?? null) ?: sprintf('Periodo %d', $id),
                'inicio' => $inicio,
                'fin' => DatoReportes::textoONulo($fila['end_date'] ?? null),
                'radiografia' => DatoReportes::texto($fila['radiography_status'] ?? null) === 'generated',
                'semanas_ids' => $semanas,
            ];
        }

        usort($meses, fn (array $a, array $b): int => strcmp((string) $b['inicio'], (string) $a['inicio']));

        return $meses;
    }

    /**
     * Catálogo de branches (oficinas/rutas) activos de Reportes.
     *
     * @return list<array{id: int, codigo: string, nombre: string, sucursal_reportes: string|null, region: string|null}>
     *
     * @throws ReportesNoDisponibleException
     */
    public function branches(): array
    {
        $branches = [];

        foreach ($this->todasLasPaginas('branches', []) as $fila) {
            $id = DatoReportes::entero($fila['id'] ?? null);

            if ($id === null) {
                continue;
            }

            $branches[] = [
                'id' => $id,
                'codigo' => DatoReportes::texto($fila['code'] ?? null),
                'nombre' => DatoReportes::texto($fila['name'] ?? null),
                'sucursal_reportes' => DatoReportes::textoONulo(DatoReportes::ruta($fila, 'sucursal.nombre')),
                'region' => DatoReportes::textoONulo($fila['region'] ?? null),
            ];
        }

        return $branches;
    }

    /**
     * Catálogo de colaboradores activos de Reportes.
     *
     * @return list<array{id: int, clave: string|null, nombre: string, puesto: string|null, branch_id: int|null, branch: string|null}>
     *
     * @throws ReportesNoDisponibleException
     */
    public function empleados(): array
    {
        $empleados = [];

        foreach ($this->todasLasPaginas('employees', []) as $fila) {
            $id = DatoReportes::entero($fila['id'] ?? null);

            if ($id === null) {
                continue;
            }

            $empleados[] = [
                'id' => $id,
                'clave' => DatoReportes::textoONulo($fila['employee_code'] ?? null),
                'nombre' => DatoReportes::texto($fila['full_name'] ?? null),
                'puesto' => DatoReportes::textoONulo($fila['position'] ?? null),
                'branch_id' => DatoReportes::entero($fila['branch_id'] ?? null),
                'branch' => DatoReportes::textoONulo(DatoReportes::ruta($fila, 'branch.name')),
            ];
        }

        return $empleados;
    }

    /**
     * Actividad semanal de un MES (GET /periods/{mes}/weekly-activity):
     * semanas del mes y, por branch y por gestor, colocación/recuperación
     * de cada semana. Respuesta cruda de Reportes (docs/SEGUIMIENTO.md).
     *
     * @return array<string, mixed>
     *
     * @throws ReportesNoDisponibleException
     */
    public function actividadSemanal(int $mesId): array
    {
        return $this->cliente->get(sprintf('periods/%d/weekly-activity', $mesId), ['include' => 'branches,employees']);
    }

    /**
     * KPIs OFICIALES del mes por sucursal de la radiografía (GET /periods/{mes}/branches).
     *
     * @return array<string, mixed>
     *
     * @throws ReportesNoDisponibleException
     */
    public function kpisSucursales(int $mesId): array
    {
        return $this->cliente->get(sprintf('periods/%d/branches', $mesId));
    }

    /**
     * KPIs OFICIALES del mes por colaborador (GET /periods/{mes}/employees), todas las páginas.
     *
     * @return list<array<string, mixed>>
     *
     * @throws ReportesNoDisponibleException
     */
    public function kpisColaboradores(int $mesId): array
    {
        return $this->todasLasPaginas(sprintf('periods/%d/employees', $mesId), []);
    }

    /**
     * Un colaborador de Reportes a lo largo de varios meses (GET /employees/{id}/periods).
     *
     * @param  list<int>  $mesesIds
     * @return array<string, mixed>
     *
     * @throws ReportesNoDisponibleException
     */
    public function colaboradorEnMeses(int $empleadoId, array $mesesIds): array
    {
        return $this->cliente->get(sprintf('employees/%d/periods', $empleadoId), [
            'period_ids' => implode(',', $mesesIds),
            'include_effectiveness' => '0',
        ]);
    }

    /**
     * @param  array<string, scalar|null>  $consulta
     * @return list<array<string, mixed>>
     *
     * @throws ReportesNoDisponibleException
     */
    private function todasLasPaginas(string $ruta, array $consulta): array
    {
        $filas = [];
        $pagina = 1;

        do {
            $respuesta = $this->cliente->get($ruta, [...$consulta, 'per_page' => 100, 'page' => $pagina]);
            array_push($filas, ...DatoReportes::lista($respuesta['data'] ?? null));
            $ultima = DatoReportes::entero(DatoReportes::ruta($respuesta, 'meta.last_page')) ?? 1;
            $pagina++;
        } while ($pagina <= $ultima && $pagina <= self::MAX_PAGINAS);

        return $filas;
    }
}
