<?php

namespace App\Services\Reportes;

use App\Enums\EstadoUsuario;
use App\Enums\EstatusBaja;
use App\Enums\TipoMovimientoLaboral;
use App\Models\AltaHistorica;
use App\Models\Baja;
use App\Models\Colaborador;
use App\Models\MovimientoLaboral;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Reportes nominales de trabajo (detalle por persona): directorio activo, altas
 * y bajas del periodo, y aniversario de alta IMSS.
 *
 * Altas: una sola serie con tres orígenes, en este orden de confianza, sin
 * contar dos veces a la misma persona en la misma fecha: movimiento de alta,
 * altas históricas confirmadas por RH y la fecha de ingreso de quien no tiene
 * movimiento. Nunca se deduce una alta a partir de una baja. Antes del primer
 * registro no hay información: esos meses salen «Sin información», no en cero.
 *
 * @phpstan-type Alta array{nombre: string, fecha: string, sucursal: string|null, puesto: string|null, origen: string}
 * @phpstan-type Reporte array{titulo: string, columnas: array<int, string>, filas: array<int, array<int, string|int|float|null>>, avisos?: list<string>}
 */
class ReportesNominalesService
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    /**
     * Enmascara un identificador oficial dejando visibles los últimos 4.
     */
    public static function enmascarar(?string $valor): ?string
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }

        return sprintf('•••• %s', mb_substr(trim($valor), -4));
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Reporte
     */
    public function directorioActivo(User $usuario, array $filtros): array
    {
        $filas = $this->colaboradores($usuario, $filtros)
            ->where('estatus', EstadoUsuario::Activo->value)
            ->with(['sucursalPrincipal:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre'])
            ->orderBy('name')
            ->get()
            ->map(fn (Colaborador $c): array => [
                $c->nombreCompleto(),
                $c->puesto->nombre ?? '—',
                $c->sucursalPrincipal->nombre ?? '—',
                $c->departamento->nombre ?? '—',
                $c->telefono_corporativo ?: '—',
                $c->correo_corporativo ?: '—',
            ])
            ->all();

        return [
            'titulo' => 'Directorio de personal activo',
            'columnas' => ['Nombre', 'Puesto', 'Sucursal', 'Departamento', 'Teléfono corporativo', 'Correo corporativo'],
            'filas' => $filas,
        ];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Reporte
     */
    public function altasDelPeriodo(User $usuario, array $filtros): array
    {
        [$desde, $hasta] = $this->periodo($filtros);
        $altas = $this->altas($usuario, $filtros)
            ->filter(fn (array $a): bool => $a['fecha'] >= $desde->toDateString() && $a['fecha'] <= $hasta->toDateString())
            ->sortBy([['fecha', 'asc'], ['nombre', 'asc']]);

        return [
            'titulo' => sprintf('Altas del %s al %s', $desde->format('d/m/Y'), $hasta->format('d/m/Y')),
            'columnas' => ['Nombre', 'Fecha de ingreso', 'Sucursal', 'Puesto', 'Origen'],
            'filas' => $altas->map(fn (array $a): array => [$a['nombre'], CarbonImmutable::parse($a['fecha'])->format('d/m/Y'), $a['sucursal'] ?? '—', $a['puesto'] ?? '—', $a['origen']])->values()->all(),
        ];
    }

    /**
     * Últimos 12 meses con la misma fuente de altas del reporte nominal.
     *
     * @param  array<string, mixed>  $filtros
     * @return Reporte
     */
    public function altasPorMes(User $usuario, array $filtros): array
    {
        $altas = $this->altas($usuario, $filtros);
        $conteos = $altas->map(fn (array $a): string => mb_substr($a['fecha'], 0, 7))->countBy();
        $inicioMovimientos = $altas->where('origen', '!=', 'Histórico')->min('fecha');
        $historicas = $altas->where('origen', 'Histórico');
        $desdeHistorico = $historicas->min('fecha');
        $hastaHistorico = $historicas->max('fecha');
        $primero = CarbonImmutable::now('America/Mexico_City')->subMonths(11)->startOfMonth();
        $filas = [];

        for ($i = 0; $i < 12; $i++) {
            $mes = $primero->addMonths($i);
            $clave = $mes->format('Y-m');
            $cubierto = (is_string($inicioMovimientos) && $clave >= mb_substr($inicioMovimientos, 0, 7))
                || (is_string($desdeHistorico) && is_string($hastaHistorico) && $clave >= mb_substr($desdeHistorico, 0, 7) && $clave <= mb_substr($hastaHistorico, 0, 7));

            $filas[] = [
                $mes->translatedFormat('F Y'),
                $cubierto ? (int) ($conteos[$clave] ?? 0) : null,
                $cubierto ? 'Con información' : 'Sin información',
            ];
        }

        return ['titulo' => 'Altas por mes', 'columnas' => ['Mes', 'Total', 'Información'], 'filas' => $filas];
    }

    /**
     * Por fecha de último día, con el mismo criterio de vigencia de Rotación:
     * cuenta aunque el expediente siga en trámite; canceladas y no procedentes no.
     *
     * @param  array<string, mixed>  $filtros
     * @return Reporte
     */
    public function bajasDelPeriodo(User $usuario, array $filtros): array
    {
        [$desde, $hasta] = $this->periodo($filtros);

        $filas = $this->alcance->limitarPorSucursal(Baja::query(), $usuario)
            ->whereIn('estatus', [EstatusBaja::EnRevisionRh->value, EstatusBaja::EnRevisionJuridico->value, EstatusBaja::Cerrada->value])
            ->whereBetween('fecha_baja', [$desde->toDateString(), $hasta->toDateString()])
            ->when($filtros['sucursal_id'] ?? null, fn (Builder $q, $v) => $q->where('sucursal_id', $v))
            ->when($filtros['puesto_id'] ?? null, fn (Builder $q, $v) => $q->where('puesto_id', $v))
            ->with(['sucursal:id,nombre', 'causal:id,clave,nombre'])
            ->orderBy('fecha_baja')
            ->get()
            ->map(fn (Baja $b): array => [
                $b->nombre_colaborador,
                $b->fecha_baja->format('d/m/Y'),
                $b->sucursal->nombre ?? '—',
                $b->forma_salida?->etiqueta() ?? '—',
                $b->causal === null ? '—' : trim(sprintf('%s %s', $b->causal->clave ?? '', $b->causal->nombre)),
                $b->estatus->etiqueta(),
            ])
            ->all();

        return [
            'titulo' => sprintf('Bajas del %s al %s', $desde->format('d/m/Y'), $hasta->format('d/m/Y')),
            'columnas' => ['Nombre', 'Fecha de último día', 'Sucursal', 'Forma de salida', 'Causal', 'Estatus'],
            'filas' => $filas,
        ];
    }

    /**
     * Usa fecha_alta_imss, independiente de la fecha de ingreso. El NSS completo
     * exige reportes_rh.datos_sensibles. No hace cálculos de IDSE ni SUA.
     *
     * @param  array<string, mixed>  $filtros
     * @return Reporte
     */
    public function aniversarioImss(User $usuario, array $filtros): array
    {
        $hoy = CarbonImmutable::now('America/Mexico_City');
        $mes = (int) ($filtros['mes'] ?? 0);
        $mes = $mes >= 1 && $mes <= 12 ? $mes : $hoy->month;
        $verNss = $usuario->can('reportes_rh.datos_sensibles');
        $vigentes = $this->colaboradores($usuario, $filtros)
            ->whereIn('estatus', [EstadoUsuario::Activo->value, EstadoUsuario::EnIncorporacion->value])
            ->with(['sucursalPrincipal:id,nombre', 'puesto:id,nombre'])
            ->get();

        $filas = $vigentes
            ->filter(fn (Colaborador $c): bool => $c->fecha_alta_imss !== null && $c->fecha_alta_imss->month === $mes)
            ->sortBy(fn (Colaborador $c): int => (int) $c->fecha_alta_imss?->day)
            ->map(fn (Colaborador $c): array => [
                $c->nombreCompleto(),
                $verNss ? ($c->nss ?: '—') : (self::enmascarar($c->nss) ?? '—'),
                $c->sucursalPrincipal->nombre ?? '—',
                $c->puesto->nombre ?? '—',
                $c->fecha_alta_imss?->format('d/m/Y'),
            ])
            ->values()
            ->all();

        $sinFecha = $vigentes->filter(fn (Colaborador $c): bool => $c->fecha_alta_imss === null)->count();
        $nombreMes = $hoy->setDate($hoy->year, $mes, 1)->translatedFormat('F');

        return [
            'titulo' => sprintf('Aniversario de alta en el IMSS · %s', $nombreMes),
            'columnas' => ['Nombre', 'NSS', 'Sucursal', 'Puesto', 'Fecha de alta IMSS'],
            'filas' => $filas,
            'avisos' => $sinFecha > 0 ? [sprintf('%d colaborador(es) vigentes sin fecha de alta IMSS capturada no aparecen aquí.', $sinFecha)] : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Builder<Colaborador>
     */
    private function colaboradores(User $usuario, array $filtros): Builder
    {
        return $this->alcance->limitarColaboradoresPorAlcance(Colaborador::query(), $usuario)
            ->when($filtros['empresa_id'] ?? null, fn (Builder $q, $v) => $q->whereHas('sucursalPrincipal', fn (Builder $s) => $s->where('empresa_id', $v)))
            ->when($filtros['sucursal_id'] ?? null, fn (Builder $q, $v) => $q->where('sucursal_principal_id', $v))
            ->when($filtros['departamento_id'] ?? null, fn (Builder $q, $v) => $q->where('departamento_id', $v))
            ->when($filtros['puesto_id'] ?? null, fn (Builder $q, $v) => $q->where('puesto_id', $v));
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, Alta>
     */
    private function altas(User $usuario, array $filtros): Collection
    {
        $colaboradores = $this->alcance->limitarColaboradoresPorAlcance(Colaborador::withTrashed(), $usuario)
            ->when($filtros['empresa_id'] ?? null, fn (Builder $q, $v) => $q->whereHas('sucursalPrincipal', fn (Builder $s) => $s->where('empresa_id', $v)))
            ->with(['sucursalPrincipal:id,nombre', 'puesto:id,nombre'])
            ->get()
            ->keyBy('id');
        $sucursalFiltro = (int) ($filtros['sucursal_id'] ?? 0);
        $puestoFiltro = (int) ($filtros['puesto_id'] ?? 0);
        $sucursales = Sucursal::query()->pluck('nombre', 'id');
        $puestos = Puesto::query()->pluck('nombre', 'id');
        $altas = collect();
        $vistas = [];
        $conMovimiento = [];

        MovimientoLaboral::query()
            ->whereIn('colaborador_id', $colaboradores->keys())
            ->where('tipo_movimiento', TipoMovimientoLaboral::Alta->value)
            ->get(['colaborador_id', 'fecha_movimiento', 'sucursal_nueva_id', 'puesto_nuevo_id'])
            ->each(function (MovimientoLaboral $m) use ($colaboradores, $sucursales, $puestos, $sucursalFiltro, $puestoFiltro, &$altas, &$vistas, &$conMovimiento): void {
                /** @var Colaborador|null $colaborador */
                $colaborador = $colaboradores->get($m->colaborador_id);

                if ($colaborador === null) {
                    return;
                }

                $conMovimiento[$colaborador->id] = true;
                $sucursalId = (int) ($m->sucursal_nueva_id ?? $colaborador->sucursal_principal_id);
                $puestoId = (int) ($m->puesto_nuevo_id ?? $colaborador->puesto_id);
                $fecha = CarbonImmutable::parse($m->fecha_movimiento)->toDateString();
                $vistas[sprintf('%d|%s', $colaborador->id, $fecha)] = true;

                if (($sucursalFiltro > 0 && $sucursalId !== $sucursalFiltro) || ($puestoFiltro > 0 && $puestoId !== $puestoFiltro)) {
                    return;
                }

                $altas->push(['nombre' => $colaborador->nombreCompleto(), 'fecha' => $fecha, 'sucursal' => $sucursales[$sucursalId] ?? null, 'puesto' => $puestos[$puestoId] ?? null, 'origen' => 'Sistema']);
            });

        // Las históricas respetan el mismo alcance por sucursal.
        $visibles = $this->alcance->sucursalesVisiblesIds($usuario);

        AltaHistorica::query()
            ->whereIn('sucursal_id', $visibles)
            ->when($sucursalFiltro > 0, fn (Builder $q) => $q->where('sucursal_id', $sucursalFiltro))
            ->when($puestoFiltro > 0, fn (Builder $q) => $q->where('puesto_id', $puestoFiltro))
            ->with(['sucursal:id,nombre', 'puesto:id,nombre'])
            ->get()
            ->each(function (AltaHistorica $a) use (&$altas, &$vistas): void {
                $fecha = $a->fecha_alta->toDateString();

                if ($a->colaborador_id !== null) {
                    $llave = sprintf('%d|%s', $a->colaborador_id, $fecha);

                    if (isset($vistas[$llave])) {
                        return;
                    }

                    $vistas[$llave] = true;
                }

                $altas->push(['nombre' => $a->nombre, 'fecha' => $fecha, 'sucursal' => $a->sucursal?->nombre, 'puesto' => $a->puesto?->nombre, 'origen' => 'Histórico']);
            });

        foreach ($colaboradores as $colaborador) {
            if ($colaborador->fecha_ingreso === null || isset($conMovimiento[$colaborador->id])) {
                continue;
            }

            $fecha = $colaborador->fecha_ingreso->toDateString();

            if (isset($vistas[sprintf('%d|%s', $colaborador->id, $fecha)])
                || ($sucursalFiltro > 0 && (int) $colaborador->sucursal_principal_id !== $sucursalFiltro)
                || ($puestoFiltro > 0 && (int) $colaborador->puesto_id !== $puestoFiltro)) {
                continue;
            }

            $altas->push(['nombre' => $colaborador->nombreCompleto(), 'fecha' => $fecha, 'sucursal' => $colaborador->sucursalPrincipal?->nombre, 'puesto' => $colaborador->puesto?->nombre, 'origen' => 'Fecha de ingreso']);
        }

        return $altas;
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function periodo(array $filtros): array
    {
        $hoy = CarbonImmutable::now('America/Mexico_City');

        return [
            CarbonImmutable::parse(is_string($filtros['fecha_inicio'] ?? null) && $filtros['fecha_inicio'] !== '' ? $filtros['fecha_inicio'] : $hoy->startOfMonth()->toDateString()),
            CarbonImmutable::parse(is_string($filtros['fecha_fin'] ?? null) && $filtros['fecha_fin'] !== '' ? $filtros['fecha_fin'] : $hoy->endOfMonth()->toDateString()),
        ];
    }
}
