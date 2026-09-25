<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plantillas oficiales versionadas (docs/FORMATOS_OFICIALES.md).
 *
 * Antes: un official_format = un PDF + overlay_config editable en sitio
 * (cambiar la configuración alteraba en silencio cómo se generaban los
 * documentos nuevos y no quedaba rastro de con qué se generó uno viejo).
 *
 * Ahora: official_formats es el FORMATO (nombre, categoría, a quién
 * aplica) y official_format_versions guarda cada versión con su archivo
 * fuente, PDF base normalizado, campos mapeados, análisis y hash. Una
 * versión publicada es inmutable; cada documento generado apunta a la
 * versión exacta que lo produjo.
 *
 * Solo agrega tablas/columnas y convierte cada formato existente en su
 * versión 1 (conservando su archivo y su configuración): no borra datos.
 * Las columnas legacy (source_*, overlay_config) se conservan, ya sin uso,
 * para no destruir información.
 */
return new class extends Migration
{
    /**
     * Clave legacy de overlay_config → variable del catálogo nuevo
     * (App\Services\Formatos\Variables\CatalogoVariablesFormato).
     *
     * @var array<string, string>
     */
    private const LEGACY = [
        'nombre_completo' => 'colaborador.nombre_completo',
        'nombre_colaborador' => 'colaborador.nombre',
        'apellidos_colaborador' => 'colaborador.apellidos',
        'puesto' => 'laboral.puesto',
        'sucursal' => 'laboral.sucursal',
        'departamento' => 'laboral.departamento',
        'fecha_actual' => 'fecha.actual',
        'fecha_ingreso' => 'laboral.fecha_ingreso',
        'curp' => 'colaborador.curp',
        'rfc' => 'colaborador.rfc',
        'nss' => 'colaborador.nss',
        'domicilio' => 'colaborador.domicilio',
        'telefono' => 'colaborador.telefono',
        'correo' => 'colaborador.correo',
        'dias_vacaciones' => 'solicitud.dias',
        'fecha_inicio_permiso' => 'solicitud.fecha_inicio',
        'fecha_fin_permiso' => 'solicitud.fecha_fin',
        'motivo_permiso' => 'solicitud.motivo',
        'observaciones' => 'solicitud.observaciones',
        'finiquito_fecha_baja' => 'finiquito.fecha_baja',
        'finiquito_antiguedad' => 'finiquito.antiguedad',
        'finiquito_sueldo_diario' => 'finiquito.sueldo_diario',
        'finiquito_sueldo_mensual' => 'finiquito.sueldo_mensual',
        'finiquito_sueldo_pendiente' => 'finiquito.sueldo_pendiente',
        'finiquito_vacaciones_pendientes' => 'finiquito.vacaciones_pendientes',
        'finiquito_prima_vacacional' => 'finiquito.prima_vacacional',
        'finiquito_aguinaldo_proporcional' => 'finiquito.aguinaldo_proporcional',
        'finiquito_indemnizacion' => 'finiquito.indemnizacion',
        'finiquito_bonos_extra' => 'finiquito.bonos_extra',
        'finiquito_descuentos' => 'finiquito.descuentos',
        'finiquito_adeudos' => 'finiquito.adeudos',
        'finiquito_otros_conceptos' => 'finiquito.otros_conceptos',
        'finiquito_total_ajustado' => 'finiquito.total',
        'finiquito_fecha_generacion' => 'fecha.actual',
    ];

    public function up(): void
    {
        Schema::table('official_formats', function (Blueprint $table): void {
            $table->string('aplica_a', 20)->default('colaborador')->after('tipo');
            $table->foreignId('empresa_id')->nullable()->after('aplica_a')->constrained('empresas')->nullOnDelete();
            $table->unsignedBigInteger('version_vigente_id')->nullable()->after('empresa_id');
            $table->foreignId('created_by')->nullable()->after('overlay_config')->constrained('users')->nullOnDelete();
            $table->timestamp('archivado_en')->nullable()->after('created_by');
            $table->foreignId('archivado_por')->nullable()->after('archivado_en')->constrained('users')->nullOnDelete();
            $table->string('source_disk')->nullable()->change();
            $table->string('source_path')->nullable()->change();
            $table->string('original_filename')->nullable()->change();
        });

        Schema::create('official_format_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('official_format_id')->constrained('official_formats')->restrictOnDelete();
            $table->unsignedInteger('numero');
            $table->string('estado', 20)->default('borrador');
            $table->string('estrategia', 20)->default('overlay');
            // Archivo fuente tal cual lo subió RH (renombrado con uuid).
            $table->string('file_type', 10);
            $table->string('source_disk');
            $table->string('source_path');
            $table->string('original_filename');
            $table->string('source_mime', 120)->nullable();
            $table->unsignedBigInteger('source_size')->nullable();
            $table->string('source_hash', 64)->nullable();
            // PDF base normalizado (fondo del overlay / vista previa).
            $table->string('base_path')->nullable();
            $table->string('base_hash', 64)->nullable();
            $table->string('fidelidad', 20)->default('exacta');
            $table->json('paginas')->nullable();
            $table->json('campos')->nullable();
            $table->json('analisis')->nullable();
            $table->text('notas')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('publicada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('publicada_en')->nullable();
            $table->timestamps();

            $table->unique(['official_format_id', 'numero'], 'official_format_versions_formato_numero_unique');
        });

        Schema::table('official_formats', function (Blueprint $table): void {
            $table->foreign('version_vigente_id', 'official_formats_version_vigente_fk')
                ->references('id')->on('official_format_versions')->nullOnDelete();
        });

        Schema::table('official_format_generations', function (Blueprint $table): void {
            $table->foreignId('official_format_version_id')->nullable()->after('official_format_id')
                ->constrained('official_format_versions')->restrictOnDelete();
            $table->unsignedInteger('version_numero')->nullable()->after('official_format_version_id');
            $table->foreignId('prestamo_id')->nullable()->after('solicitud_interna_id')->constrained('prestamos')->nullOnDelete();
            $table->foreignId('contrato_laboral_id')->nullable()->after('prestamo_id')->constrained('contratos_laborales')->nullOnDelete();
            $table->json('valores_manuales')->nullable()->after('data_snapshot');
            $table->string('checksum', 64)->nullable()->after('valores_manuales');
            $table->boolean('en_expediente')->default(false)->after('checksum');
        });

        $this->migrarFormatosExistentes();
    }

    public function down(): void
    {
        Schema::table('official_format_generations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('official_format_version_id');
            $table->dropConstrainedForeignId('prestamo_id');
            $table->dropConstrainedForeignId('contrato_laboral_id');
            $table->dropColumn(['version_numero', 'valores_manuales', 'checksum', 'en_expediente']);
        });

        Schema::table('official_formats', function (Blueprint $table): void {
            $table->dropForeign('official_formats_version_vigente_fk');
        });

        Schema::dropIfExists('official_format_versions');

        Schema::table('official_formats', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('empresa_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('archivado_por');
            $table->dropColumn(['aplica_a', 'version_vigente_id', 'archivado_en']);
        });
    }

    /**
     * Cada formato existente se vuelve su versión 1 con el mismo archivo y
     * la configuración convertida al esquema nuevo de campos. Publicada si
     * ya tenía campos habilitados (ya se usaba para generar); borrador si no.
     */
    private function migrarFormatosExistentes(): void
    {
        $formatos = DB::table('official_formats')->whereNotNull('source_path')->get();

        foreach ($formatos as $formato) {
            $campos = $this->convertirConfiguracion(json_decode((string) $formato->overlay_config, true));
            $publicada = $campos !== [];
            $ahora = now();

            $versionId = DB::table('official_format_versions')->insertGetId([
                'official_format_id' => $formato->id,
                'numero' => 1,
                'estado' => $publicada ? 'publicada' : 'borrador',
                'estrategia' => 'overlay',
                'file_type' => $formato->file_type === 'docx' ? 'docx' : 'pdf',
                'source_disk' => $formato->source_disk,
                'source_path' => $formato->source_path,
                'original_filename' => $formato->original_filename ?? basename((string) $formato->source_path),
                'source_mime' => $formato->file_type === 'docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/pdf',
                'base_path' => $formato->file_type === 'docx' ? null : $formato->source_path,
                'fidelidad' => 'exacta',
                'campos' => json_encode($campos),
                'notas' => 'Versión 1 creada automáticamente a partir de la configuración anterior.',
                'publicada_en' => $publicada ? $ahora : null,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);

            if ($publicada) {
                DB::table('official_formats')->where('id', $formato->id)->update(['version_vigente_id' => $versionId]);
            }

            DB::table('official_format_generations')
                ->where('official_format_id', $formato->id)
                ->whereNull('official_format_version_id')
                ->update(['official_format_version_id' => $versionId, 'version_numero' => 1]);
        }
    }

    /**
     * @param  mixed  $config
     * @return list<array<string, mixed>>
     */
    private function convertirConfiguracion($config): array
    {
        if (! is_array($config)) {
            return [];
        }

        $campos = [];

        foreach ($config as $clave => $campo) {
            if (! is_array($campo) || ($campo['enabled'] ?? false) !== true || ! isset(self::LEGACY[$clave])) {
                continue;
            }

            $tamano = (float) ($campo['font_size'] ?? 10);

            $campos[] = [
                'id' => substr(md5((string) $clave), 0, 10),
                'tipo' => 'variable',
                'variable' => self::LEGACY[$clave],
                'etiqueta' => null,
                'texto' => null,
                'pagina' => max(1, (int) ($campo['pagina'] ?? 1)),
                'x' => (float) ($campo['x'] ?? 10),
                'y' => (float) ($campo['y'] ?? 10),
                'ancho' => isset($campo['max_width']) && $campo['max_width'] !== '' ? (float) $campo['max_width'] : 80.0,
                'alto' => round($tamano * 0.5 + 1, 2),
                'font_size' => $tamano,
                'align' => in_array($campo['align'] ?? 'left', ['left', 'center', 'right'], true) ? $campo['align'] : 'left',
                'negrita' => false,
                'color' => (string) ($campo['color'] ?? '#111111'),
                'formato' => null,
                'max_caracteres' => null,
                'multilinea' => true,
                'requerido' => false,
            ];
        }

        return $campos;
    }
};
