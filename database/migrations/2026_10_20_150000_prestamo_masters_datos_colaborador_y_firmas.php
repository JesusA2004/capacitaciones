<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Documentos del préstamo («Contrato de crédito para colaboradores.pdf»),
 * versiones YA cargadas — igual que config/documentos_maestros.php:
 *  - Celda «Calle»: `domicilio_calle_mayusculas` (el domicilio sin la
 *    colonia/municipio/estado/C.P. que ya van en su propia celda).
 *  - «Nombre y firma del representante legal»: el formato deja la línea en
 *    blanco y se firma a mano → la zona deja de ser dato (nunca se imprime
 *    ni bloquea la generación).
 *  - Apellido materno: blanco intencional (no todos tienen dos apellidos).
 *  - Nombre del deudor en las firmas: caja del ancho del recuadro (mismo
 *    centro) para que un nombre largo quepa completo.
 *  - Carta de retención: el puesto empieza después de «puesto de» impreso.
 */
return new class extends Migration
{
    /**
     * familia => list of [campo, página, y, cambios en la caja (x/ancho) o null, nuevo campo o null].
     *
     * @var array<string, list<array{0: string, 1: int, 2: float, 3: array{x: float, ancho: float}|null, 4: string|null}>>
     */
    private const CAJAS = [
        'prestamo_contrato.general' => [
            ['domicilio_mayusculas', 2, 119.2, null, 'domicilio_calle_mayusculas'],
            ['nombre_completo_mayusculas', 3, 217.6, ['x' => 17.0, 'ancho' => 88.0], null],
            ['nombre_completo_mayusculas', 3, 252.3, ['x' => 17.0, 'ancho' => 88.0], null],
        ],
        'prestamo_pagare.general' => [
            ['domicilio_mayusculas', 4, 199.8, null, 'domicilio_calle_mayusculas'],
            ['nombre_completo_mayusculas', 4, 246.0, ['x' => 45.0, 'ancho' => 126.0], null],
        ],
        'prestamo_consentimiento_retencion.general' => [
            ['puesto_mayusculas', 1, 103.0, ['x' => 131.0, 'ancho' => 52.0], null],
        ],
    ];

    private const BLANCO_MATERNO = 'Solo aplica si el colaborador tiene segundo apellido.';

    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('document_template_fields')) {
            return;
        }

        DB::transaction(function (): void {
            foreach (DB::table('document_templates')->whereIn('familia', array_keys(self::CAJAS))->get(['id', 'familia', 'mapping', 'configuracion']) as $master) {
                $this->corregirMapa((int) $master->id, (string) $master->familia, $master->mapping);
                $this->corregirCampos((int) $master->id, (string) $master->familia);

                $configuracion = is_string($master->configuracion) ? json_decode($master->configuracion, true) : null;

                if ($master->familia === 'prestamo_contrato.general' && is_array($configuracion) && ($configuracion['representante'] ?? null) === 'empresa') {
                    DB::table('document_templates')->where('id', $master->id)->update(['configuracion' => json_encode([...$configuracion, 'representante' => 'no_aplica'], JSON_UNESCAPED_UNICODE)]);
                }
            }
        });
    }

    public function down(): void
    {
        // Corrección de datos: no se revierte (volvería a pedir el representante y a cortar la calle).
    }

    private function corregirMapa(int $id, string $familia, mixed $json): void
    {
        $mapping = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($mapping)) {
            return;
        }

        $campos = [];

        foreach ((array) ($mapping['campos'] ?? []) as $caja) {
            if (! is_array($caja)) {
                continue;
            }

            // La firma del representante legal se escribe a mano.
            if (($caja['campo'] ?? null) === 'representante_legal_mayusculas') {
                continue;
            }

            foreach (self::CAJAS[$familia] as [$campo, $pagina, $y, $geometria, $nuevo]) {
                if (($caja['campo'] ?? null) === $campo && (int) ($caja['pagina'] ?? 0) === $pagina && abs((float) ($caja['y'] ?? 0) - $y) < 0.05) {
                    $caja = [...$caja, ...($geometria ?? []), 'campo' => $nuevo ?? $campo];
                }
            }

            $campos[] = $caja;
        }

        $mapping['campos'] = $campos;

        if (isset($mapping['requeridos']) && is_array($mapping['requeridos'])) {
            $usados = array_map(fn (array $c): string => (string) $c['campo'], $campos);
            $mapping['requeridos'] = array_values(array_filter(
                array_unique(array_map(fn (mixed $r): string => (string) $r === 'domicilio_mayusculas' && ! in_array('domicilio_mayusculas', $usados, true) ? 'domicilio_calle_mayusculas' : (string) $r, $mapping['requeridos'])),
                fn (string $r): bool => ! in_array($r, ['representante_legal_mayusculas', 'apellido_materno_mayusculas'], true),
            ));
        }

        DB::table('document_templates')->where('id', $id)->update(['mapping' => json_encode($mapping, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)]);
    }

    private function corregirCampos(int $id, string $familia): void
    {
        $campos = DB::table('document_template_fields')->where('document_template_id', $id);

        (clone $campos)->where('source_key', 'representante_legal_mayusculas')->update(['zona' => 'firma', 'required' => false, 'updated_at' => now()]);

        foreach ((clone $campos)->where('source_key', 'apellido_materno_mayusculas')->get(['id', 'metadata']) as $fila) {
            $metadata = is_string($fila->metadata) ? (array) json_decode($fila->metadata, true) : [];
            DB::table('document_template_fields')->where('id', $fila->id)->update([
                'required' => false,
                'metadata' => json_encode([...$metadata, 'motivo_blanco' => self::BLANCO_MATERNO], JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }

        foreach (self::CAJAS[$familia] as [$campo, $pagina, $y, $geometria, $nuevo]) {
            $filas = (clone $campos)->where('source_key', $campo)->where('page', $pagina)->whereBetween('y', [$y - 0.05, $y + 0.05])->get(['id', 'metadata']);

            foreach ($filas as $fila) {
                $metadata = is_string($fila->metadata) ? (array) json_decode($fila->metadata, true) : [];
                $legado = is_string($metadata['legado'] ?? null) ? json_decode($metadata['legado'], true) : null;

                if (is_array($legado)) {
                    $legado = [...$legado, ...($geometria ?? []), 'campo' => $nuevo ?? $campo];
                    $metadata['legado'] = json_encode($legado, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
                }

                DB::table('document_template_fields')->where('id', $fila->id)->update([
                    ...$this->columnas($geometria, $nuevo),
                    'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Columnas del espacio que cambian (dato y/o geometría).
     *
     * @param  array{x: float, ancho: float}|null  $geometria
     * @return array<string, float|string>
     */
    private function columnas(?array $geometria, ?string $nuevo): array
    {
        $columnas = [];

        if ($nuevo !== null) {
            $columnas['source_key'] = $nuevo;
            $columnas['label'] = 'Calle y número (sin colonia/municipio/estado/C.P. ya capturados) · MAYÚSCULAS';
        }

        if ($geometria !== null) {
            $columnas['x'] = $geometria['x'];
            $columnas['width'] = $geometria['ancho'];
        }

        return $columnas;
    }
};
