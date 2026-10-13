<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Contratos por tiempo indeterminado de ADMINISTRATIVOS (confianza y no
 * confianza): su cláusula DÉCIMA SEXTA de beneficiarios (art. 501 LFT) trae
 * las líneas como ESPACIOS SUBRAYADOS (sin «_»); el detector no las veía y
 * el registro las daba por inexistentes, así que el contrato salía con las
 * líneas vacías. Se agregan los dos espacios (beneficiario y parentesco) a
 * las versiones ya cargadas, igual que en config/documentos_maestros.php.
 * El master se vuelve a preparar con `php artisan documentos:normalizar-masters`.
 */
return new class extends Migration
{
    private const FAMILIAS = ['contrato_indeterminado.administrativo_confianza', 'contrato_indeterminado.administrativo_no_confianza'];

    private const REGLAS = [
        ['tipo' => 'entre', 'desde' => 'se deberán cubrir a', 'hasta' => ', quien guarda parentesco de', 'campo' => 'beneficiario_nombre'],
        ['tipo' => 'entre', 'desde' => 'quien guarda parentesco de', 'hasta' => 'con el trabajador', 'campo' => 'beneficiario_parentesco'],
    ];

    private const ETIQUETAS = ['beneficiario_nombre' => 'Beneficiario', 'beneficiario_parentesco' => 'Parentesco del beneficiario'];

    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('document_template_fields')) {
            return;
        }

        DB::transaction(function (): void {
            foreach (DB::table('document_templates')->whereIn('familia', self::FAMILIAS)->pluck('id') as $id) {
                $campos = DB::table('document_template_fields')->where('document_template_id', $id);

                // Versión sin espacios en BD (se siembra del registro) o ya corregida.
                if (! (clone $campos)->exists() || (clone $campos)->where('source_key', 'beneficiario_nombre')->exists()) {
                    continue;
                }

                $numero = (int) (clone $campos)->pluck('field_key')->map(fn (string $k): int => (int) preg_replace('/\D/', '', $k))->max();
                $orden = (int) (clone $campos)->max('sort_order');

                foreach (self::REGLAS as $regla) {
                    DB::table('document_template_fields')->insert([
                        'document_template_id' => $id,
                        'field_key' => sprintf('c%d', ++$numero),
                        'zona' => 'dato',
                        'estado' => 'confirmado',
                        'source_key' => $regla['campo'],
                        'label' => self::ETIQUETAS[$regla['campo']],
                        'type' => 'texto',
                        'required' => true,
                        'multiline' => false,
                        'selector' => json_encode(array_diff_key($regla, ['campo' => true]), JSON_UNESCAPED_UNICODE),
                        'detection_method' => 'legado_config',
                        'sort_order' => ++$orden,
                        'metadata' => json_encode(['legado' => json_encode($regla, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)], JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Corrección de datos: no se revierte (volvería a dejar las líneas vacías).
    }
};
