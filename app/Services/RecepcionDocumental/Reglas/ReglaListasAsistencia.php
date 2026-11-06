<?php

namespace App\Services\RecepcionDocumental\Reglas;

use App\Enums\EstadoUsuario;
use App\Enums\ReglaRecepcionDocumental;
use App\Models\Colaborador;
use App\Models\Sucursal;
use App\Services\RecepcionDocumental\EsperadoRecepcion;
use App\Services\RecepcionDocumental\PeriodoRecepcion;
use App\Support\FechaMexico;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * LISTAS DE ASISTENCIA: un «Lote de lista de asistencias firmadas» por
 * SUCURSAL y por SEMANA (lunes a domingo, la misma semana que la nómina
 * semanal de LoteNominaService::periodo). La semana cae en el mes de su
 * domingo (día de pago), igual que los recibos se cuentan por fecha de
 * pago; solo se esperan semanas que ya empezaron (en hora de México).
 *
 * Cantidad = colaboradores ACTIVOS de esa sucursal en esa semana, con la
 * misma definición que la nómina (estatus EstadoUsuario::Activo, sucursal
 * principal) aplicada a la semana: ingresó a más tardar el domingo y, si
 * tiene baja, ésta fue a partir del lunes (quien se dio de baja a media
 * semana sí firmó esa lista). «En incorporación», suspendidos sin baja y
 * dados de baja antes de la semana no cuentan. Una fecha_baja anterior a la
 * fecha_ingreso es de un empleo previo (reingreso) y se ignora.
 *
 * Sin activos en la semana no hay lote. Mientras el lote está pendiente,
 * cada materialización actualiza su cantidad (altas y bajas de la semana).
 */
class ReglaListasAsistencia implements ReglaRecepcion
{
    public const PREFIJO = 'lista_asistencia.';

    public function regla(): ReglaRecepcionDocumental
    {
        return ReglaRecepcionDocumental::ListasAsistencia;
    }

    public function esperados(PeriodoRecepcion $periodo): array
    {
        $semanas = self::semanas($periodo);

        if ($semanas === []) {
            return [];
        }

        $primera = $semanas[0]['inicio']->toDateString();
        $ultima = $semanas[array_key_last($semanas)]['fin']->toDateString();
        $colaboradores = $this->candidatos($primera, $ultima)->get(['id', 'name', 'apellidos', 'numero_empleado', 'sucursal_principal_id', 'estatus', 'fecha_ingreso', 'fecha_baja']);

        if ($colaboradores->isEmpty()) {
            return [];
        }

        $sucursales = Sucursal::query()->whereIn('id', $colaboradores->pluck('sucursal_principal_id')->unique()->all())->get(['id', 'nombre'])->keyBy('id');
        $esperados = [];

        foreach ($semanas as ['inicio' => $inicio, 'fin' => $fin]) {
            $porSucursal = $colaboradores
                ->filter(fn (Colaborador $c): bool => self::activoEnSemana($c, $inicio->toDateString(), $fin->toDateString()))
                ->groupBy('sucursal_principal_id');

            foreach ($porSucursal as $sucursalId => $activos) {
                $sucursal = $sucursales->get((int) $sucursalId);

                if (! $sucursal instanceof Sucursal) {
                    continue;
                }

                $esperados[] = new EsperadoRecepcion(
                    $this->regla(),
                    self::PREFIJO.$inicio->toDateString(),
                    sprintf('Lote de lista de asistencias firmadas · semana del %s al %s', $inicio->format('d/m'), $fin->format('d/m/Y')),
                    $sucursal,
                    null,
                    null,
                    $sucursal->id,
                    null,
                    null,
                    null,
                    $sucursal->nombre,
                    $fin->toDateString(),
                    self::descripcion($inicio, $fin, $activos->count()),
                    $activos->count(),
                );
            }
        }

        usort($esperados, fn (EsperadoRecepcion $a, EsperadoRecepcion $b): int => [$a->sucursalNombre, $a->tipoDocumento] <=> [$b->sucursalNombre, $b->tipoDocumento]);

        return $esperados;
    }

    /**
     * Nombres de quienes debe traer el lote (mismo criterio que la cantidad).
     *
     * @return list<array{id: int, nombre: string, numero_empleado: string|null}>
     */
    public function integrantes(int $sucursalId, string $tipoDocumento): array
    {
        $inicio = self::inicioDeTipo($tipoDocumento);

        if ($inicio === null) {
            return [];
        }

        $fin = $inicio->addDays(6);

        return array_values($this->candidatos($inicio->toDateString(), $fin->toDateString())
            ->where('sucursal_principal_id', $sucursalId)
            ->orderBy('name')->orderBy('apellidos')
            ->get(['id', 'name', 'apellidos', 'numero_empleado', 'sucursal_principal_id', 'estatus', 'fecha_ingreso', 'fecha_baja'])
            ->filter(fn (Colaborador $c): bool => self::activoEnSemana($c, $inicio->toDateString(), $fin->toDateString()))
            ->map(fn (Colaborador $c): array => ['id' => $c->id, 'nombre' => $c->nombreCompleto(), 'numero_empleado' => $c->numero_empleado])
            ->values()
            ->all());
    }

    /**
     * Semanas (lunes–domingo) cuyo domingo cae en el mes y que ya empezaron.
     *
     * @return list<array{inicio: CarbonImmutable, fin: CarbonImmutable}>
     */
    public static function semanas(PeriodoRecepcion $periodo): array
    {
        $hoy = FechaMexico::hoy();
        $domingo = $periodo->inicio()->startOfDay();

        if ($domingo->dayOfWeek !== CarbonInterface::SUNDAY) {
            $domingo = $domingo->next(CarbonInterface::SUNDAY);
        }

        $semanas = [];

        while ($domingo->toDateString() <= $periodo->fin()->toDateString()) {
            $lunes = $domingo->subDays(6);

            if ($lunes->toDateString() <= $hoy) {
                $semanas[] = ['inicio' => $lunes, 'fin' => $domingo];
            }

            $domingo = $domingo->addWeek();
        }

        return $semanas;
    }

    /** Activo en la semana [inicio, fin] (fechas AAAA-MM-DD). */
    public static function activoEnSemana(Colaborador $c, string $inicio, string $fin): bool
    {
        $ingreso = $c->fecha_ingreso?->toDateString();

        if ($ingreso !== null && $ingreso > $fin) {
            return false;
        }

        $baja = $c->fecha_baja?->toDateString();

        if ($baja !== null && $ingreso !== null && $baja < $ingreso) {
            $baja = null;
        }

        if ($baja !== null) {
            return $baja >= $inicio;
        }

        return $c->estatus === EstadoUsuario::Activo;
    }

    private static function descripcion(CarbonImmutable $inicio, CarbonImmutable $fin, int $activos): string
    {
        return sprintf('Semana del %s al %s · %d colaborador%s activo%s', $inicio->format('d/m/Y'), $fin->format('d/m/Y'), $activos, $activos === 1 ? '' : 'es', $activos === 1 ? '' : 's');
    }

    private static function inicioDeTipo(string $tipo): ?CarbonImmutable
    {
        if (! str_starts_with($tipo, self::PREFIJO)) {
            return null;
        }

        $fecha = substr($tipo, strlen(self::PREFIJO));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) === 1 ? CarbonImmutable::parse($fecha, 'UTC')->startOfDay() : null;
    }

    /**
     * Una sola consulta acotada: quien pudo estar activo en algún día del
     * rango, en sucursales que llevan lista (sin corporativo, salvo que la
     * configuración lo pida). El filtro fino por semana es activoEnSemana().
     *
     * @return Builder<Colaborador>
     */
    private function candidatos(string $desde, string $hasta): Builder
    {
        $incluirCorporativo = (bool) config('recepcion_documental.listas_asistencia.incluir_corporativo', false);

        return Colaborador::query()
            ->whereNotNull('sucursal_principal_id')
            ->when(! $incluirCorporativo, fn (Builder $q) => $q->whereHas('sucursalPrincipal', fn (Builder $s) => $s->where('es_corporativo', false)))
            ->where(fn (Builder $q) => $q->whereNull('fecha_ingreso')->orWhereDate('fecha_ingreso', '<=', $hasta))
            ->where(fn (Builder $q) => $q->where('estatus', EstadoUsuario::Activo->value)
                ->orWhere(fn (Builder $b) => $b->whereNotNull('fecha_baja')->whereDate('fecha_baja', '>=', $desde)));
    }
}
