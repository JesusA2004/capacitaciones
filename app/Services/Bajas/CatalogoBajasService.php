<?php

namespace App\Services\Bajas;

use App\Enums\FormaSalida;
use App\Models\CausalRotacion;
use App\Models\DocumentoRequeridoBaja;
use App\Models\FormaSalidaCatalogo;
use Illuminate\Support\Collection;

/**
 * Catálogos del módulo de bajas (docs/BAJAS_Y_CAUSALES.md): formas de
 * salida (3), causales (17) y documentos requeridos por forma + adicionales
 * por causal. Siembra idempotente desde config/bajas.php: agrega lo que
 * falte por clave y NUNCA pisa lo que RH editó o desactivó.
 */
class CatalogoBajasService
{
    /**
     * @return array{formas: int, causales: int, documentos: int}
     */
    public function sembrar(): array
    {
        $creadas = ['formas' => 0, 'causales' => 0, 'documentos' => 0];

        foreach (FormaSalida::cases() as $forma) {
            $fila = FormaSalidaCatalogo::query()->firstOrCreate(['clave' => $forma->value], [
                'nombre' => $forma->etiqueta(),
                'descripcion' => $forma->descripcion(),
                'orden' => $forma->orden(),
                'activo' => true,
            ]);
            $creadas['formas'] += $fila->wasRecentlyCreated ? 1 : 0;
        }

        $orden = 0;

        foreach ($this->causalesConfiguradas() as $clave => $datos) {
            $orden += 10;

            if (CausalRotacion::query()->where('clave', $clave)->exists()) {
                continue;
            }

            CausalRotacion::query()->create([...$this->atributosCausal($clave, $datos), 'orden' => $orden, 'activo' => true, 'legado' => false]);
            $creadas['causales']++;
        }

        $orden = 0;

        foreach ((array) config('bajas.documentos_requeridos', []) as $clave => $datos) {
            $orden += 10;

            if (! is_array($datos) || DocumentoRequeridoBaja::query()->where('clave', (string) $clave)->exists()) {
                continue;
            }

            $causalId = isset($datos['causal']) ? CausalRotacion::query()->where('clave', (string) $datos['causal'])->value('id') : null;

            if (isset($datos['causal']) && $causalId === null) {
                continue;
            }

            DocumentoRequeridoBaja::query()->create([
                'clave' => (string) $clave,
                'forma_salida' => isset($datos['forma']) ? FormaSalida::from((string) $datos['forma']) : null,
                'causal_rotacion_id' => $causalId,
                'nombre' => (string) $datos['nombre'],
                'obligatorio_para_cerrar' => (bool) ($datos['obligatorio'] ?? true),
                'documento_sistema' => isset($datos['sistema']) ? (string) $datos['sistema'] : null,
                'orden' => $orden,
                'activo' => true,
            ]);
            $creadas['documentos']++;
        }

        return $creadas;
    }

    /**
     * Causales de config/bajas.php (clave => datos), en su orden.
     *
     * @return array<string, array<string, mixed>>
     */
    public function causalesConfiguradas(): array
    {
        $resultado = [];

        foreach ((array) config('bajas.causales', []) as $clave => $datos) {
            if (is_array($datos)) {
                $resultado[(string) $clave] = $datos;
            }
        }

        return $resultado;
    }

    /**
     * Atributos de una causal del catálogo inicial (sin orden ni estado).
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public function atributosCausal(string $clave, array $datos): array
    {
        $forma = FormaSalida::from((string) $datos['forma']);

        return [
            'clave' => $clave,
            'tipo' => $forma->tipoBaja(),
            'forma_salida' => $forma,
            'nombre' => (string) $datos['nombre'],
            'descripcion' => isset($datos['ayuda']) ? (string) $datos['ayuda'] : null,
            'requiere_juridico' => (bool) ($datos['requiere_juridico'] ?? false),
            'comentario_obligatorio' => (bool) ($datos['comentario_obligatorio'] ?? false),
            'alerta' => isset($datos['alerta']) ? (string) $datos['alerta'] : null,
            'excluye_indemnizacion' => (bool) ($datos['excluye_indemnizacion'] ?? false),
            'implica_indemnizacion' => (bool) ($datos['implica_indemnizacion'] ?? false),
        ];
    }

    /**
     * Opciones de la pantalla de solicitud: las formas permitidas con sus
     * causales ACTIVAS en orden (la ayuda se muestra debajo del combo).
     *
     * @param  list<FormaSalida>|null  $formas  null = las tres.
     * @return list<array{value: string, etiqueta: string, resumen: string, descripcion: string, tipo_baja: string, token: string, causales: list<array<string, mixed>>}>
     */
    public function opciones(?array $formas = null): array
    {
        $causales = CausalRotacion::query()->activas()->with('grupo:id,nombre')->ordenadas()->get()
            ->groupBy(fn (CausalRotacion $c): string => $c->forma()->value);

        $resultado = [];

        foreach ($formas ?? FormaSalida::cases() as $forma) {
            $deLaForma = $causales->get($forma->value) ?? collect();

            $resultado[] = [
                'value' => $forma->value,
                'etiqueta' => $forma->etiqueta(),
                'resumen' => $forma->resumen(),
                'descripcion' => $forma->descripcion(),
                'tipo_baja' => $forma->tipoBaja()->value,
                'token' => $forma->token(),
                'causales' => array_values($deLaForma->map(fn (CausalRotacion $c): array => $this->causalArray($c))->all()),
            ];
        }

        return $resultado;
    }

    /**
     * `requiere_juridico` se conserva como dato (compatibilidad), pero su
     * lectura de negocio es "integrar expediente probatorio" (aviso para que RH
     * resguarde evidencia; ya NO es autorización — compuerta retirada en Etapa
     * 1). `implica_indemnizacion`: el finiquito se calcula como liquidación
     * (regla configurable + advertencia, sujeta a validación jurídica).
     *
     * @return array{id: int, clave: string|null, nombre: string, grupo: string|null, ayuda: string|null, descripcion: string|null, requiere_juridico: bool, integrar_expediente_probatorio: bool, comentario_obligatorio: bool, alerta: string|null, excluye_indemnizacion: bool, implica_indemnizacion: bool}
     */
    public function causalArray(CausalRotacion $causal): array
    {
        return [
            'id' => $causal->id,
            'clave' => $causal->clave,
            'nombre' => $causal->nombre,
            'grupo' => $causal->grupo?->nombre,
            'ayuda' => $causal->descripcion,
            // Compatibilidad (diálogo anterior y API móvil): misma ayuda.
            'descripcion' => $causal->descripcion,
            'requiere_juridico' => $causal->requiere_juridico,
            // Alias semántico de Etapa 1 (misma marca, nueva lectura).
            'integrar_expediente_probatorio' => $causal->requiere_juridico,
            'comentario_obligatorio' => $causal->comentario_obligatorio,
            'alerta' => $causal->alerta,
            'excluye_indemnizacion' => $causal->excluye_indemnizacion,
            'implica_indemnizacion' => $causal->implica_indemnizacion,
        ];
    }

    /**
     * Documentos requeridos = los de la forma + los adicionales de la causal
     * (RN-05), activos y en orden.
     *
     * @return Collection<int, DocumentoRequeridoBaja>
     */
    public function documentosRequeridos(?FormaSalida $forma, ?CausalRotacion $causal): Collection
    {
        if ($forma === null) {
            return collect();
        }

        return DocumentoRequeridoBaja::query()
            ->where('activo', true)
            ->where(function ($q) use ($forma, $causal): void {
                $q->where('forma_salida', $forma->value);

                if ($causal !== null) {
                    $q->orWhere('causal_rotacion_id', $causal->id);
                }
            })
            ->orderByRaw('case when causal_rotacion_id is null then 0 else 1 end')
            ->orderBy('orden')
            ->get();
    }
}
