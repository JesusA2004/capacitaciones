<?php

use App\Enums\ClaveFormularioEvaluacion;
use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\EstadoFormularioEvaluacion;
use App\Enums\TipoTarea;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\FormularioEvaluacion;
use App\Services\Contratos\EvaluacionPeriodoPruebaService;
use App\Services\Tareas\TareaService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La evaluación de periodo de prueba (FO-RH-EPP-001) pasa a ser una
 * AUTOEVALUACIÓN: la contesta el colaborador evaluado, nunca RH ni su jefe.
 * Migración de DATOS (no borra nada):
 *
 *  1. Evaluaciones aún SIN contestar (pendiente/devuelta) asignadas a un
 *     jefe: ahora las contesta la persona evaluada. Su tarea abierta del jefe
 *     se resuelve y se abre «Contesta tu evaluación» para el colaborador.
 *     Las ya contestadas/decididas se quedan tal cual (su evaluador
 *     histórico se conserva).
 *  2. Formulario: si la versión publicada conserva la descripción por
 *     defecto escrita para el jefe, se publica una versión nueva idéntica
 *     con la descripción para el colaborador (la anterior queda archivada y
 *     las evaluaciones contestadas con ella no cambian). Las evaluaciones sin
 *     contestar apuntan a la versión nueva (mismos criterios y reglas).
 *  3. Reglas de aviso personalizadas que aún mandaban «evaluación por
 *     contestar/devuelta» al jefe: ahora al colaborador.
 */
return new class extends Migration
{
    private const DESCRIPCION_JEFE = 'Califica el desempeño del colaborador durante su capacitación inicial. Con esta evaluación se decide si se renueva su contrato.';

    private const DESCRIPCION_COLABORADOR = 'Contesta con honestidad cómo ha sido tu desempeño durante tu capacitación inicial. Recursos Humanos revisa tus respuestas y con ellas decide la renovación de tu contrato.';

    public function up(): void
    {
        $sinContestar = [EstadoEvaluacionPrueba::Pendiente->value, EstadoEvaluacionPrueba::Devuelta->value];

        // 2. Versión nueva del formulario (solo si conserva el texto por defecto del jefe).
        $vigente = FormularioEvaluacion::query()
            ->where('clave', ClaveFormularioEvaluacion::PeriodoPrueba->value)
            ->where('estado', EstadoFormularioEvaluacion::Publicado->value)
            ->orderByDesc('version')
            ->first();

        if ($vigente !== null && trim((string) $vigente->descripcion) === self::DESCRIPCION_JEFE) {
            DB::transaction(function () use ($vigente, $sinContestar): void {
                $siguiente = (int) FormularioEvaluacion::query()->where('clave', ClaveFormularioEvaluacion::PeriodoPrueba->value)->max('version') + 1;

                $nueva = FormularioEvaluacion::query()->create([
                    'clave' => ClaveFormularioEvaluacion::PeriodoPrueba,
                    'version' => $siguiente,
                    'codigo' => $vigente->codigo,
                    'titulo' => $vigente->titulo,
                    'descripcion' => self::DESCRIPCION_COLABORADOR,
                    'diseno' => $vigente->diseno,
                    'definicion' => $vigente->definicion,
                    'estado' => EstadoFormularioEvaluacion::Publicado,
                    'publicado_en' => now(),
                ]);

                $vigente->update(['estado' => EstadoFormularioEvaluacion::Archivado]);

                EvaluacionPeriodoPrueba::query()
                    ->whereIn('estado', $sinContestar)
                    ->where(fn ($q) => $q->whereNull('formulario_evaluacion_id')->orWhere('formulario_evaluacion_id', $vigente->id))
                    ->update(['formulario_evaluacion_id' => $nueva->id]);
            });
        }

        // 1. Las pendientes/devueltas las contesta ahora la persona evaluada.
        $tareas = app(TareaService::class);
        $servicio = app(EvaluacionPeriodoPruebaService::class);

        EvaluacionPeriodoPrueba::query()
            ->whereIn('estado', $sinContestar)
            ->with(['colaborador.user', 'contrato'])
            ->orderBy('id')
            ->each(function (EvaluacionPeriodoPrueba $evaluacion) use ($tareas, $servicio): void {
                DB::transaction(function () use ($evaluacion, $tareas, $servicio): void {
                    $evaluacion->update(['evaluador_colaborador_id' => $evaluacion->colaborador_id]);
                    $tareas->resolver(TipoTarea::EvaluacionPendiente, $evaluacion);
                    $servicio->asignarAlColaborador(
                        $evaluacion,
                        'Contesta tu evaluación de periodo de prueba',
                        'Recursos Humanos te asignó tu evaluación de capacitación inicial. Contéstala en Mi espacio.',
                        $evaluacion->fecha_limite?->toDateString(),
                    );
                });
            });

        // 3. Reglas personalizadas de aviso (si alguien las guardó para el jefe).
        DB::table('reglas_notificacion')
            ->whereIn('evento', ['evaluacion_pendiente', 'evaluacion_devuelta'])
            ->update(['destinatarios' => json_encode(['colaborador']), 'fallback' => json_encode([]), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Migración de datos: no se revierte (nunca se borran respuestas,
        // tareas ni versiones del formulario).
    }
};
