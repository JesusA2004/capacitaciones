<?php

namespace App\Services\Headcount;

use App\Enums\EstadoUsuario;
use App\Enums\MotivoDiferenciaPlantilla;
use App\Models\Baja;
use App\Models\CoberturaPuesto;
use App\Models\Colaborador;
use App\Models\Puesto;
use App\Models\Sucursal;
use Illuminate\Support\Collection;

/**
 * Cuadre de plantilla: las filas por sucursal (HeadcountService, fuente
 * única), sus totales, la verificación de las invariantes y la lista NOMINAL
 * de quién explica cada diferencia. Nunca se esconde a nadie para cuadrar
 * una cifra (docs/HEADCOUNT_Y_VACANTES.md → «Cuadre de plantilla»).
 *
 * Invariantes (por fila, por segmento y en el total):
 *  - autorizadas = ocupadas + vacantes;
 *  - vigentes + segundas_plazas = ocupadas + excedentes + fuera_de_plantilla + sin_ubicacion;
 *  - vigentes = activos + en_incorporacion;
 *  - Σ filas = total (Corporativo + sucursales + «Sin sucursal asignada»).
 *
 * @phpstan-import-type FilaPoblacion from HeadcountService
 * @phpstan-import-type SegmentoPoblacion from HeadcountService
 *
 * @phpstan-type PersonaDiferencia array{id: int, nombre: string, numero_empleado: string|null, estatus: string, puesto: string|null, sucursal_registrada: string|null}
 * @phpstan-type Diferencia array{motivo: string, motivo_etiqueta: string, descuadra: bool, sucursal_id: int|null, sucursal: string, puesto: string|null, cantidad: int, detalle: string, personas: list<PersonaDiferencia>}
 */
class CuadrePlantillaService
{
    public function __construct(
        private readonly HeadcountService $headcount,
        private readonly PuestosPlantillaService $puestos,
    ) {}

    /**
     * Todo el cuadre de un alcance: filas, totales (= suma de filas),
     * problemas de las invariantes (debe venir vacío) y diferencias nominales.
     *
     * @param  Collection<int, int>|null  $sucursalesIds  null = toda la empresa
     * @return array{filas: list<FilaPoblacion>, total: SegmentoPoblacion, corporativo: SegmentoPoblacion, sucursales: SegmentoPoblacion, problemas: list<string>, diferencias: list<Diferencia>}
     */
    public function cuadre(?Collection $sucursalesIds = null): array
    {
        $filas = $this->headcount->poblacionPorSucursal($sucursalesIds);
        $segmentos = $this->headcount->sumarFilas($filas);

        return [
            'filas' => $filas,
            ...$segmentos,
            'problemas' => $this->problemas($filas, $segmentos['total']),
            'diferencias' => $this->diferencias($sucursalesIds),
        ];
    }

    /**
     * Invariantes rotas, en texto (lista vacía = todo cuadra).
     *
     * @param  list<FilaPoblacion>  $filas
     * @param  SegmentoPoblacion  $total
     * @return list<string>
     */
    public function problemas(array $filas, array $total): array
    {
        $problemas = [];
        $revisar = [...array_map(fn (array $f): array => [$f['sucursal'], $f], $filas), ['Total', $total]];

        foreach ($revisar as [$nombre, $f]) {
            if ($f['autorizadas'] !== $f['ocupadas'] + $f['vacantes']) {
                $problemas[] = sprintf('%s: %d autorizadas ≠ %d ocupadas + %d vacantes.', $nombre, $f['autorizadas'], $f['ocupadas'], $f['vacantes']);
            }

            $personas = $f['vigentes'] + $f['segundas_plazas'];
            $destino = $f['ocupadas'] + $f['excedentes'] + $f['fuera_de_plantilla'] + $f['sin_ubicacion'];

            if ($personas !== $destino) {
                $problemas[] = sprintf('%s: %d vigentes + %d segundas plazas ≠ %d ocupadas + %d excedentes + %d fuera de plantilla + %d sin ubicación.', $nombre, $f['vigentes'], $f['segundas_plazas'], $f['ocupadas'], $f['excedentes'], $f['fuera_de_plantilla'], $f['sin_ubicacion']);
            }

            if ($f['vigentes'] !== $f['activos'] + $f['en_incorporacion']) {
                $problemas[] = sprintf('%s: %d vigentes ≠ %d activos + %d en incorporación.', $nombre, $f['vigentes'], $f['activos'], $f['en_incorporacion']);
            }
        }

        foreach (['autorizadas', 'ocupadas', 'vacantes', 'activos', 'en_incorporacion', 'vigentes', 'excedentes', 'fuera_de_plantilla', 'sin_ubicacion', 'segundas_plazas', 'bajas_en_tramite', 'bajas_programadas'] as $campo) {
            $suma = array_sum(array_column($filas, $campo));

            if ($suma !== $total[$campo]) {
                $problemas[] = sprintf('La suma de filas de «%s» (%d) no es el total (%d).', $campo, $suma, $total[$campo]);
            }
        }

        return $problemas;
    }

    /**
     * Quién explica cada diferencia, con nombre, número de empleado, estatus
     * y motivo. Orden: lo que descuadra personas contra plazas primero.
     *
     * @param  Collection<int, int>|null  $sucursalesIds
     * @return list<Diferencia>
     */
    public function diferencias(?Collection $sucursalesIds = null): array
    {
        $enAlcance = fn (int $sucursalId): bool => $sucursalesIds === null || ($sucursalId !== HeadcountService::SIN_SUCURSAL && $sucursalesIds->contains($sucursalId));
        $nombres = Sucursal::query()->pluck('nombre', 'id');
        $nombreSucursal = fn (int $id): string => $id === HeadcountService::SIN_SUCURSAL ? HeadcountService::ETIQUETA_SIN_SUCURSAL : (string) ($nombres[$id] ?? sprintf('Sucursal #%d', $id));

        $ocupantes = $this->headcount->consultaOcupantes()
            ->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre'])
            ->orderBy('name')->orderBy('apellidos')
            ->get(['id', 'name', 'apellidos', 'numero_empleado', 'estatus', 'puesto_id', 'sucursal_principal_id'])
            ->map(function (Colaborador $c): array {
                $sucursalId = $this->headcount->sucursalDeConteo($c->puesto_id, $c->sucursal_principal_id);
                $clave = $c->puesto_id !== null && $c->sucursal_principal_id !== null ? sprintf('%d:%d', $sucursalId, $this->puestos->canonico($c->puesto_id)) : null;

                return ['colaborador' => $c, 'sucursal_id' => $sucursalId, 'clave' => $clave];
            })
            ->filter(fn (array $o): bool => $enAlcance($o['sucursal_id']))
            ->values();

        $diferencias = [];
        $autorizadas = $this->headcount->plantillaAutorizadaPorSucursalPuesto($sucursalesIds);
        $nombresPuesto = $ocupantes->mapWithKeys(fn (array $o): array => $o['colaborador']->puesto !== null ? [(int) $o['colaborador']->puesto_id => (string) $o['colaborador']->puesto->nombre] : []);

        // Segundas plazas («Ocupa ambos puestos») de personas que ocupan plaza.
        $coberturas = CoberturaPuesto::query()
            ->where('activa', true)
            ->where('ocupa_plaza', true)
            ->whereIn('colaborador_id', $this->headcount->consultaOcupantes()->select('id'))
            ->with(['colaborador:id,name,apellidos,numero_empleado,estatus,puesto_id,sucursal_principal_id', 'colaborador.puesto:id,nombre', 'colaborador.sucursalPrincipal:id,nombre', 'puesto:id,nombre'])
            ->get();
        $coberturasPorClave = [];

        foreach ($coberturas as $cobertura) {
            $puestoId = $this->puestos->canonico($cobertura->puesto_id);
            $sucursalId = $this->puestos->sucursalDeCobertura($puestoId, $cobertura->sucursal_id);

            if ($sucursalId === null || ! $enAlcance($sucursalId) || $cobertura->colaborador === null) {
                continue;
            }

            $coberturasPorClave[sprintf('%d:%d', $sucursalId, $puestoId)][] = $cobertura->colaborador;
            $diferencias[] = $this->diferencia(MotivoDiferenciaPlantilla::SegundaPlaza, $sucursalId, $nombreSucursal($sucursalId), $cobertura->puesto?->nombre, 1,
                sprintf('También ocupa la plaza de %s en %s (cobertura «Ocupa ambos puestos»): una persona, dos plazas ocupadas.', $cobertura->puesto->nombre ?? 'otro puesto', $nombreSucursal($sucursalId)),
                [$cobertura->colaborador]);
        }

        // Excedentes y fuera de plantilla: por par, con sus personas.
        foreach ($this->headcount->plantillaActualPorSucursalPuesto($sucursalesIds) as $clave => $ocupan) {
            $plazas = (int) ($autorizadas[$clave] ?? 0);

            if ($ocupan <= $plazas) {
                continue;
            }

            [$sucursalId, $puestoId] = array_map('intval', explode(':', $clave));
            $deLaClave = fn (array $claves): Collection => $ocupantes
                ->filter(fn (array $o): bool => in_array($o['clave'], $claves, true))
                ->map(fn (array $o): Colaborador => $o['colaborador']);
            $propias = $deLaClave([$clave])->merge($coberturasPorClave[$clave] ?? []);

            // Plazas compartidas (Gestor volante ↔ Gestor): si el reparto movió
            // gente a este puesto, se listan también las del otro puesto.
            if ($propias->count() < $ocupan) {
                $otras = array_values(array_diff(array_map(fn (int $p): string => sprintf('%d:%d', $sucursalId, $p), $this->puestos->compartidos($puestoId)), [$clave]));
                $propias = $propias->merge($deLaClave($otras));
            }

            $personas = $propias->values()->all();
            $puesto = $nombresPuesto[$puestoId] ?? $this->nombrePuesto($puestoId);
            $sobran = $ocupan - $plazas;

            $diferencias[] = $plazas > 0
                ? $this->diferencia(MotivoDiferenciaPlantilla::Excedente, $sucursalId, $nombreSucursal($sucursalId), $puesto, $sobran,
                    sprintf('%s en %s: %d personas para %d plazas autorizadas (sobra%s %d). Se listan todas las personas del puesto: RH decide quién se reubica o si se autorizan más plazas.', $puesto ?? 'Puesto', $nombreSucursal($sucursalId), $ocupan, $plazas, $sobran === 1 ? '' : 'n', $sobran),
                    $personas)
                : $this->diferencia(MotivoDiferenciaPlantilla::FueraDePlantilla, $sucursalId, $nombreSucursal($sucursalId), $puesto, $sobran,
                    sprintf('%s no tiene plazas autorizadas en %s: %d persona%s sin plaza. Reubícala o autoriza la plaza.', $puesto ?? 'El puesto', $nombreSucursal($sucursalId), $sobran, $sobran === 1 ? '' : 's'),
                    $personas);
        }

        // Sin sucursal o sin puesto.
        foreach ($ocupantes->filter(fn (array $o): bool => $o['clave'] === null) as $o) {
            $c = $o['colaborador'];
            $diferencias[] = $this->diferencia(MotivoDiferenciaPlantilla::SinUbicacion, $o['sucursal_id'] === HeadcountService::SIN_SUCURSAL ? null : $o['sucursal_id'], $nombreSucursal($o['sucursal_id']), $c->puesto?->nombre, 1,
                $c->sucursal_principal_id === null ? 'No tiene sucursal asignada: no puede ocupar una plaza.' : 'No tiene puesto asignado: no puede ocupar una plaza.',
                [$c]);
        }

        // En incorporación: SÍ ocupan plaza (explican Activos ≠ Ocupadas).
        foreach ($ocupantes->filter(fn (array $o): bool => $o['colaborador']->estatus === EstadoUsuario::EnIncorporacion) as $o) {
            $c = $o['colaborador'];
            $diferencias[] = $this->diferencia(MotivoDiferenciaPlantilla::EnIncorporacion, $o['sucursal_id'] === HeadcountService::SIN_SUCURSAL ? null : $o['sucursal_id'], $nombreSucursal($o['sucursal_id']), $c->puesto?->nombre, 1,
                'Contratado, aún sin activar (inducción/evaluación/capacitación): ya trabaja y ocupa su plaza.',
                [$c]);
        }

        // Bajas registradas sin cerrar.
        foreach ([[true, MotivoDiferenciaPlantilla::BajaEnTramite], [false, MotivoDiferenciaPlantilla::BajaProgramada]] as [$yaEfectivas, $motivo]) {
            $bajas = $this->headcount->bajasAplicadas($yaEfectivas)
                ->whereIn('colaborador_id', Colaborador::query()->whereIn('estatus', EstadoUsuario::valoresVigentes())->select('id'))
                ->with(['colaborador:id,name,apellidos,numero_empleado,estatus,puesto_id,sucursal_principal_id', 'colaborador.puesto:id,nombre', 'colaborador.sucursalPrincipal:id,nombre'])
                ->orderBy('fecha_baja')
                ->get(['id', 'folio', 'colaborador_id', 'fecha_baja']);

            foreach ($bajas as $baja) {
                $c = $baja->colaborador;

                if ($c === null) {
                    continue;
                }

                $sucursalId = $this->headcount->sucursalDeConteo($c->puesto_id, $c->sucursal_principal_id);

                if (! $enAlcance($sucursalId)) {
                    continue;
                }

                $diferencias[] = $this->diferencia($motivo, $sucursalId === HeadcountService::SIN_SUCURSAL ? null : $sucursalId, $nombreSucursal($sucursalId), $c->puesto?->nombre, 1, $this->detalleBaja($baja, $yaEfectivas), [$c]);
            }
        }

        $orden = array_flip(array_map(fn (MotivoDiferenciaPlantilla $m): string => $m->value, MotivoDiferenciaPlantilla::cases()));
        usort($diferencias, fn (array $a, array $b): int => [! $a['descuadra'], $orden[$a['motivo']] ?? 99, $a['sucursal']] <=> [! $b['descuadra'], $orden[$b['motivo']] ?? 99, $b['sucursal']]);

        return $diferencias;
    }

    private function detalleBaja(Baja $baja, bool $yaEfectiva): string
    {
        $fecha = $baja->fecha_baja?->format('d/m/Y') ?? 's/f';

        return $yaEfectiva
            ? sprintf('Baja %s registrada, último día %s, aún sin cerrar (finiquito o documentos pendientes). Ya NO cuenta en la plantilla: dejó libre su plaza.', $baja->folio ?? 'sin folio', $fecha)
            : sprintf('Baja %s registrada con último día %s: sigue laborando y ocupa su plaza hasta ese día.', $baja->folio ?? 'sin folio', $fecha);
    }

    private function nombrePuesto(int $puestoId): ?string
    {
        $nombre = Puesto::withTrashed()->where('id', $puestoId)->value('nombre');

        return is_string($nombre) ? $nombre : null;
    }

    /**
     * @param  array<int, Colaborador>  $personas
     * @return Diferencia
     */
    private function diferencia(MotivoDiferenciaPlantilla $motivo, ?int $sucursalId, string $sucursal, ?string $puesto, int $cantidad, string $detalle, array $personas): array
    {
        return [
            'motivo' => $motivo->value,
            'motivo_etiqueta' => $motivo->etiqueta(),
            'descuadra' => $motivo->descuadraPersonasContraPlazas(),
            'sucursal_id' => $sucursalId,
            'sucursal' => $sucursal,
            'puesto' => $puesto,
            'cantidad' => $cantidad,
            'detalle' => $detalle,
            'personas' => array_map(fn (Colaborador $c): array => [
                'id' => $c->id,
                'nombre' => $c->nombreCompleto(),
                'numero_empleado' => $c->numero_empleado,
                'estatus' => $c->estatus->etiqueta(),
                'puesto' => $c->puesto?->nombre,
                'sucursal_registrada' => $c->sucursalPrincipal?->nombre,
            ], array_values($personas)),
        ];
    }
}
