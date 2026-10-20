<?php

namespace App\Services\Bajas;

use App\Enums\AccionBitacoraBaja;
use App\Enums\EstadoCierreLaboral;
use App\Enums\EstatusBaja;
use App\Enums\FormaSalida;
use App\Enums\OrigenBaja;
use App\Enums\TipoBaja;
use App\Models\Baja;
use App\Models\CausalRotacion;
use App\Models\CierreLaboral;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Conversión del subcatálogo anterior de causales (20-oct-2026, por la
 * mañana) al catálogo de la especificación de RH (17 claves RV/NR/RE) y
 * alta del expediente de baja de los cierres laborales que ya existían.
 * La llama la migración 2026_10_20_200000_modulo_bajas_y_causales; es
 * idempotente (correrla otra vez no cambia nada).
 *
 * NADA se pierde:
 *  - cierres_laborales / solicitudes_internas guardan en
 *    causal_rotacion_original el tipo y la causal que tenían;
 *  - las causales anteriores no se borran: la equivalente directa se
 *    actualiza en su lugar (conserva su id) y las demás quedan como
 *    `legado` (inactivas, reemplazada_por_id → la nueva);
 *  - lo que no mapea limpio (config/bajas.php causales_anteriores con
 *    limpio = false, o una causal creada por RH sin equivalencia) queda en
 *    la baja como «causal migrada de …, revisar» y en el log (warning).
 */
class MigracionCatalogoBajasService
{
    private const PATRON_CLAVE_NUEVA = '/^(RV|NR|RE)-\d{2}$/';

    /** @var list<string> */
    private const TABLAS_CON_CAUSAL = ['cierres_laborales', 'solicitudes_internas', 'bajas'];

    public function __construct(
        private readonly CatalogoBajasService $catalogo,
        private readonly BajaRegistroService $registro,
    ) {}

    /**
     * @return array{originales: int, bajas_creadas: int, actualizadas: list<string>, insertadas: list<string>, legado: list<string>, sin_mapeo: list<string>, no_limpias: list<string>}
     */
    public function aplicar(): array
    {
        $originales = $this->conservarOriginales();
        $bajasCreadas = $this->registrarCierresPrevios();
        $reporte = $this->convertirCatalogo();
        $this->catalogo->sembrar();
        $this->sincronizarFormas();

        $resultado = ['originales' => $originales, 'bajas_creadas' => $bajasCreadas, ...$reporte];

        if ($reporte['sin_mapeo'] !== [] || $reporte['no_limpias'] !== []) {
            Log::warning('Módulo de bajas: causales anteriores sin equivalencia limpia (RH debe revisar las bajas marcadas «causal migrada»).', $resultado);
        } else {
            Log::info('Módulo de bajas: catálogo de causales convertido.', $resultado);
        }

        return $resultado;
    }

    /**
     * Guarda «Tipo · Causal» que tenía cada cierre/solicitud antes de la
     * conversión (solo una vez).
     */
    private function conservarOriginales(): int
    {
        $total = 0;

        foreach ($this->causalesAnteriores() as $causal) {
            $texto = Str::limit(sprintf('%s · %s', $this->etiquetaTipoAnterior($causal->tipo), $causal->nombre), 200, '');

            foreach (['cierres_laborales', 'solicitudes_internas'] as $tabla) {
                $total += DB::table($tabla)->where('causal_rotacion_id', $causal->id)->whereNull('causal_rotacion_original')->update(['causal_rotacion_original' => $texto]);
            }
        }

        return $total;
    }

    /**
     * Expediente de baja para cada cierre laboral que ya existía (origen
     * «cierre_previo»): cerrada si la baja ya se ejecutó; en revisión de RH
     * si sigue abierto. Sin documentos obligatorios ni alertas.
     */
    private function registrarCierresPrevios(): int
    {
        $excluidos = [EstadoCierreLaboral::Rechazado->value, EstadoCierreLaboral::Cancelado->value, EstadoCierreLaboral::Solicitado->value, EstadoCierreLaboral::PendienteRh->value];
        $mapeo = (array) config('bajas.causales_anteriores', []);
        $creadas = 0;

        CierreLaboral::query()
            ->whereNotIn('estado', $excluidos)
            ->whereDoesntHave('baja')
            ->with(['colaborador', 'causalRotacion'])
            ->orderBy('id')
            ->each(function (CierreLaboral $cierre) use ($mapeo, &$creadas): void {
                $colaborador = $cierre->colaborador;
                $causal = $cierre->causalRotacion;
                $claveAnterior = $causal?->clave;
                $destino = $claveAnterior !== null ? ($mapeo[$claveAnterior] ?? null) : null;
                $esNueva = $claveAnterior !== null && preg_match(self::PATRON_CLAVE_NUEVA, $claveAnterior) === 1;
                $limpio = $causal === null || $esNueva || (is_array($destino) && (bool) ($destino['limpio'] ?? false));
                $forma = is_array($destino) && isset($destino['clave'])
                    ? FormaSalida::from(substr((string) $destino['clave'], 0, 2))
                    : FormaSalida::desdeTipoBaja(TipoBaja::desdeValor((string) $cierre->getRawOriginal('tipo_baja')) ?? TipoBaja::Rescision);
                $cerrada = in_array($cierre->estado, [EstadoCierreLaboral::BajaEjecutada, EstadoCierreLaboral::ExpedienteCerrado], true);

                DB::transaction(function () use ($cierre, $colaborador, $causal, $limpio, $forma, $cerrada, &$creadas): void {
                    $baja = $this->registro->nueva($colaborador, [
                        'cierre_laboral_id' => $cierre->id,
                        'fecha_baja' => $cierre->fecha_efectiva->toDateString(),
                        'forma_salida' => $forma,
                        'causal_rotacion_id' => $causal?->id,
                        'comentarios' => Str::limit(trim($cierre->motivo), 500, ''),
                        'motivo_original' => Str::limit(trim($cierre->causal_rotacion_original ?? $cierre->motivo), 300, ''),
                        'causal_migrada_de' => $limpio ? null : Str::limit($cierre->causal_rotacion_original ?? ($causal->nombre ?? ''), 200, ''),
                        'estatus' => $cerrada ? EstatusBaja::Cerrada : EstatusBaja::EnRevisionRh,
                        'solicitado_por' => $cierre->iniciado_por,
                        'enviado_en' => $cierre->created_at,
                        'cerrado_en' => $cerrada ? ($cierre->baja_ejecutada_en ?? $cierre->expediente_cerrado_en ?? $cierre->updated_at) : null,
                        'acceso_suspendido_en' => $cierre->acceso_suspendido_en,
                        'creado_por' => $cierre->iniciado_por,
                    ], null, OrigenBaja::CierrePrevio);
                    $baja->save();

                    $this->registro->bitacora($baja, null, AccionBitacoraBaja::Migrada, 'cierre_laboral', null, sprintf('Cierre #%d (%s)', $cierre->id, $cierre->estado->etiqueta()), $limpio ? null : 'La causal anterior no tiene equivalencia directa en el catálogo nuevo: RH debe revisarla.');
                    $creadas++;
                });
            });

        return $creadas;
    }

    /**
     * @return array{actualizadas: list<string>, insertadas: list<string>, legado: list<string>, sin_mapeo: list<string>, no_limpias: list<string>}
     */
    private function convertirCatalogo(): array
    {
        $mapeo = (array) config('bajas.causales_anteriores', []);
        $reporte = ['actualizadas' => [], 'insertadas' => [], 'legado' => [], 'sin_mapeo' => [], 'no_limpias' => []];
        $orden = 0;
        $ids = [];

        foreach ($this->catalogo->causalesConfiguradas() as $clave => $datos) {
            $orden += 10;
            $existente = CausalRotacion::query()->where('clave', $clave)->first();

            if ($existente !== null) {
                $ids[$clave] = $existente->id;

                continue;
            }

            $atributos = [...$this->catalogo->atributosCausal($clave, $datos), 'orden' => $orden, 'activo' => true, 'legado' => false];
            $primaria = $this->primariaPara($clave, $mapeo)
                ?? CausalRotacion::query()->where('legado', false)->where('tipo', $atributos['tipo'])->where('nombre', $atributos['nombre'])->first();

            if ($primaria !== null) {
                $reporte['actualizadas'][] = sprintf('%s ← %s', $clave, $primaria->clave ?? $primaria->nombre);
                $primaria->update($atributos);
                $ids[$clave] = $primaria->id;

                continue;
            }

            $ids[$clave] = CausalRotacion::query()->create($atributos)->id;
            $reporte['insertadas'][] = $clave;
        }

        foreach ($this->causalesAnteriores() as $anterior) {
            $destino = $anterior->clave !== null ? ($mapeo[$anterior->clave] ?? null) : null;
            $nuevaId = is_array($destino) ? ($ids[(string) $destino['clave']] ?? null) : null;

            if ($nuevaId === null) {
                // Sin equivalencia (p. ej. creada por RH): deja de ofrecerse y
                // sus bajas la conservan, marcadas para que RH las reclasifique.
                $anterior->update(['legado' => true, 'activo' => false]);
                DB::table('bajas')->where('causal_rotacion_id', $anterior->id)->whereNull('causal_migrada_de')->update(['causal_migrada_de' => Str::limit(sprintf('%s (sin equivalencia)', $anterior->nombre), 200, '')]);
                $reporte['sin_mapeo'][] = $anterior->clave ?? $anterior->nombre;

                continue;
            }

            if (! (bool) ($destino['limpio'] ?? false)) {
                $reporte['no_limpias'][] = sprintf('%s → %s', $anterior->clave, $destino['clave']);
                // La baja queda marcada para que RH confirme la causal.
                DB::table('bajas')->where('causal_rotacion_id', $anterior->id)->whereNull('causal_migrada_de')->update(['causal_migrada_de' => Str::limit(sprintf('%s · %s', $this->etiquetaTipoAnterior($anterior->tipo), $anterior->nombre), 200, '')]);
            }

            foreach (self::TABLAS_CON_CAUSAL as $tabla) {
                DB::table($tabla)->where('causal_rotacion_id', $anterior->id)->update(['causal_rotacion_id' => $nuevaId]);
            }

            $anterior->update(['legado' => true, 'activo' => false, 'reemplazada_por_id' => $nuevaId]);
            $reporte['legado'][] = sprintf('%s → %s', $anterior->clave, $destino['clave']);
        }

        return $reporte;
    }

    /**
     * Tipo de baja y forma de salida de cada registro = los de su causal
     * (p. ej. «Bajo desempeño» pasó de rescisión a No renovación). El tipo
     * anterior queda en causal_rotacion_original.
     */
    private function sincronizarFormas(): void
    {
        CausalRotacion::query()->delCatalogo()->whereNotNull('forma_salida')->get()->each(function (CausalRotacion $causal): void {
            $forma = $causal->forma();

            foreach (['cierres_laborales', 'solicitudes_internas'] as $tabla) {
                DB::table($tabla)->where('causal_rotacion_id', $causal->id)->where('tipo_baja', '!=', $forma->tipoBaja()->value)->update(['tipo_baja' => $forma->tipoBaja()->value]);
            }

            DB::table('bajas')->where('causal_rotacion_id', $causal->id)->where(fn ($q) => $q->whereNull('forma_salida')->orWhere('forma_salida', '!=', $forma->value))->update(['forma_salida' => $forma->value]);
        });
    }

    /**
     * @param  array<string, mixed>  $mapeo
     */
    private function primariaPara(string $clave, array $mapeo): ?CausalRotacion
    {
        foreach ($mapeo as $claveAnterior => $destino) {
            if (is_array($destino) && ($destino['clave'] ?? null) === $clave && (bool) ($destino['primaria'] ?? false)) {
                $fila = CausalRotacion::query()->where('clave', (string) $claveAnterior)->where('legado', false)->first();

                if ($fila !== null) {
                    return $fila;
                }
            }
        }

        return null;
    }

    /**
     * Causales que no son del catálogo nuevo y aún no están marcadas como legado.
     *
     * @return list<CausalRotacion>
     */
    private function causalesAnteriores(): array
    {
        return array_values(CausalRotacion::query()->where('legado', false)->get()
            ->filter(fn (CausalRotacion $c): bool => $c->clave === null || preg_match(self::PATRON_CLAVE_NUEVA, $c->clave) !== 1)
            ->all());
    }

    private function etiquetaTipoAnterior(TipoBaja $tipo): string
    {
        return match ($tipo) {
            TipoBaja::Renuncia => 'Renuncia',
            TipoBaja::Rescision => 'Rescisión',
            TipoBaja::FinContrato => 'Fin de contrato',
        };
    }
}
