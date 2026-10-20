<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «Contrato de crédito para trabajadores» (versiones YA cargadas, igual que
 * config/documentos_maestros.php): las casillas «No. de cheque», «Cargo
 * (sin I.V.A.)», «I.V.A.» y «Monto total (con I.V.A.)» de la sección
 * I. Datos del contrato no tenían espacio y salían en blanco. Ahora las
 * llena lo que RH captura al autorizar el préstamo (nunca un cálculo).
 *
 * No bloquean la generación (required = false, blanco intencional): las
 * versiones de la app ya instaladas autorizan sin mandarlas. Idempotente:
 * una casilla que ya existe no se duplica.
 */
return new class extends Migration
{
    private const FAMILIA = 'prestamo_contrato.general';

    private const MOTIVO = 'Lo captura RH al autorizar el préstamo; en blanco si no aplica.';

    /**
     * campo => [etiqueta, caja].
     *
     * @var array<string, array{0: string, 1: array<string, int|float|string>}>
     */
    private const CASILLAS = [
        'numero_cheque_prestamo' => ['No. de cheque', ['campo' => 'numero_cheque_prestamo', 'pagina' => 2, 'x' => 90.5, 'y' => 72.6, 'ancho' => 54, 'alto' => 4.4, 'tamano' => 9]],
        'cargo_prestamo' => ['Cargo (sin I.V.A.)', ['campo' => 'cargo_prestamo', 'pagina' => 2, 'x' => 49.5, 'y' => 133, 'ancho' => 35, 'alto' => 4.4, 'tamano' => 9]],
        'iva_prestamo' => ['I.V.A.', ['campo' => 'iva_prestamo', 'pagina' => 2, 'x' => 88, 'y' => 133, 'ancho' => 20, 'alto' => 4.4, 'tamano' => 9]],
        'monto_total_prestamo' => ['Monto total (con I.V.A.)', ['campo' => 'monto_total_prestamo', 'pagina' => 2, 'x' => 111.5, 'y' => 133, 'ancho' => 35, 'alto' => 4.4, 'tamano' => 9]],
    ];

    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('document_template_fields')) {
            return;
        }

        DB::transaction(function (): void {
            foreach (DB::table('document_templates')->where('familia', self::FAMILIA)->get(['id', 'mapping']) as $master) {
                $this->agregarAlMapa((int) $master->id, $master->mapping);
                $this->agregarEspacios((int) $master->id);
            }
        });
    }

    public function down(): void
    {
        // Corrección de datos: no se revierte (volvería a dejar las casillas en blanco).
    }

    private function agregarAlMapa(int $id, mixed $json): void
    {
        $mapping = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($mapping)) {
            return;
        }

        $campos = array_values(array_filter((array) ($mapping['campos'] ?? []), 'is_array'));
        $existentes = array_map(fn (array $c): string => (string) ($c['campo'] ?? ''), $campos);

        foreach (self::CASILLAS as $campo => [, $caja]) {
            if (! in_array($campo, $existentes, true)) {
                $campos[] = $caja;
            }
        }

        // `requeridos` explícito se deja tal cual: estas casillas no bloquean.
        $mapping['campos'] = $campos;

        DB::table('document_templates')->where('id', $id)->update(['mapping' => json_encode($mapping, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)]);
    }

    private function agregarEspacios(int $id): void
    {
        $espacios = DB::table('document_template_fields')->where('document_template_id', $id);

        // Versión sin espacios en BD: se siembra del registro (ya los trae).
        if (! (clone $espacios)->exists()) {
            return;
        }

        $numero = (int) (clone $espacios)->pluck('field_key')->map(fn (string $k): int => (int) preg_replace('/\D/', '', $k))->max();
        $orden = (int) (clone $espacios)->max('sort_order');

        foreach (self::CASILLAS as $campo => [$etiqueta, $caja]) {
            if ((clone $espacios)->where('source_key', $campo)->exists()) {
                continue;
            }

            DB::table('document_template_fields')->insert([
                'document_template_id' => $id,
                'field_key' => sprintf('c%d', ++$numero),
                'zona' => 'dato',
                'estado' => 'confirmado',
                'source_key' => $campo,
                'label' => $etiqueta,
                'type' => 'texto',
                'page' => $caja['pagina'],
                'x' => $caja['x'],
                'y' => $caja['y'],
                'width' => $caja['ancho'],
                'height' => $caja['alto'],
                'font_size' => $caja['tamano'],
                'multiline' => false,
                'required' => false,
                'detection_method' => 'legado_config',
                'sort_order' => ++$orden,
                'metadata' => json_encode([
                    'legado' => json_encode($caja, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                    'motivo_blanco' => self::MOTIVO,
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
