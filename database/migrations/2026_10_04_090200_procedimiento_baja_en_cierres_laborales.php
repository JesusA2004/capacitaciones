<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapas del "Procedimiento integral de baja de colaborador" (documento de
 * negocio de RH/Legal, ver docs/MATRIZ_DOCUMENTOS_POR_FLUJO.md) que el
 * cierre laboral no registraba:
 *
 *  - Fase 3 escenario B: negativa del colaborador a firmar/recibir
 *    (documentos que se intentaron entregar, testigos, finiquito a su
 *    disposición) → habilita el Acta administrativa de negativa.
 *  - Fase 4: notificación complementaria el mismo día (correo/WhatsApp) y
 *    su evidencia.
 *  - Fase 5: baja operativa (IMSS, control de asistencia, accesos, aviso
 *    interno).
 *  - Fase 6: consignación preventiva del finiquito si no lo cobra.
 *  - Fase 1: evidencia objetiva de desempeño (KPI's, reportes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cierres_laborales', function (Blueprint $table): void {
            $table->timestamp('negativa_firma_en')->nullable();
            $table->foreignId('negativa_firma_por')->nullable()->constrained('users')->nullOnDelete();
            $table->json('negativa_documentos')->nullable();
            $table->text('negativa_observaciones')->nullable();
            $table->json('negativa_participantes')->nullable();
            $table->json('testigos')->nullable();
            $table->boolean('finiquito_a_disposicion')->default(false);
            $table->timestamp('notificacion_electronica_en')->nullable();
            $table->foreignId('notificacion_electronica_por')->nullable()->constrained('users')->nullOnDelete();
            $table->json('notificacion_medios')->nullable();
            $table->json('evidencias')->nullable();
            $table->timestamp('baja_imss_en')->nullable();
            $table->timestamp('baja_asistencia_en')->nullable();
            $table->timestamp('accesos_cancelados_en')->nullable();
            $table->timestamp('aviso_interno_en')->nullable();
            $table->timestamp('consignacion_preventiva_en')->nullable();
            $table->text('consignacion_observaciones')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cierres_laborales', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('negativa_firma_por');
            $table->dropConstrainedForeignId('notificacion_electronica_por');
            $table->dropColumn([
                'negativa_firma_en', 'negativa_documentos', 'negativa_observaciones', 'negativa_participantes', 'testigos',
                'finiquito_a_disposicion', 'notificacion_electronica_en', 'notificacion_medios', 'evidencias', 'baja_imss_en',
                'baja_asistencia_en', 'accesos_cancelados_en', 'aviso_interno_en', 'consignacion_preventiva_en', 'consignacion_observaciones',
            ]);
        });
    }
};
