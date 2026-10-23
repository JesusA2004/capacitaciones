<?php

use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\EstadoFormularioEvaluacion;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\FormularioEvaluacion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deshace de forma SEGURA e IDEMPOTENTE el cambio conceptual equivocado que
 * convirtió la evaluación de término de periodo de prueba (FO-RH-EPP-001) en
 * una «autoevaluación» del colaborador. El formato es y siempre fue del JEFE
 * INMEDIATO, que evalúa el desempeño observado del colaborador; RH administra,
 * valida y decide.
 *
 * NUNCA inventa ni borra respuestas:
 *  1. Evaluaciones SIN contestar (pendiente/devuelta, capturada_en NULL) cuyo
 *     evaluador quedó apuntando al propio colaborador: se reasignan a su jefe
 *     directo del organigrama (o a su gerente). Las ya contestadas NO se tocan
 *     — solo se listan para revisión manual (ver reporte del encargo).
 *  2. Reglas de aviso que la migración equivocada forzó a «colaborador» para
 *     evaluacion_pendiente / evaluacion_devuelta: se borra ese override exacto
 *     para que vuelva a regir la configuración (destinatario: evaluador).
 *  3. Formulario: si quedó publicada una versión con la descripción de
 *     autoevaluación y archivada la del jefe, se reactiva la del jefe y las
 *     evaluaciones sin contestar vuelven a apuntar a ella.
 *
 * En una base donde la migración equivocada nunca corrió (p. ej. la local al
 * momento del encargo), cada paso no encuentra nada y no cambia nada.
 */
return new class extends Migration
{
    private const DESCRIPCION_JEFE = 'Califica el desempeño del colaborador durante su capacitación inicial. Con esta evaluación se decide si se renueva su contrato.';

    private const DESCRIPCION_COLABORADOR = 'Contesta con honestidad cómo ha sido tu desempeño durante tu capacitación inicial. Recursos Humanos revisa tus respuestas y con ellas decide la renovación de tu contrato.';

    public function up(): void
    {
        // 1. Reasignar al jefe las evaluaciones SIN contestar apuntadas al colaborador.
        if (Schema::hasTable('evaluaciones_periodo_prueba')) {
            EvaluacionPeriodoPrueba::query()
                ->whereColumn('evaluador_colaborador_id', 'colaborador_id')
                ->whereNull('capturada_en')
                ->whereIn('estado', [EstadoEvaluacionPrueba::Pendiente->value, EstadoEvaluacionPrueba::Devuelta->value])
                ->with('colaborador:id,jefe_id,gerente_id')
                ->chunkById(200, function ($evaluaciones): void {
                    foreach ($evaluaciones as $evaluacion) {
                        $colaborador = $evaluacion->colaborador;
                        $jefe = $colaborador->jefe_id ?? $colaborador->gerente_id;

                        // Sin jefe en el organigrama: se deja en NULL (RH lo asigna).
                        $evaluacion->forceFill(['evaluador_colaborador_id' => $jefe !== $colaborador->id ? $jefe : null])->saveQuietly();
                    }
                });
        }

        // 2. Quitar el override de notificación que apuntaba al colaborador.
        if (Schema::hasTable('reglas_notificacion')) {
            DB::table('reglas_notificacion')
                ->whereIn('evento', ['evaluacion_pendiente', 'evaluacion_devuelta'])
                ->where('destinatarios', json_encode(['colaborador']))
                ->delete();
        }

        // 3. Reactivar la versión del formulario del jefe (si quedó archivada).
        if (Schema::hasTable('formularios_evaluacion')) {
            $colaboradorPub = FormularioEvaluacion::query()
                ->where('clave', 'periodo_prueba')
                ->where('estado', EstadoFormularioEvaluacion::Publicado->value)
                ->where('descripcion', self::DESCRIPCION_COLABORADOR)
                ->orderByDesc('version')
                ->first();

            $jefeArchivado = FormularioEvaluacion::query()
                ->where('clave', 'periodo_prueba')
                ->where('estado', EstadoFormularioEvaluacion::Archivado->value)
                ->where('descripcion', self::DESCRIPCION_JEFE)
                ->orderByDesc('version')
                ->first();

            if ($colaboradorPub !== null && $jefeArchivado !== null) {
                DB::transaction(function () use ($colaboradorPub, $jefeArchivado): void {
                    $colaboradorPub->update(['estado' => EstadoFormularioEvaluacion::Archivado]);
                    $jefeArchivado->update(['estado' => EstadoFormularioEvaluacion::Publicado]);

                    // Evaluaciones sin contestar vuelven a la versión del jefe.
                    EvaluacionPeriodoPrueba::query()
                        ->whereNull('capturada_en')
                        ->where('formulario_evaluacion_id', $colaboradorPub->id)
                        ->update(['formulario_evaluacion_id' => $jefeArchivado->id]);
                });
            }
        }
    }

    public function down(): void
    {
        // Migración de datos correctiva: no se revierte (nunca toca respuestas).
    }
};
