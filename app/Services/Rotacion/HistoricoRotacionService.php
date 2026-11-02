<?php

namespace App\Services\Rotacion;

use App\Enums\ClasificacionSalida;
use App\Enums\EstatusBaja;
use App\Enums\RangoAntiguedad;
use App\Models\Baja;
use App\Models\BajaHistorica;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\FiniquitoCalculo;
use App\Models\Puesto;
use App\Models\RotacionCorreccion;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Flujos\ConstructorEnlaces;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Histórico individual de bajas (sistema + históricas importadas) del periodo
 * y alcance, paginado en el servidor. Recibe los filtros generales y, aparte,
 * el «desglose» con el que se llegó desde una gráfica (un mes, una sucursal,
 * una causal, un rango de antigüedad), sin cambiar los filtros del tablero.
 *
 * Cada fila trae solo las acciones que el usuario puede abrir (ConstructorEnlaces
 * revalida alcance y permisos): nunca un enlace que termine en 403.
 */
class HistoricoRotacionService
{
    public const RAPIDOS = ['pendientes', 'sin_causal', 'sin_fecha', 'inconsistente', 'sin_homologar', 'temprana', 'estructural'];

    public const ORDENES = ['fecha_baja', 'nombre', 'sucursal', 'antiguedad', 'causal'];

    public function __construct(
        private readonly RotacionMotorService $motor,
        private readonly ConstructorEnlaces $enlaces,
    ) {}

    /**
     * Eventos ya filtrados (sin paginar): lo usan la tabla y la exportación.
     *
     * @param  array<string, mixed>  $entrada
     * @return Collection<int, array<string, mixed>>
     */
    public function filtrados(FiltrosRotacion $f, array $entrada): Collection
    {
        $eventos = $this->motor->eventos($f);
        $analiticas = $this->motor->analiticas();
        $sinCausal = $this->motor->idSinCausal();
        $busqueda = BajaHistorica::normalizar((string) ($entrada['q'] ?? ''));
        $rapido = in_array($entrada['rapido'] ?? null, self::RAPIDOS, true) ? (string) $entrada['rapido'] : null;
        $dMes = is_string($entrada['d_mes'] ?? null) && preg_match('/^\d{4}-\d{2}$/', $entrada['d_mes']) === 1 ? $entrada['d_mes'] : null;
        $dSucursal = is_numeric($entrada['d_sucursal'] ?? null) ? (int) $entrada['d_sucursal'] : null;
        $dCausal = $entrada['d_causal'] ?? null;
        $dRango = RangoAntiguedad::tryFrom((string) ($entrada['d_rango'] ?? ''));
        $dClasificacion = ClasificacionSalida::tryFrom((string) ($entrada['d_clasificacion'] ?? ''));

        $filtrados = $eventos->filter(function (array $e) use ($busqueda, $rapido, $dMes, $dSucursal, $dCausal, $dRango, $dClasificacion, $sinCausal, $analiticas): bool {
            if ($busqueda !== '' && ! str_contains(BajaHistorica::normalizar($e['nombre'].' '.($e['numero_empleado'] ?? '')), $busqueda)) {
                return false;
            }

            if (($dMes !== null && $e['mes'] !== $dMes)
                || ($dSucursal !== null && $e['sucursal_id'] !== $dSucursal)
                || ($dRango !== null && $e['rango'] !== $dRango)
                || ($dClasificacion !== null && $e['clasificacion'] !== $dClasificacion)) {
                return false;
            }

            if ($dCausal !== null && $dCausal !== '') {
                $coincide = $dCausal === 'pendiente' ? $e['causal_analitica_id'] === null : $e['causal_analitica_id'] === (int) $dCausal;

                if (! $coincide) {
                    return false;
                }
            }

            return match ($rapido) {
                'pendientes' => $e['causal_analitica_id'] === $sinCausal || ! $e['homologada'] || $e['fecha_ingreso'] === null || $e['fecha_inconsistente'],
                'sin_causal' => $e['causal_analitica_id'] === $sinCausal,
                'sin_homologar' => ! $e['homologada'],
                'sin_fecha' => $e['fecha_ingreso'] === null,
                'inconsistente' => $e['fecha_inconsistente'],
                'temprana' => $e['rango']->esTemprana(),
                'estructural' => $e['causal_analitica_id'] !== null && ($analiticas->get($e['causal_analitica_id'])->es_estructural ?? false),
                default => true,
            };
        });

        $orden = in_array($entrada['orden'] ?? null, self::ORDENES, true) ? (string) $entrada['orden'] : 'fecha_baja';
        $desc = ($entrada['dir'] ?? ($orden === 'fecha_baja' ? 'desc' : 'asc')) === 'desc';
        $nombresSucursal = $orden === 'sucursal' ? $this->motor->nombresUbicaciones($filtrados->pluck('sucursal_id')->unique()->values()) : collect();

        $llave = match ($orden) {
            'nombre' => fn (array $e): string => BajaHistorica::normalizar($e['nombre']),
            'sucursal' => fn (array $e): string => BajaHistorica::normalizar((string) ($nombresSucursal[$e['sucursal_id']] ?? '')),
            'antiguedad' => fn (array $e): int => $e['antiguedad_dias'] ?? -1,
            'causal' => fn (array $e): string => (string) ($analiticas->get((int) $e['causal_analitica_id'])->nombre ?? 'zzz'),
            default => fn (array $e): string => $e['fecha_baja_texto'].$e['clave'],
        };

        return ($desc ? $filtrados->sortByDesc($llave) : $filtrados->sortBy($llave))->values();
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function pagina(User $usuario, FiltrosRotacion $f, array $entrada, int $porPagina, int $pagina): LengthAwarePaginator
    {
        $todos = $this->filtrados($f, $entrada);
        $pagina = max(1, $pagina);
        $filas = $this->filas($usuario, $todos->forPage($pagina, $porPagina)->values());

        return new LengthAwarePaginator($filas, $todos->count(), $porPagina, $pagina, ['path' => request()->url(), 'query' => request()->query()]);
    }

    /**
     * Filas listas para la tabla o la exportación (nombres en vez de ids).
     *
     * @param  Collection<int, array<string, mixed>>  $eventos
     * @return list<array<string, mixed>>
     */
    public function filas(User $usuario, Collection $eventos, bool $conAcciones = true): array
    {
        $analiticas = $this->motor->analiticas();
        $sinCausal = $this->motor->idSinCausal();
        $sucursales = $this->motor->nombresUbicaciones($eventos->pluck('sucursal_id')->unique()->values());
        $externas = $this->motor->unidadesExternas();
        $empresas = Empresa::query()->pluck('nombre', 'id');
        $puestos = Puesto::query()->withTrashed()->whereIn('id', $eventos->pluck('puesto_id')->filter()->unique()->all())->pluck('nombre', 'id');
        $departamentos = Departamento::query()->whereIn('id', $eventos->pluck('departamento_id')->filter()->unique()->all())->pluck('nombre', 'id');
        $puedeCorregir = $usuario->can('bajas.gestionar');

        $bajas = $conAcciones ? Baja::query()->whereIn('id', $eventos->pluck('baja_id')->filter()->all())->get()->keyBy('id') : collect();
        $colaboradores = $conAcciones ? Colaborador::query()->withTrashed()->whereIn('id', $eventos->pluck('colaborador_id')->filter()->unique()->all())->get()->keyBy('id') : collect();
        $finiquitos = $conAcciones ? FiniquitoCalculo::query()->whereIn('colaborador_id', $colaboradores->keys()->all())->latest('id')->get()->unique('colaborador_id')->keyBy('colaborador_id') : collect();
        $correcciones = $conAcciones ? $this->conteoCorrecciones($eventos) : collect();

        return array_values($eventos->map(function (array $e) use ($usuario, $analiticas, $sinCausal, $sucursales, $externas, $empresas, $puestos, $departamentos, $puedeCorregir, $bajas, $colaboradores, $finiquitos, $correcciones, $conAcciones): array {
            $causal = $e['causal_analitica_id'] !== null ? $analiticas->get($e['causal_analitica_id']) : null;
            $estatus = $e['estatus'] !== null ? EstatusBaja::tryFrom($e['estatus']) : null;
            $fila = [
                'clave' => $e['clave'],
                'fuente' => $e['fuente'],
                'fuente_etiqueta' => $e['fuente'] === 'sistema' ? 'Sistema' : 'Histórico',
                'id' => $e['id'],
                'nombre' => $e['nombre'],
                'numero_empleado' => $e['numero_empleado'],
                'empresa' => $e['empresa_id'] !== null ? ($empresas[$e['empresa_id']] ?? null) : ($externas[$e['sucursal_id']]['grupo'] ?? null),
                'sucursal_id' => $e['sucursal_id'],
                'sucursal' => (string) ($sucursales[$e['sucursal_id']] ?? ''),
                'puesto' => $e['puesto_id'] !== null ? ($puestos[$e['puesto_id']] ?? null) : null,
                'departamento' => $e['departamento_id'] !== null ? ($departamentos[$e['departamento_id']] ?? null) : null,
                'fecha_ingreso' => $e['fecha_ingreso']?->toDateString(),
                'fecha_baja' => $e['fecha_baja_texto'],
                'antiguedad_dias' => $e['antiguedad_dias'],
                'antiguedad_aproximada' => $e['antiguedad_aproximada'],
                'rango' => $e['rango']->value,
                'rango_etiqueta' => $e['rango']->etiqueta(),
                'motivo_original' => $e['motivo_original'],
                'causal_catalogo' => $e['causal_rotacion'],
                'causal_analitica_id' => $e['causal_analitica_id'],
                'causal' => $causal->nombre ?? 'Sin homologar',
                'clasificacion' => $e['clasificacion']->value,
                'clasificacion_etiqueta' => $e['clasificacion']->etiqueta(),
                'estado' => $estatus?->etiqueta() ?? 'Histórico importado',
                'alertas' => array_values(array_filter([
                    $e['causal_analitica_id'] === $sinCausal ? 'sin_causal' : null,
                    $e['homologada'] ? null : 'sin_homologar',
                    $e['fecha_ingreso'] === null ? 'sin_fecha' : null,
                    $e['fecha_inconsistente'] ? 'inconsistente' : null,
                ])),
            ];

            if (! $conAcciones) {
                return $fila;
            }

            $baja = $e['baja_id'] !== null ? $bajas->get($e['baja_id']) : null;
            $colaborador = $e['colaborador_id'] !== null ? $colaboradores->get($e['colaborador_id']) : null;
            $finiquito = $colaborador !== null ? $finiquitos->get($colaborador->id) : null;

            $fila['acciones'] = [
                'ver_baja' => $baja instanceof Baja ? $this->enlaces->baja($usuario, $baja) : null,
                'expediente' => $colaborador instanceof Colaborador ? $this->enlaces->expediente($usuario, $colaborador) : null,
                'cierre' => $colaborador instanceof Colaborador ? $this->enlaces->ciclo($usuario, $colaborador) : null,
                'finiquito' => $finiquito instanceof FiniquitoCalculo ? $this->enlaces->finiquito($usuario, $finiquito) : null,
                'corregir_causal' => $puedeCorregir,
                'completar_fecha' => $puedeCorregir && $e['fuente'] === 'historico' && ($e['fecha_ingreso'] === null || $e['fecha_inconsistente']),
                'correcciones' => (int) ($correcciones[$e['clave']] ?? 0),
            ];

            return $fila;
        })->all());
    }

    /**
     * Bitácora de correcciones de un evento, de la más reciente a la más vieja.
     *
     * @return list<array{campo: string, antes: string|null, despues: string|null, comentario: string, usuario: string|null, fecha: string|null}>
     */
    public function bitacora(string $fuente, int $id): array
    {
        return array_values(RotacionCorreccion::query()
            ->with('usuario:id,name')
            ->where('sujeto_tipo', $fuente === 'sistema' ? 'baja' : 'baja_historica')
            ->where('sujeto_id', $id)
            ->latest('id')
            ->get()
            ->map(fn (RotacionCorreccion $c): array => [
                'campo' => $c->campo,
                'antes' => $c->antes,
                'despues' => $c->despues,
                'comentario' => $c->comentario,
                'usuario' => $c->usuario?->name,
                'fecha' => $c->created_at?->format('Y-m-d H:i'),
            ])->all());
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $eventos
     * @return Collection<string, int>
     */
    private function conteoCorrecciones(Collection $eventos): Collection
    {
        $porTipo = ['baja' => $eventos->where('fuente', 'sistema')->pluck('id')->all(), 'baja_historica' => $eventos->where('fuente', 'historico')->pluck('id')->all()];
        $conteo = collect();

        foreach ($porTipo as $tipo => $ids) {
            if ($ids === []) {
                continue;
            }

            RotacionCorreccion::query()->where('sujeto_tipo', $tipo)->whereIn('sujeto_id', $ids)
                ->selectRaw('sujeto_id, count(*) as total')->groupBy('sujeto_id')->get()
                ->each(function (RotacionCorreccion $c) use ($conteo, $tipo): void {
                    $conteo->put(sprintf('%s:%d', $tipo === 'baja' ? 'sistema' : 'historico', $c->sujeto_id), (int) $c->getAttribute('total'));
                });
        }

        return $conteo;
    }
}
