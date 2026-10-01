<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Consolida los estados del candidato al pipeline real del reclutamiento
 * (ver docs/AUDITORIA_CICLO_LABORAL_FINAL.md §5) y calcula el hito máximo
 * alcanzado (candidatos.etapa_maxima) para el embudo.
 *
 * Mapeo deliberadamente conservador: los estados "oferta/aprobación" y
 * "listo para contratación" nunca tuvieron preautorización ni autorización
 * RH registrada, así que caen ANTES de esas aprobaciones (el gerente debe
 * preautorizar y RH autorizar). Los valores se escriben como texto fijo
 * (no se usa el enum) para que la migración no cambie si el enum evoluciona.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const MAPEO = [
        'preseleccion' => 'entrevista_pendiente',
        'entrevista' => 'entrevista_pendiente',
        'psicometricos' => 'psicometricas_pendientes',
        'pruebas' => 'psicometricas_pendientes',
        'estudio_socioeconomico' => 'socioeconomico_pendiente',
        'validacion_documental' => 'referencias_pendientes',
        'oferta_aprobacion' => 'preseleccion_gerente',
        'listo_para_contratacion' => 'autorizacion_rh_pendiente',
    ];

    /** @var array<string, int> */
    private const ORDEN = [
        'recibidos' => 1,
        'entrevista_pendiente' => 2,
        'psicometricas_pendientes' => 3,
        'revision_psicometricas' => 4,
        'socioeconomico_pendiente' => 5,
        'referencias_pendientes' => 6,
        'preseleccion_gerente' => 7,
        'autorizacion_rh_pendiente' => 8,
        'autorizado_rh' => 9,
        'en_contratacion' => 10,
        'contratado' => 11,
    ];

    public function up(): void
    {
        foreach (self::MAPEO as $anterior => $nuevo) {
            DB::table('candidatos')->where('estado', $anterior)->update(['estado' => $nuevo]);
            DB::table('seguimientos_candidato')->where('estado_anterior', $anterior)->update(['estado_anterior' => $nuevo]);
            DB::table('seguimientos_candidato')->where('estado_nuevo', $anterior)->update(['estado_nuevo' => $nuevo]);
        }

        DB::table('candidatos')->orderBy('id')->select(['id', 'estado'])->chunkById(500, function ($candidatos): void {
            foreach ($candidatos as $candidato) {
                $estados = DB::table('seguimientos_candidato')
                    ->where('candidato_id', $candidato->id)
                    ->get(['estado_anterior', 'estado_nuevo'])
                    ->flatMap(fn ($s) => [$s->estado_anterior, $s->estado_nuevo])
                    ->push($candidato->estado)
                    ->filter();

                $maximo = $estados->map(fn (string $e) => self::ORDEN[$e] ?? 1)->max() ?? 1;

                DB::table('candidatos')->where('id', $candidato->id)->update(['etapa_maxima' => max(1, (int) $maximo)]);
            }
        });
    }

    public function down(): void
    {
        // El mapeo es con pérdida (varios estados viejos → uno nuevo): no se
        // revierte. Las filas conservan su historial en seguimientos_candidato.
    }
};
