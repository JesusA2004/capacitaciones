<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Convenios de confidencialidad (gestor, gerente, subgerente, coordinadoras y
 * general): la cláusula DÉCIMA SÉPTIMA («las leyes del estado de …», «los
 * tribunales de la ciudad de …») y el cierre «en la ciudad de …» imprimen el
 * ESTADO y la CIUDAD de la sucursal del colaborador, nunca Cuernavaca fijo.
 *
 *  - Gestor y general traen el ejemplo «MORELOS» / «Cuernavaca, Morelos»
 *    escrito: se mapea y se cambia el cierre fijo por ciudad + fecha.
 *  - Gerente, subgerente y coordinadoras traen la jurisdicción en blanco y se
 *    dejaba vacía a propósito (`ignorar`): ahora se llena.
 *  - Indeterminados de administrativos (confianza / no confianza): su cierre
 *    «lo firman en Cuernavaca, Morelos a …» también toma la ciudad de la sucursal.
 *
 * Mismo cambio que config/documentos_maestros.php, aplicado a las versiones
 * ya cargadas. El master se vuelve a preparar con
 * `php artisan documentos:normalizar-masters`.
 */
return new class extends Migration
{
    private const ANCLA_FIRMA_FIJA = 'Cuernavaca, Morelos a los';

    private const CON_EJEMPLO = [
        ['tipo' => 'texto', 'buscar' => 'MORELOS', 'parrafo' => 'las leyes del estado de', 'campo' => 'sucursal_estado_mayusculas'],
        ['tipo' => 'texto', 'buscar' => 'Cuernavaca, Morelos', 'parrafo' => 'tribunales de la ciudad de', 'campo' => 'lugar_firma'],
        ['tipo' => 'entre', 'desde' => 'en la ciudad de', 'hasta' => '.', 'parrafo' => 'lo firmaron al calce', 'campo' => 'ciudad_y_fecha_firma_a_los_inicio_contrato'],
    ];

    private const EN_BLANCO = [
        ['tipo' => 'blanco', 'antes' => 'las leyes del estado de', 'campo' => 'sucursal_estado_mayusculas'],
        ['tipo' => 'blanco', 'antes' => 'tribunales de la ciudad de', 'campo' => 'lugar_firma'],
    ];

    private const ADMINISTRATIVOS = ['contrato_indeterminado.administrativo_confianza', 'contrato_indeterminado.administrativo_no_confianza'];

    private const FIRMA_ADMINISTRATIVOS = ['tipo' => 'texto', 'buscar' => 'Cuernavaca, Morelos', 'parrafo' => 'lo firman en', 'campo' => 'lugar_firma'];

    private const ETIQUETAS = [
        'sucursal_estado_mayusculas' => 'Estado de la sucursal (mayúsculas)',
        'lugar_firma' => 'Ciudad y estado de la sucursal',
        'ciudad_y_fecha_firma_a_los_inicio_contrato' => 'Ciudad y fecha de firma',
    ];

    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('document_template_fields')) {
            return;
        }

        DB::transaction(function (): void {
            $versiones = DB::table('document_templates')->where('familia', 'like', 'contrato_confidencialidad.%')->get(['id', 'configuracion']);

            foreach ($versiones as $version) {
                $campos = DB::table('document_template_fields')->where('document_template_id', $version->id);

                // Sin espacios en BD (se siembra del registro) o ya corregida.
                if (! (clone $campos)->exists() || (clone $campos)->where('source_key', 'sucursal_estado_mayusculas')->exists()) {
                    continue;
                }

                $fija = (clone $campos)->get()->first(fn (object $c): bool => (json_decode((string) $c->selector, true)['antes'] ?? null) === self::ANCLA_FIRMA_FIJA);
                $enBlanco = (clone $campos)->where('source_key', 'ciudad_y_fecha_firma_a_los_inicio_contrato')->exists();

                if ($fija !== null) {
                    DB::table('document_template_fields')->where('id', $fija->id)->delete();
                    $reglas = self::CON_EJEMPLO;
                } elseif ($enBlanco) {
                    $reglas = self::EN_BLANCO;
                } else {
                    // Otra estructura (p. ej. el convenio general 2024): no aplica.
                    continue;
                }

                $this->agregar($version->id, $reglas);
                $this->quitarIgnorar($version->id, $version->configuracion);
            }

            // Indeterminados de administrativos: «lo firman en Cuernavaca,
            // Morelos a …» → la ciudad de firma sale de la sucursal.
            foreach (DB::table('document_templates')->whereIn('familia', self::ADMINISTRATIVOS)->pluck('id') as $id) {
                $campos = DB::table('document_template_fields')->where('document_template_id', $id);

                if (! (clone $campos)->exists() || (clone $campos)->where('source_key', 'lugar_firma')->exists()) {
                    continue;
                }

                $this->agregar((int) $id, [self::FIRMA_ADMINISTRATIVOS]);
            }
        });
    }

    /**
     * @param  list<array<string, string>>  $reglas
     */
    private function agregar(int $id, array $reglas): void
    {
        $campos = DB::table('document_template_fields')->where('document_template_id', $id);
        $numero = (int) (clone $campos)->pluck('field_key')->map(fn (string $k): int => (int) preg_replace('/\D/', '', $k))->max();
        $orden = (int) (clone $campos)->max('sort_order');

        foreach ($reglas as $regla) {
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

    private function quitarIgnorar(int $id, ?string $configuracion): void
    {
        $config = json_decode((string) $configuracion, true);

        if (! is_array($config) || ! isset($config['ignorar']) || ! is_array($config['ignorar'])) {
            return;
        }

        $config['ignorar'] = array_values(array_filter($config['ignorar'], fn ($t): bool => ! str_contains(mb_strtolower((string) $t), 'leyes del estado de')));

        if ($config['ignorar'] === []) {
            unset($config['ignorar']);
        }

        DB::table('document_templates')->where('id', $id)->update(['configuracion' => json_encode($config, JSON_UNESCAPED_UNICODE)]);
    }

    public function down(): void
    {
        // Corrección de datos: no se revierte (volvería a imprimir Cuernavaca fijo / blancos).
    }
};
