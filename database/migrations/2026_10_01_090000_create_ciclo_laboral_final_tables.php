<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cierre definitivo del ciclo laboral (docs/AUDITORIA_CICLO_LABORAL_FINAL.md):
 *
 *  - motor genérico de aprobaciones (preautorización operativa → RH),
 *  - registros estructurados del reclutamiento (entrevista, psicométricas,
 *    socioeconómico, referencias, evidencias privadas),
 *  - onboarding (módulos, avance, intentos, activos y responsivas),
 *  - reingreso,
 *  - parámetros de catálogo (duración del periodo de prueba por puesto,
 *    grupo de indicador, sucursal corporativa, vigencia documental),
 *  - columnas nuevas del cierre laboral (autorización RH, finiquito
 *    autorizado, programación de pago, cita) y de la bandeja de tareas.
 *
 * Migración aditiva: no modifica ni borra columnas existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aprobaciones', function (Blueprint $table): void {
            $table->id();
            $table->morphs('aprobable', 'aprobaciones_aprobable_idx');
            $table->string('proceso', 40);
            $table->string('etapa', 30);
            // Una solicitud devuelta para corrección abre una ronda nueva: la
            // ronda anterior se conserva completa como historial.
            $table->unsignedSmallInteger('ronda')->default(1);
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->foreignId('candidato_id')->nullable()->constrained('candidatos')->nullOnDelete();
            $table->foreignId('solicitante_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Aprobador resuelto desde el organigrama al abrir la etapa
            // (snapshot: si después cambia el jefe, esta fila no cambia).
            $table->foreignId('aprobador_colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->foreignId('aprobador_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('capacidad_requerida', 80)->nullable();
            $table->string('estado', 20)->default('pendiente');
            // Decisión de negocio asociada (p. ej. "renovar" / "no_renovar").
            $table->string('decision', 30)->nullable();
            $table->text('comentario')->nullable();
            $table->foreignId('decidido_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decidido_en')->nullable();
            $table->json('decisor_snapshot')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            // Una sola fila por etapa y ronda: dos aprobadores concurrentes o
            // un doble clic nunca duplican la decisión.
            $table->unique(['aprobable_type', 'aprobable_id', 'proceso', 'etapa', 'ronda'], 'aprobaciones_etapa_ronda_unique');
            $table->index(['estado', 'etapa'], 'aprobaciones_estado_etapa_idx');
            $table->index(['aprobador_colaborador_id', 'estado'], 'aprobaciones_aprobador_estado_idx');
            $table->index('colaborador_id');
            $table->index('candidato_id');
        });

        Schema::table('candidatos', function (Blueprint $table): void {
            // Hito máximo alcanzado en el pipeline (orden de EstadoCandidato):
            // el embudo cuenta personas que ALCANZARON cada etapa aunque hoy
            // estén más adelante o hayan salido.
            $table->unsignedTinyInteger('etapa_maxima')->default(1)->after('estado')->index();
            $table->text('motivo_salida')->nullable();
            $table->timestamp('salida_en')->nullable();
            $table->foreignId('salida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('autorizado_rh_en')->nullable();
            $table->index(['sucursal_id', 'estado'], 'candidatos_sucursal_estado_idx');
            $table->index('created_at');
        });

        Schema::create('candidato_entrevistas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('candidato_id')->constrained('candidatos')->cascadeOnDelete();
            $table->dateTime('realizada_en');
            $table->foreignId('entrevistador_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('observaciones')->nullable();
            $table->string('resultado', 20);
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('candidato_id');
        });

        Schema::create('candidato_psicometricas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('candidato_id')->constrained('candidatos')->cascadeOnDelete();
            $table->string('link', 500)->nullable();
            $table->timestamp('enviada_en')->nullable();
            $table->foreignId('enviada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resultados_en')->nullable();
            $table->foreignId('resultados_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resumen_resultados')->nullable();
            $table->string('revision_resultado', 20)->nullable();
            $table->text('revision_observaciones')->nullable();
            $table->foreignId('revisada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revisada_en')->nullable();
            $table->timestamps();

            $table->index('candidato_id');
        });

        Schema::create('candidato_socioeconomicos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('candidato_id')->constrained('candidatos')->cascadeOnDelete();
            $table->date('fecha_visita');
            $table->foreignId('visitador_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('direccion', 500);
            // Criterios informativos (vivienda en orden, vive con familia,
            // arraigo, resguardo de motocicleta...): registro del responsable,
            // el sistema no decide por él.
            $table->json('checklist')->nullable();
            $table->text('riesgos')->nullable();
            $table->text('observaciones')->nullable();
            $table->string('resultado', 20);
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('candidato_id');
        });

        Schema::create('candidato_referencias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('candidato_id')->constrained('candidatos')->cascadeOnDelete();
            $table->string('empresa');
            $table->string('contacto');
            $table->string('telefono', 30)->nullable();
            $table->string('relacion_puesto')->nullable();
            $table->string('resultado', 20);
            $table->text('observaciones')->nullable();
            $table->date('fecha_validacion');
            $table->foreignId('validada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('candidato_id');
        });

        Schema::create('candidato_evidencias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('candidato_id')->constrained('candidatos')->cascadeOnDelete();
            $table->nullableMorphs('evidenciable', 'candidato_evidencias_evidenciable_idx');
            $table->string('tipo', 20);
            // Archivo privado en el NAS: solo metadatos aquí, nunca binarios.
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->foreignId('subida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('onboarding_modulos', function (Blueprint $table): void {
            $table->id();
            $table->string('clave', 80)->nullable()->unique();
            $table->string('titulo');
            $table->text('descripcion')->nullable();
            $table->string('tipo', 20);
            // null = aplica a cualquier puesto (siempre null en institucional).
            $table->foreignId('puesto_id')->nullable()->constrained('puestos')->nullOnDelete();
            $table->unsignedSmallInteger('orden')->default(1);
            $table->string('contenido_url', 500)->nullable();
            $table->longText('contenido')->nullable();
            // [{pregunta, opciones: [..], correcta: indice}] — la respuesta
            // correcta nunca se envía al cliente.
            $table->json('preguntas')->nullable();
            $table->decimal('calificacion_minima', 4, 2)->default(8);
            $table->boolean('obligatorio')->default(true);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['tipo', 'puesto_id', 'activo'], 'onboarding_modulos_tipo_puesto_idx');
        });

        Schema::create('onboarding_procesos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->foreignId('contrato_laboral_id')->nullable()->constrained('contratos_laborales')->nullOnDelete();
            $table->unsignedBigInteger('reingreso_id')->nullable();
            $table->string('estado', 30);
            $table->timestamp('iniciado_en');
            $table->timestamp('completado_en')->nullable();
            $table->foreignId('completado_por')->nullable()->constrained('users')->nullOnDelete();
            // Solo lleva valor mientras el proceso está abierto: el índice
            // único impide dos onboardings abiertos para la misma persona
            // (doble disparo de la firma del contrato).
            $table->unsignedBigInteger('colaborador_abierto_id')->nullable()->unique();
            $table->timestamps();

            $table->index(['colaborador_id', 'estado'], 'onboarding_procesos_colaborador_estado_idx');
        });

        Schema::create('onboarding_avances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('onboarding_proceso_id')->constrained('onboarding_procesos')->cascadeOnDelete();
            $table->foreignId('onboarding_modulo_id')->constrained('onboarding_modulos')->restrictOnDelete();
            $table->string('tipo', 20);
            $table->unsignedSmallInteger('orden');
            $table->string('estado', 30);
            $table->unsignedSmallInteger('intentos_count')->default(0);
            $table->decimal('ultima_calificacion', 4, 2)->nullable();
            $table->decimal('mejor_calificacion', 4, 2)->nullable();
            $table->timestamp('aprobado_en')->nullable();
            $table->text('retroalimentacion')->nullable();
            $table->foreignId('retroalimentado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('retroalimentado_en')->nullable();
            $table->timestamps();

            $table->unique(['onboarding_proceso_id', 'onboarding_modulo_id'], 'onboarding_avances_proceso_modulo_unique');
            $table->index('estado');
        });

        Schema::create('onboarding_intentos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('onboarding_avance_id')->constrained('onboarding_avances')->cascadeOnDelete();
            $table->unsignedSmallInteger('numero');
            $table->decimal('calificacion', 4, 2);
            $table->boolean('aprobado');
            $table->json('respuestas')->nullable();
            // Retroalimentación de RH que habilitó ESTE intento (historial).
            $table->text('retroalimentacion_previa')->nullable();
            $table->foreignId('presentado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['onboarding_avance_id', 'numero'], 'onboarding_intentos_avance_numero_unique');
        });

        Schema::create('tipos_activo', function (Blueprint $table): void {
            $table->id();
            $table->string('clave', 60)->unique();
            $table->string('nombre');
            $table->boolean('requiere_identificador')->default(false);
            // null = aplica a todos los puestos; lista de ids = solo esos.
            $table->json('puesto_ids')->nullable();
            $table->boolean('obligatorio')->default(true);
            $table->string('plantilla_responsiva', 80)->default('carta_responsiva');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('entregas_activo', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->foreignId('onboarding_proceso_id')->nullable()->constrained('onboarding_procesos')->nullOnDelete();
            $table->foreignId('tipo_activo_id')->constrained('tipos_activo')->restrictOnDelete();
            $table->string('identificador', 120)->nullable();
            $table->string('descripcion')->nullable();
            $table->date('entregado_en');
            $table->foreignId('entregado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('generated_document_id')->nullable()->constrained('generated_documents')->nullOnDelete();
            $table->string('estado', 20)->default('entregado');
            $table->date('devuelto_en')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->unique(['onboarding_proceso_id', 'tipo_activo_id'], 'entregas_activo_proceso_tipo_unique');
            $table->index(['colaborador_id', 'estado'], 'entregas_activo_colaborador_estado_idx');
        });

        Schema::create('reingresos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->foreignId('cierre_anterior_id')->nullable()->constrained('cierres_laborales')->nullOnDelete();
            $table->string('estado', 30);
            $table->text('motivo');
            $table->foreignId('puesto_id')->nullable()->constrained('puestos')->nullOnDelete();
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->foreignId('jefe_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->string('tipo_contratacion', 30)->nullable();
            $table->decimal('sueldo_mensual', 10, 2)->nullable();
            $table->date('fecha_reingreso')->nullable();
            // Ids de document_types que se piden de nuevo (vencidos,
            // faltantes o requeridos expresamente) — nada más.
            $table->json('documentos_requeridos')->nullable();
            $table->text('comentario_decision')->nullable();
            $table->foreignId('solicitado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decidido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decidido_en')->nullable();
            $table->foreignId('contrato_laboral_id')->nullable()->constrained('contratos_laborales')->nullOnDelete();
            $table->timestamp('completado_en')->nullable();
            // Un solo reingreso abierto por persona.
            $table->unsignedBigInteger('colaborador_abierto_id')->nullable()->unique();
            $table->timestamps();

            $table->index(['colaborador_id', 'estado'], 'reingresos_colaborador_estado_idx');
        });

        Schema::table('onboarding_procesos', function (Blueprint $table): void {
            $table->foreign('reingreso_id')->references('id')->on('reingresos')->nullOnDelete();
        });

        Schema::table('puestos', function (Blueprint $table): void {
            // Duración oficial del periodo de prueba del puesto (gestor 2,
            // gerente 3, regional 3 — valores iniciales del seeder).
            $table->unsignedTinyInteger('meses_periodo_prueba')->nullable();
            $table->string('grupo_indicador', 30)->nullable();
        });

        Schema::table('sucursales', function (Blueprint $table): void {
            $table->boolean('es_corporativo')->default(false);
        });

        Schema::table('document_types', function (Blueprint $table): void {
            // Meses de vigencia de un documento aprobado (p. ej. comprobante
            // de domicilio, antecedentes no penales). null = no vence.
            $table->unsignedSmallInteger('vigencia_meses')->nullable();
        });

        Schema::table('cierres_laborales', function (Blueprint $table): void {
            $table->timestamp('autorizado_rh_en')->nullable();
            $table->foreignId('autorizado_rh_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rechazado_en')->nullable();
            $table->text('motivo_rechazo')->nullable();
            $table->timestamp('finiquito_autorizado_en')->nullable();
            $table->foreignId('finiquito_autorizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->date('pago_programado_para')->nullable();
            $table->decimal('pago_monto', 12, 2)->nullable();
            $table->string('pago_metodo', 60)->nullable();
            $table->foreignId('pago_responsable_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('pago_observaciones')->nullable();
            $table->foreignId('pago_programado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('pago_programado_en')->nullable();
            $table->dateTime('cita_firma_en')->nullable();
            $table->foreignId('cita_registrada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['estado', 'fecha_efectiva'], 'cierres_laborales_estado_fecha_idx');
        });

        Schema::table('tareas_rh', function (Blueprint $table): void {
            $table->foreignId('candidato_id')->nullable()->constrained('candidatos')->nullOnDelete();
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->index(['sucursal_id', 'resuelta_en'], 'tareas_rh_sucursal_resuelta_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tareas_rh', function (Blueprint $table): void {
            $table->dropIndex('tareas_rh_sucursal_resuelta_idx');
            $table->dropConstrainedForeignId('candidato_id');
            $table->dropConstrainedForeignId('sucursal_id');
        });

        Schema::table('cierres_laborales', function (Blueprint $table): void {
            $table->dropIndex('cierres_laborales_estado_fecha_idx');
            $table->dropConstrainedForeignId('autorizado_rh_por');
            $table->dropConstrainedForeignId('finiquito_autorizado_por');
            $table->dropConstrainedForeignId('pago_responsable_user_id');
            $table->dropConstrainedForeignId('pago_programado_por');
            $table->dropConstrainedForeignId('cita_registrada_por');
            $table->dropColumn([
                'autorizado_rh_en', 'rechazado_en', 'motivo_rechazo', 'finiquito_autorizado_en',
                'pago_programado_para', 'pago_monto', 'pago_metodo', 'pago_observaciones',
                'pago_programado_en', 'cita_firma_en',
            ]);
        });

        Schema::table('document_types', fn (Blueprint $table) => $table->dropColumn('vigencia_meses'));
        Schema::table('sucursales', fn (Blueprint $table) => $table->dropColumn('es_corporativo'));
        Schema::table('puestos', fn (Blueprint $table) => $table->dropColumn(['meses_periodo_prueba', 'grupo_indicador']));

        Schema::table('onboarding_procesos', function (Blueprint $table): void {
            $table->dropForeign(['reingreso_id']);
        });

        Schema::dropIfExists('reingresos');
        Schema::dropIfExists('entregas_activo');
        Schema::dropIfExists('tipos_activo');
        Schema::dropIfExists('onboarding_intentos');
        Schema::dropIfExists('onboarding_avances');
        Schema::dropIfExists('onboarding_procesos');
        Schema::dropIfExists('onboarding_modulos');
        Schema::dropIfExists('candidato_evidencias');
        Schema::dropIfExists('candidato_referencias');
        Schema::dropIfExists('candidato_socioeconomicos');
        Schema::dropIfExists('candidato_psicometricas');
        Schema::dropIfExists('candidato_entrevistas');

        Schema::table('candidatos', function (Blueprint $table): void {
            $table->dropIndex('candidatos_sucursal_estado_idx');
            $table->dropIndex(['created_at']);
            $table->dropIndex(['etapa_maxima']);
            $table->dropConstrainedForeignId('salida_por');
            $table->dropColumn(['etapa_maxima', 'motivo_salida', 'salida_en', 'autorizado_rh_en']);
        });

        Schema::dropIfExists('aprobaciones');
    }
};
