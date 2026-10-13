<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cobertura de zonas de los documentos maestros ya cargados:
 *
 *  1. Beneficiario y parentesco (cláusula art. 501 LFT) dejan de ser
 *     opcionales: si faltan, el contrato no se genera (antes salía con la
 *     línea vacía y la vista previa no lo reportaba).
 *  2. Overlays PDF: todo dato es obligatorio salvo casillas y los blancos
 *     intencionales con su razón (horas del permiso). Antes solo unos
 *     cuantos estaban en «requeridos» y el resto podía imprimirse vacío.
 *  3. Word: los marcatextos de Jurídico se retiran del documento final
 *     (`configuracion.limpiar_marcas`); el original no se toca. Se aplica al
 *     volver a preparar la versión (php artisan documentos:normalizar-masters).
 *
 * Solo cambia CUÁNDO se bloquea por datos faltantes (mapping.instancias /
 * mapping.requeridos y los espacios en BD): el master técnico y su QA
 * visual no cambian aquí.
 */
return new class extends Migration
{
    private const BENEFICIARIO = ['beneficiario_nombre', 'beneficiario_parentesco'];

    private const BLANCOS = [
        'hora_salida' => 'Solo aplica cuando el permiso es para salir antes.',
        'hora_entrada' => 'Solo aplica cuando el permiso es para llegar tarde o regresar.',
    ];

    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('document_template_fields')) {
            return;
        }

        DB::transaction(function (): void {
            foreach (DB::table('document_templates')->whereNotNull('familia')->get(['id', 'motor', 'mapping', 'configuracion']) as $master) {
                $id = (int) data_get($master, 'id');
                $this->corregirEspacios($id, (string) data_get($master, 'motor'));
                $this->corregirMapa($id, (string) data_get($master, 'mapping'), (string) data_get($master, 'configuracion'));
            }

            // Nombres humanos en el editor (las etiquetas se guardaron al sembrar).
            DB::table('document_template_fields')->where('label', 'Beneficiario: nombre')->update(['label' => 'Beneficiario']);
            DB::table('document_template_fields')->where('label', 'Beneficiario: parentesco')->update(['label' => 'Parentesco del beneficiario']);
        });
    }

    public function down(): void
    {
        // Corrección de datos: no se revierte (volvería a permitir líneas vacías).
    }

    private function corregirEspacios(int $masterId, string $motor): void
    {
        $campos = DB::table('document_template_fields')->where('document_template_id', $masterId)->where('zona', 'dato')->where('required', false)->get();

        foreach ($campos as $campo) {
            $fuente = (string) $campo->source_key;
            $metadata = json_decode((string) $campo->metadata, true);
            $metadata = is_array($metadata) ? $metadata : [];
            $casilla = $campo->type === 'casilla' || str_starts_with($fuente, 'marca_');

            if ($casilla) {
                continue;
            }

            if ($motor === 'pdf_overlay' && isset(self::BLANCOS[$fuente])) {
                $metadata['motivo_blanco'] = self::BLANCOS[$fuente];
                DB::table('document_template_fields')->where('id', $campo->id)->update(['metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE)]);

                continue;
            }

            if ($motor !== 'pdf_overlay' && ! in_array($fuente, self::BENEFICIARIO, true)) {
                continue;
            }

            // La regla heredada guardada como texto conserva su forma sin
            // «opcional», para que el espacio siga compilándose igual.
            if (isset($metadata['legado']) && is_string($metadata['legado'])) {
                $legado = json_decode($metadata['legado'], true);

                if (is_array($legado)) {
                    unset($legado['opcional']);
                    $metadata['legado'] = json_encode($legado, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
                }
            }

            DB::table('document_template_fields')->where('id', $campo->id)->update(['required' => true, 'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE)]);
        }
    }

    private function corregirMapa(int $id, string $mappingJson, string $configuracionJson): void
    {
        $mapping = json_decode($mappingJson, true);
        $configuracion = json_decode($configuracionJson, true);
        $configuracion = is_array($configuracion) ? $configuracion : [];

        if (! is_array($mapping)) {
            return;
        }

        if (($mapping['motor'] ?? 'docx') === 'pdf_overlay') {
            $requeridos = [];

            foreach ((array) ($mapping['campos'] ?? []) as $caja) {
                $campo = is_array($caja) ? (string) ($caja['campo'] ?? '') : '';
                $casilla = is_array($caja) && (($caja['tipo'] ?? null) === 'check' || str_starts_with($campo, 'marca_'));

                if ($campo !== '' && ! $casilla && ! isset(self::BLANCOS[$campo])) {
                    $requeridos[] = $campo;
                }
            }

            $mapping['requeridos'] = array_values(array_unique($requeridos));
        } else {
            foreach ((array) ($mapping['instancias'] ?? []) as $n => $instancia) {
                if (is_array($instancia) && in_array((string) ($instancia['campo'] ?? ''), self::BENEFICIARIO, true)) {
                    $mapping['instancias'][$n]['opcional'] = false;
                }
            }

            $configuracion['limpiar_marcas'] ??= true;
        }

        DB::table('document_templates')->where('id', $id)->update([
            'mapping' => json_encode($mapping, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            'configuracion' => json_encode($configuracion, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
        ]);
    }
};
