<?php

namespace App\Services\Rotacion;

use App\Enums\ClasificacionSalida;
use App\Enums\RangoAntiguedad;
use App\Models\BajaHistorica;
use App\Models\CausalAnalitica;
use App\Models\ImportacionExcel;
use App\Models\Sucursal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Carga de un histórico individual de bajas desde un archivo versionado
 * (comando rotacion:cargar-historico) y su conciliación contra los totales
 * que publicó RH. La regla de carga es la de BajasHistoricasImportService
 * (leer → registrar → aplicar); aquí solo se agrega:
 *
 *  - totales del archivo y de lo que ya cuenta Rotación (RotacionAnalisisService,
 *    el mismo motor de la pantalla) en la forma de los totales esperados;
 *  - aplicar la carga todo o nada, auditada en importaciones_excel con quién,
 *    cuándo, desde dónde y por qué; idempotente por la llave de cada evento.
 *
 * Nunca borra ni modifica bajas existentes.
 */
class CargaHistoricoRotacionService
{
    public function __construct(
        private readonly BajasHistoricasImportService $importador,
    ) {}

    /**
     * @return array{filas: list<array<string, mixed>>, columnas: list<string>}
     */
    public function leer(string $ruta): array
    {
        return $this->importador->leer($ruta);
    }

    /**
     * Cuántas filas hay por estado (nuevo, sin_cambio, duplicado_sistema, error).
     *
     * @param  list<array<string, mixed>>  $filas
     * @return array<string, int>
     */
    public function porEstado(array $filas): array
    {
        $cuenta = ['nuevo' => 0, 'sin_cambio' => 0, 'duplicado_sistema' => 0, 'error' => 0];

        foreach ($filas as $fila) {
            $cuenta[(string) $fila['estado']] = ($cuenta[(string) $fila['estado']] ?? 0) + 1;
        }

        return $cuenta;
    }

    /**
     * Totales del archivo (filas sin error) con la misma forma que los
     * esperados: total, por mes (con tipo), causal, tipo, antigüedad y
     * ubicación.
     *
     * @param  list<array<string, mixed>>  $filas
     * @return array<string, mixed>
     */
    public function totalesArchivo(array $filas): array
    {
        $causales = CausalAnalitica::query()->get()->keyBy('id');
        $t = $this->vacio();

        foreach ($filas as $fila) {
            if ($fila['estado'] === 'error' || ! is_string($fila['fecha_baja'] ?? null)) {
                continue;
            }

            $baja = CarbonImmutable::parse($fila['fecha_baja']);
            $causal = $causales->get((int) $fila['causal_analitica_id']);
            $tipo = $causal?->clasificacion->value ?? ClasificacionSalida::SinClasificar->value;
            $ingreso = is_string($fila['fecha_ingreso'] ?? null) ? CarbonImmutable::parse($fila['fecha_ingreso']) : null;
            $rango = $ingreso !== null
                ? RangoAntiguedad::de($ingreso, $baja)
                : (RangoAntiguedad::tryFrom((string) ($fila['rango_antiguedad'] ?? '')) ?? RangoAntiguedad::SinFecha);
            $mes = $baja->format('Y-m');

            $t['total']++;
            $t['por_mes'][$mes]['total'] = ($t['por_mes'][$mes]['total'] ?? 0) + 1;
            $t['por_mes'][$mes][$tipo] = ($t['por_mes'][$mes][$tipo] ?? 0) + 1;
            $t['por_causal'][$causal->clave ?? CausalAnalitica::SIN_CAUSAL] = ($t['por_causal'][$causal->clave ?? CausalAnalitica::SIN_CAUSAL] ?? 0) + 1;
            $t['por_tipo'][$tipo] = ($t['por_tipo'][$tipo] ?? 0) + 1;
            $t['por_antiguedad'][$rango->value] = ($t['por_antiguedad'][$rango->value] ?? 0) + 1;
            $t['por_ubicacion'][(string) $fila['sucursal']] = ($t['por_ubicacion'][(string) $fila['sucursal']] ?? 0) + 1;
        }

        return $t;
    }

    /**
     * Lo que Rotación cuenta HOY para el periodo, con todas las sucursales y
     * las unidades externas (sin filtros de alcance), por el mismo servicio
     * que la pantalla.
     *
     * @param  'todas'|'historico'  $fuente
     * @return array<string, mixed>
     */
    public function totalesRotacion(CarbonImmutable $desde, CarbonImmutable $hasta, string $fuente): array
    {
        // Instancias nuevas: el motor guarda en memoria lo ya calculado y aquí
        // se consulta antes y después de cargar en el mismo proceso.
        $analisis = app(RotacionAnalisisService::class);
        $hasta = $hasta->min(CarbonImmutable::today());
        $sucursales = Sucursal::query()->pluck('id')->map(fn ($id): int => (int) $id)->values();
        $f = new FiltrosRotacion('rango', $desde->year, null, $desde, $hasta, $sucursales, null, null, null, null, null, null, null, $fuente, false, externas: true);
        $t = $this->vacio();
        $resumen = $analisis->resumen($f);
        $t['total'] = (int) $resumen['total'];
        $t['por_tipo'] = [
            ClasificacionSalida::Voluntaria->value => (int) $resumen['voluntarias']['bajas'],
            ClasificacionSalida::Involuntaria->value => (int) $resumen['involuntarias']['bajas'],
            ClasificacionSalida::SinClasificar->value => (int) $resumen['sin_clasificar']['bajas'],
        ];

        foreach ($analisis->tendencia($f)['meses'] as $m) {
            $t['por_mes'][(string) $m['periodo']] = [
                'total' => (int) $m['total'],
                ClasificacionSalida::Voluntaria->value => (int) $m['voluntarias'],
                ClasificacionSalida::Involuntaria->value => (int) $m['involuntarias'],
                ClasificacionSalida::SinClasificar->value => (int) $m['sin_clasificar'],
            ];
        }

        foreach ($analisis->causales($f)['causales'] as $c) {
            $t['por_causal'][(string) $c['clave']] = (int) $c['bajas'];
        }

        foreach ($analisis->antiguedad($f)['rangos'] as $r) {
            if ($r['bajas'] > 0) {
                $t['por_antiguedad'][(string) $r['clave']] = (int) $r['bajas'];
            }
        }

        foreach ($analisis->sucursales($f) as $s) {
            $t['por_ubicacion'][(string) $s['sucursal']] = (int) $s['bajas'];
        }

        $t['discrepancias_excel'] = array_values(array_map(
            fn (array $d): string => sprintf('%s %s: Excel %d, detalle %d', $d['mes'], $d['sucursal'], (int) $d['agregado'], (int) $d['individuales']),
            $analisis->calidad($f)['discrepancias'],
        ));

        return $t;
    }

    /**
     * Filas «indicador | esperado | obtenido… | ¿cuadra?» para imprimir. Las
     * ubicaciones se comparan normalizadas («Miacatlán» = «Miacatlan»).
     *
     * @param  array<string, mixed>  $esperado
     * @param  array<string, array<string, mixed>>  $obtenidos  etiqueta => totales
     * @return list<array{0: string, 1: string, 2: list<string>, 3: bool}>
     */
    public function comparar(array $esperado, array $obtenidos): array
    {
        $filas = [];
        $valor = function (array $totales, string $grupo, ?string $clave, ?string $sub = null): int {
            if ($clave === null) {
                return (int) ($totales[$grupo] ?? 0);
            }

            $mapa = (array) ($totales[$grupo] ?? []);

            if ($grupo === 'por_ubicacion') {
                foreach ($mapa as $k => $v) {
                    if (BajaHistorica::normalizar((string) $k) === BajaHistorica::normalizar($clave)) {
                        return (int) $v;
                    }
                }

                return 0;
            }

            $v = $mapa[$clave] ?? 0;

            if ($sub === null) {
                return is_numeric($v) ? (int) $v : 0;
            }

            $detalle = is_array($v) ? $v : [];

            return is_numeric($detalle[$sub] ?? null) ? (int) $detalle[$sub] : 0;
        };
        $agregar = function (string $etiqueta, int $esperadoValor, string $grupo, ?string $clave, ?string $sub = null) use (&$filas, $obtenidos, $valor): void {
            $valores = array_map(fn (array $t): int => $valor($t, $grupo, $clave, $sub), $obtenidos);
            $filas[] = [$etiqueta, (string) $esperadoValor, array_map('strval', array_values($valores)), count(array_unique([...$valores, $esperadoValor])) === 1];
        };

        $agregar('Total', (int) ($esperado['total'] ?? 0), 'total', null);

        foreach ((array) ($esperado['por_mes'] ?? []) as $mes => $tipos) {
            foreach ((array) $tipos as $tipo => $n) {
                $agregar(sprintf('Mes %s · %s', $mes, $tipo), (int) $n, 'por_mes', (string) $mes, (string) $tipo);
            }
        }

        foreach (['por_causal' => 'Causal', 'por_tipo' => 'Tipo', 'por_antiguedad' => 'Antigüedad', 'por_ubicacion' => 'Ubicación'] as $grupo => $nombre) {
            foreach ((array) ($esperado[$grupo] ?? []) as $clave => $n) {
                $agregar(sprintf('%s · %s', $nombre, $clave), (int) $n, $grupo, (string) $clave);
            }
        }

        return $filas;
    }

    /**
     * Aplica la carga (todo o nada). Las filas ya cargadas se omiten por su
     * llave; si no hay nada nuevo no escribe nada y devuelve null.
     *
     * @param  array{filas: list<array<string, mixed>>, columnas: list<string>}  $lectura
     * @param  array<string, mixed>  $metadatos
     */
    public function aplicar(string $ruta, array $lectura, User $usuario, string $motivo, array $metadatos = []): ?ImportacionExcel
    {
        $estados = $this->porEstado($lectura['filas']);

        if ($estados['error'] > 0) {
            throw ValidationException::withMessages(['archivo' => sprintf('El archivo tiene %d fila(s) con error: corrígelas antes de cargar.', $estados['error'])]);
        }

        if ($estados['nuevo'] + $estados['duplicado_sistema'] === 0) {
            return null;
        }

        $hash = (string) hash_file('sha256', $ruta);

        if (ImportacionExcel::query()->where('archivo_hash', $hash)->where('estado', 'aplicado')->exists()) {
            throw ValidationException::withMessages(['archivo' => 'Este mismo archivo ya se aplicó, pero hay filas que ya no están en la base: revisa antes de volver a cargar (nunca se borra una histórica).']);
        }

        return DB::transaction(function () use ($hash, $ruta, $lectura, $usuario, $motivo, $metadatos): ImportacionExcel {
            $importacion = $this->importador->registrar($hash, basename($ruta), $lectura, $usuario, $metadatos);
            $this->importador->aplicar($importacion, $motivo, $usuario);

            return $importacion->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function vacio(): array
    {
        return ['total' => 0, 'por_mes' => [], 'por_causal' => [], 'por_tipo' => [], 'por_antiguedad' => [], 'por_ubicacion' => []];
    }
}
