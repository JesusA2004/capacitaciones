<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migración inicial de colaboradores y expedientes históricos
 * (docs/MIGRACION_INICIAL_EXPEDIENTES.md).
 *
 *  - colaboradores.clave_legacy: la «Clave» del Excel histórico. NO es
 *    única (la 130 está repetida en la fuente): solo referencia; el
 *    identificador operativo sigue siendo numero_empleado (EMP-XXXX).
 *  - colaboradores.importado_de: de dónde salió el registro (excel_base_general /
 *    nas_historico) para auditoría y reingresos.
 *  - contacto de emergencia: parentesco y dirección.
 *  - colaborador_datos_medicos: condición médica y alergias, cifradas y
 *    fuera de la tabla general (solo con expedientes.datos_medicos.ver).
 *  - expedientes_historicos: el PDF histórico ÚNICO de cada persona
 *    (no es un tipo de documento: no cuenta en el checklist de 14).
 *  - migraciones_expedientes: cada análisis/ejecución con su plan,
 *    decisiones manuales, totales y manifiesto (auditoría y reintento).
 *  - document_types.orden: orden del checklist de 14 documentos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table): void {
            $table->string('clave_legacy', 40)->nullable()->index();
            $table->string('importado_de', 40)->nullable();
            // Valor ORIGINAL del Excel (Alta, Reingreso, Incapacidad…): el
            // estatus del sistema se decide aparte; esto queda como fuente.
            $table->string('estatus_origen', 60)->nullable();
            $table->string('contacto_emergencia_parentesco', 80)->nullable();
            $table->string('contacto_emergencia_direccion', 255)->nullable();
        });

        Schema::create('colaborador_datos_medicos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('colaborador_id')->unique()->constrained('colaboradores')->cascadeOnDelete();
            $table->text('condicion_medica')->nullable();
            $table->text('alergias')->nullable();
            $table->string('fuente', 60)->nullable();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('migraciones_expedientes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('archivo_nombre');
            $table->string('archivo_path')->nullable();
            $table->string('archivo_hash', 64)->index();
            $table->string('modo', 20)->default('copiar');
            $table->string('estado', 30)->default('analizado');
            $table->json('totales')->nullable();
            $table->longText('plan')->nullable();
            $table->json('decisiones')->nullable();
            $table->json('resultado')->nullable();
            $table->string('manifiesto_path')->nullable();
            $table->unsignedInteger('progreso')->default(0);
            $table->unsignedInteger('progreso_total')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('iniciada_en')->nullable();
            $table->timestamp('terminada_en')->nullable();
            $table->timestamps();
        });

        Schema::create('expedientes_historicos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->string('estado', 30)->default('vinculado');
            $table->string('disk', 40);
            $table->string('path');
            $table->string('original_name');
            $table->string('stored_name');
            $table->string('mime', 100)->nullable();
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('hash', 64)->index();
            $table->string('source_disk', 40)->nullable();
            $table->string('source_path', 1000);
            $table->string('source_sucursal', 120)->nullable();
            $table->string('source_carpeta', 255)->nullable();
            $table->string('nombre_detectado', 255)->nullable();
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->foreignId('migracion_id')->nullable()->constrained('migraciones_expedientes')->nullOnDelete();
            $table->timestamp('migrated_at')->nullable();
            $table->foreignId('migrated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('vinculado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('vinculado_en')->nullable();
            $table->timestamps();

            $table->index(['colaborador_id', 'hash'], 'exp_hist_colab_hash_idx');
        });

        Schema::table('document_types', function (Blueprint $table): void {
            $table->unsignedSmallInteger('orden')->default(100);
        });

        $this->catalogoDeCatorce();
        $this->sucursalesAutorizadas();
    }

    /**
     * Bases existentes: la sucursal se llama «Tula» (Tula de Allende es la
     * ciudad) y Corporativo es una sucursal real de Mr. Lana.
     */
    private function sucursalesAutorizadas(): void
    {
        DB::table('sucursales')->where('clave', 'TUL01')->update(['nombre' => 'Tula', 'ciudad' => 'Tula de Allende']);

        $empresaId = DB::table('empresas')->where('nombre', 'Mr. Lana')->value('id');
        $corporativo = ['ciudad' => 'Cuernavaca', 'estado' => 'Morelos', 'direccion' => 'Subida del Club 114, Cuernavaca, Morelos'];

        if (Schema::hasColumn('sucursales', 'codigo_postal')) {
            $corporativo['codigo_postal'] = '62260';
        }

        if ($empresaId !== null) {
            $corporativo['empresa_id'] = $empresaId;
        }

        DB::table('sucursales')->where('clave', 'CORP01')->update($corporativo);
    }

    /**
     * Checklist del expediente = exactamente estos 14 tipos obligatorios.
     * Los demás tipos se conservan (solicitudes, bajas, préstamos y el
     * historial dependen de ellos) pero dejan de ser obligatorios: ya no se
     * muestran en el checklist (ver ExpedienteService::tiposRequeridos()).
     */
    private function catalogoDeCatorce(): void
    {
        $ahora = now();
        $catorce = [
            ['solicitud_empleo', 'Solicitud de empleo (Formato interno)', 'personales'],
            ['fotografia', '2 fotografías tamaño infantil B/N o Color', 'personales'],
            ['acta_nacimiento', 'Acta de nacimiento', 'personales'],
            ['ine', 'Identificación oficial', 'personales'],
            ['nss', 'Número de Seguridad Social', 'personales'],
            ['curp', 'CURP', 'personales'],
            ['rfc', 'Constancia de situación fiscal (RFC)', 'personales'],
            ['comprobante_domicilio', 'Comprobante de domicilio', 'personales'],
            ['comprobante_estudios', 'Comprobante de estudios', 'personales'],
            ['cartas_recomendacion', '2 cartas de recomendación', 'personales'],
            ['datos_bancarios', 'Datos bancarios (número de cuenta, tarjeta y CLABE interbancaria)', 'personales'],
            ['contrato', 'Contrato laboral', 'contratos'],
            ['carta_confidencialidad', 'Contrato / carta de confidencialidad', 'contratos'],
            ['contrato_no_competencia', 'Contrato de No Competencia', 'contratos'],
        ];

        $tieneCategoria = Schema::hasColumn('document_types', 'categoria');

        foreach ($catorce as $orden => [$clave, $nombre, $categoria]) {
            $datos = ['nombre' => $nombre, 'requerido' => true, 'activo' => true, 'orden' => $orden + 1, 'updated_at' => $ahora];

            if ($tieneCategoria) {
                $datos['categoria'] = $categoria;
            }

            if (DB::table('document_types')->where('clave', $clave)->exists()) {
                DB::table('document_types')->where('clave', $clave)->update($datos);
            } else {
                DB::table('document_types')->insert([...$datos, 'clave' => $clave, 'aplica_alta' => ! in_array($clave, ['contrato', 'carta_confidencialidad', 'contrato_no_competencia'], true), 'created_at' => $ahora]);
            }
        }

        DB::table('document_types')
            ->whereNotIn('clave', array_column($catorce, 0))
            ->update(['requerido' => false, 'updated_at' => $ahora]);
    }

    public function down(): void
    {
        Schema::table('document_types', fn (Blueprint $table) => $table->dropColumn('orden'));
        Schema::dropIfExists('expedientes_historicos');
        Schema::dropIfExists('migraciones_expedientes');
        Schema::dropIfExists('colaborador_datos_medicos');
        Schema::table('colaboradores', fn (Blueprint $table) => $table->dropColumn(['clave_legacy', 'importado_de', 'estatus_origen', 'contacto_emergencia_parentesco', 'contacto_emergencia_direccion']));
    }
};
