<?php

use App\Services\Bajas\MigracionCatalogoBajasService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo de Bajas y Causales (especificación de RH v1.0, 20-oct-2026,
 * docs/BAJAS_Y_CAUSALES.md):
 *
 *  1. causales_rotacion evoluciona a «cat_causal_baja»: forma de salida
 *     (RV/NR/RE), requiere Jurídico, comentario obligatorio, alerta y
 *     marca de legado (las causales anteriores que no son de la
 *     especificación dejan de ofrecerse, nunca se borran).
 *  2. Catálogos nuevos: formas_salida y documentos_requeridos_baja.
 *  3. Expediente de la baja: bajas, baja_documentos, baja_bitacora.
 *  4. plantilla_mensual (foto día 1 / último día por sucursal).
 *  5. Datos (MigracionCatalogoBajasService, idempotente): convierte las
 *     causales y las bajas existentes a las 17 claves nuevas conservando el
 *     valor original (causal_rotacion_original) y crea el expediente de
 *     baja de cada cierre laboral que ya existía (origen «cierre_previo»).
 *     Lo que no mapea limpio queda marcado y en el log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('causales_rotacion', function (Blueprint $table): void {
            if (! Schema::hasColumn('causales_rotacion', 'forma_salida')) {
                $table->char('forma_salida', 2)->nullable()->after('tipo');
                $table->boolean('requiere_juridico')->default(false)->after('descripcion');
                $table->boolean('comentario_obligatorio')->default(false)->after('requiere_juridico');
                $table->string('alerta', 200)->nullable()->after('comentario_obligatorio');
                $table->boolean('legado')->default(false)->after('activo');
                $table->unsignedBigInteger('reemplazada_por_id')->nullable()->after('legado');
            }

            // Marca «Implica indemnización» (propuesta Etapa 1). Se agrega aquí
            // porque la siembra inline de causales (MigracionCatalogoBajasService
            // ::aplicar() más abajo) ya usa la columna en instalaciones nuevas.
            // Las instalaciones EXISTENTES la reciben por la migración
            // 2026_10_30_130000 (ambas guardadas con hasColumn para no chocar).
            if (! Schema::hasColumn('causales_rotacion', 'implica_indemnizacion')) {
                $table->boolean('implica_indemnizacion')->default(false)->after('excluye_indemnizacion');
            }
        });

        foreach (['cierres_laborales', 'solicitudes_internas'] as $tabla) {
            if (! Schema::hasColumn($tabla, 'causal_rotacion_original')) {
                Schema::table($tabla, function (Blueprint $table): void {
                    $table->string('causal_rotacion_original', 200)->nullable()->after('tipo_baja_original');
                });
            }
        }

        if (! Schema::hasTable('formas_salida')) {
            Schema::create('formas_salida', function (Blueprint $table): void {
                $table->id();
                $table->char('clave', 2)->unique();
                $table->string('nombre', 80);
                $table->string('descripcion', 200);
                $table->unsignedInteger('orden')->default(0);
                $table->boolean('activo')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('documentos_requeridos_baja')) {
            Schema::create('documentos_requeridos_baja', function (Blueprint $table): void {
                $table->id();
                $table->string('clave', 60)->unique();
                $table->char('forma_salida', 2)->nullable();
                $table->foreignId('causal_rotacion_id')->nullable()->constrained('causales_rotacion', 'id', 'docs_req_baja_causal_fk');
                $table->string('nombre', 150);
                $table->boolean('obligatorio_para_cerrar')->default(true);
                $table->string('documento_sistema', 40)->nullable();
                $table->unsignedInteger('orden')->default(0);
                $table->boolean('activo')->default(true);
                $table->timestamps();

                $table->index(['forma_salida', 'activo'], 'docs_req_baja_forma_activo_idx');
            });
        }

        if (! Schema::hasTable('bajas')) {
            Schema::create('bajas', function (Blueprint $table): void {
                $table->id();
                $table->string('folio', 20)->unique();
                $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores');
                $table->foreignId('cierre_laboral_id')->nullable()->unique()->constrained('cierres_laborales');
                // Copias al momento de la baja (sucursal y puesto se COPIAN).
                $table->string('nombre_colaborador', 200);
                $table->string('numero_empleado', 50)->nullable();
                $table->foreignId('sucursal_id')->nullable()->constrained('sucursales');
                $table->foreignId('puesto_id')->nullable()->constrained('puestos');
                $table->foreignId('departamento_id')->nullable()->constrained('departamentos');
                $table->date('fecha_ingreso')->nullable();
                $table->date('fecha_baja')->nullable()->comment('Último día laborado');
                $table->integer('antiguedad_dias')->nullable();
                $table->char('forma_salida', 2)->nullable();
                $table->foreignId('causal_rotacion_id')->nullable()->constrained('causales_rotacion');
                $table->string('comentarios', 500)->nullable();
                $table->string('recontratable', 20)->nullable();
                $table->date('fecha_conocimiento_falta')->nullable();
                $table->string('entrega_aviso_rescision', 20)->nullable();
                $table->date('fecha_entrega_aviso')->nullable();
                $table->boolean('es_rotacion')->default(true);
                $table->string('origen', 20)->default('sistema');
                $table->string('motivo_original', 300)->nullable();
                $table->string('causal_migrada_de', 200)->nullable();
                $table->string('estatus', 30)->default('borrador');
                $table->foreignId('creado_por')->nullable()->constrained('users');
                $table->foreignId('solicitado_por')->nullable()->constrained('users');
                $table->dateTime('enviado_en')->nullable();
                $table->foreignId('validado_por')->nullable()->constrained('users');
                $table->dateTime('validado_en')->nullable();
                $table->foreignId('juridico_por')->nullable()->constrained('users');
                $table->dateTime('juridico_en')->nullable();
                $table->foreignId('rechazado_por')->nullable()->constrained('users');
                $table->dateTime('rechazado_en')->nullable();
                $table->string('rechazado_area', 20)->nullable();
                $table->text('motivo_rechazo')->nullable();
                $table->foreignId('cerrado_por')->nullable()->constrained('users');
                $table->dateTime('cerrado_en')->nullable();
                $table->foreignId('cancelado_por')->nullable()->constrained('users');
                $table->dateTime('cancelado_en')->nullable();
                $table->text('motivo_cancelacion')->nullable();
                $table->dateTime('acceso_suspendido_en')->nullable();
                $table->json('alertas')->nullable();
                $table->timestamps();

                $table->index(['estatus', 'sucursal_id'], 'bajas_estatus_sucursal_idx');
                $table->index(['colaborador_id', 'estatus'], 'bajas_colaborador_estatus_idx');
                $table->index(['estatus', 'es_rotacion', 'fecha_baja'], 'bajas_rotacion_fecha_idx');
            });
        }

        if (! Schema::hasTable('baja_documentos')) {
            Schema::create('baja_documentos', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('baja_id')->constrained('bajas');
                $table->foreignId('documento_requerido_id')->nullable()->constrained('documentos_requeridos_baja', 'id', 'baja_docs_requerido_fk');
                $table->string('nombre', 150);
                $table->string('nombre_original', 255);
                $table->string('disco', 40);
                $table->string('ruta', 500);
                $table->string('mime', 120)->nullable();
                $table->unsignedBigInteger('tamano')->default(0);
                $table->foreignId('cargado_por')->nullable()->constrained('users');
                $table->foreignId('quitado_por')->nullable()->constrained('users');
                $table->timestamps();
                $table->softDeletes();

                $table->index(['baja_id', 'documento_requerido_id'], 'baja_docs_baja_requerido_idx');
            });
        }

        if (! Schema::hasTable('baja_bitacora')) {
            Schema::create('baja_bitacora', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('baja_id')->constrained('bajas');
                $table->foreignId('user_id')->nullable()->constrained('users');
                $table->string('accion', 40);
                $table->string('campo', 60)->nullable();
                $table->text('valor_anterior')->nullable();
                $table->text('valor_nuevo')->nullable();
                $table->text('motivo')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['baja_id', 'created_at'], 'baja_bitacora_baja_fecha_idx');
            });
        }

        if (! Schema::hasTable('plantilla_mensual')) {
            Schema::create('plantilla_mensual', function (Blueprint $table): void {
                $table->id();
                $table->char('periodo', 7)->comment('AAAA-MM');
                $table->unsignedSmallInteger('anio');
                $table->unsignedTinyInteger('mes');
                $table->foreignId('sucursal_id')->constrained('sucursales');
                $table->unsignedInteger('activos_inicio')->nullable()->comment('Activos el día 1');
                $table->unsignedInteger('activos_cierre')->nullable()->comment('Activos el último día');
                $table->decimal('plantilla_promedio', 8, 2)->nullable()->comment('(inicio + cierre) / 2');
                $table->string('fuente', 20)->default('foto');
                $table->dateTime('foto_inicio_en')->nullable();
                $table->dateTime('foto_cierre_en')->nullable();
                $table->timestamps();

                $table->unique(['periodo', 'sucursal_id'], 'plantilla_mensual_periodo_sucursal_unique');
                $table->index(['anio', 'mes'], 'plantilla_mensual_anio_mes_idx');
            });
        }

        app(MigracionCatalogoBajasService::class)->aplicar();
    }

    public function down(): void
    {
        Schema::dropIfExists('plantilla_mensual');
        Schema::dropIfExists('baja_bitacora');
        Schema::dropIfExists('baja_documentos');
        Schema::dropIfExists('bajas');
        Schema::dropIfExists('documentos_requeridos_baja');
        Schema::dropIfExists('formas_salida');

        foreach (['cierres_laborales', 'solicitudes_internas'] as $tabla) {
            if (Schema::hasColumn($tabla, 'causal_rotacion_original')) {
                Schema::table($tabla, fn (Blueprint $table) => $table->dropColumn('causal_rotacion_original'));
            }
        }

        if (Schema::hasColumn('causales_rotacion', 'forma_salida')) {
            Schema::table('causales_rotacion', fn (Blueprint $table) => $table->dropColumn(['forma_salida', 'requiere_juridico', 'comentario_obligatorio', 'alerta', 'legado', 'reemplazada_por_id']));
        }
    }
};
