<?php

namespace App\Services\CicloLaboral;

use App\Enums\EstadoCandidato;
use App\Enums\ProcesoAprobacion;
use App\Enums\RutaIntervencionCandidato;
use App\Enums\TipoBaja;
use App\Models\AjusteHistoricoVacaciones;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\HistorialSalario;
use App\Models\SeguimientoCandidato;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Timeline única y cronológica de una persona, de candidato a excolaborador
 * (y reingreso). NO es una segunda fuente de verdad: lee los registros que
 * ya existen —
 *  - seguimientos_candidato (Etapa 1),
 *  - bitácora de negocio activity_log (log "rh", AuditoriaService), que
 *    lleva colaborador_id / candidato_id de cada evento —
 * y los traduce a títulos en español.
 *
 * @phpstan-type ItemTimeline array{fecha: string|null, titulo: string, descripcion: string|null, actor: string|null, etapa: string, evento: string}
 */
class TimelineService
{
    /**
     * Evento de AuditoriaService → [título, etapa].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const EVENTOS = [
        'candidato_registrado' => ['Candidato registrado', 'reclutamiento'],
        'candidato_estado' => ['Avance en reclutamiento', 'reclutamiento'],
        'candidato_salida' => ['Proceso de reclutamiento cerrado', 'reclutamiento'],
        'candidato_psicometricas_enviadas' => ['Link de psicométricas enviado', 'reclutamiento'],
        'candidato_referencia_validada' => ['Referencia laboral validada', 'reclutamiento'],
        'aprobacion_preautorizada' => ['Preautorización operativa', 'aprobacion'],
        'aprobacion_autorizada_rh' => ['Autorización final de RH', 'aprobacion'],
        'aprobacion_rechazada' => ['Aprobación rechazada', 'aprobacion'],
        'aprobacion_devuelta' => ['Devuelto para corrección', 'aprobacion'],
        'candidato_contratacion_iniciada' => ['QR de contratación generado', 'contratacion'],
        'candidato_contratado' => ['Contratado: contratos firmados', 'contratacion'],
        'colaborador_alta' => ['Alta en el sistema', 'contratacion'],
        'alta_estado' => ['Avance de la contratación', 'contratacion'],
        'contrato_creado' => ['Contrato registrado', 'contratacion'],
        'documento_generado' => ['Documento generado', 'contratacion'],
        'documento_impreso' => ['Documento impreso', 'contratacion'],
        'documento_pendiente_firma_fisica' => ['Documento listo para firma física', 'contratacion'],
        'documento_pendiente_impresion' => ['Documento pendiente de impresión', 'contratacion'],
        'documento_firmado_fisicamente' => ['Firma física y huella registradas', 'contratacion'],
        'documento_firmado_digitalmente' => ['Documento firmado digitalmente', 'contratacion'],
        'documento_enviado_corporativo' => ['Original enviado a corporativo', 'contratacion'],
        'documento_recibido_corporativo' => ['Original recibido en corporativo', 'contratacion'],
        'documento_archivado' => ['Original archivado', 'contratacion'],
        'onboarding_iniciado' => ['Onboarding habilitado', 'onboarding'],
        'onboarding_modulo_aprobado' => ['Módulo de inducción aprobado', 'onboarding'],
        'onboarding_intento_reprobado' => ['Evaluación de inducción menor al mínimo', 'onboarding'],
        'onboarding_retroalimentacion' => ['Retroalimentación de RH · reevaluación habilitada', 'onboarding'],
        'activo_entregado' => ['Activo entregado', 'onboarding'],
        'onboarding_completado' => ['Onboarding completado', 'onboarding'],
        'colaborador_activado' => ['Inicio de operación en campo', 'onboarding'],
        'contrato_aviso_vencimiento' => ['Evaluación de periodo de prueba habilitada', 'periodo_prueba'],
        'evaluacion_capturada' => ['Evaluación del jefe enviada (preautorización)', 'periodo_prueba'],
        'evaluacion_devuelta' => ['Evaluación devuelta por RH', 'periodo_prueba'],
        'evaluacion_autorizada' => ['RH decidió el periodo de prueba', 'periodo_prueba'],
        'contrato_renovado' => ['Contrato por tiempo indeterminado generado', 'periodo_prueba'],
        'cierre_laboral_solicitado' => ['Baja solicitada', 'cierre'],
        'cierre_laboral_aplicado' => ['Baja aplicada', 'cierre'],
        'cierre_laboral_autorizado_rh' => ['RH autorizó la baja (flujo anterior)', 'cierre'], // LEGACY: solo registros anteriores.
        'cierre_laboral_rechazado' => ['Baja rechazada', 'cierre'],
        'cierre_laboral_devuelto' => ['Baja devuelta para corrección', 'cierre'],
        'cierre_laboral_aviso' => ['Renuncia / aviso registrado', 'cierre'],
        'finiquito_calculado' => ['Finiquito calculado', 'cierre'],
        'finiquito_autorizado' => ['Finiquito autorizado', 'cierre'],
        'finiquito_pago_programado' => ['Pago de finiquito programado', 'cierre'],
        'finiquito_cita' => ['Cita para firma y pago registrada', 'cierre'],
        'finiquito_firmado' => ['Finiquito firmado', 'cierre'],
        'finiquito_pagado' => ['Finiquito pagado', 'cierre'],
        'finiquito_entregado' => ['Finiquito entregado', 'cierre'],
        'cierre_laboral_baja' => ['Baja ejecutada', 'cierre'],
        'cierre_laboral_expediente_cerrado' => ['Cierre laboral completo', 'cierre'],
        'cierre_laboral_cancelado' => ['Cierre laboral cancelado', 'cierre'],
        'reingreso_solicitado' => ['Reingreso solicitado', 'reingreso'],
        'reingreso_autorizado' => ['RH autorizó el reingreso', 'reingreso'],
        'reingreso_rechazado' => ['Reingreso no viable', 'reingreso'],
        'reingreso_completado' => ['Reingreso completado', 'reingreso'],
        'intervencion_solicitada' => ['Intervención solicitada', 'intervencion'],
        'intervencion_aprobada' => ['Intervención aprobada: pasa a contratación', 'intervencion'],
        'intervencion_rechazo_confirmado' => ['Rechazo confirmado tras intervención', 'intervencion'],
        'induccion_evaluacion_presentada' => ['Evaluación de inducción presentada', 'onboarding'],
        'induccion_casos_calificados' => ['Evaluación de inducción calificada', 'onboarding'],
        'induccion_retroalimentacion' => ['Retroalimentación de inducción · reevaluación habilitada', 'onboarding'],
    ];

    /**
     * @return list<ItemTimeline>
     */
    public function de(Candidato|Colaborador $persona, int $limite = 300): array
    {
        [$candidatoId, $colaboradorId] = $persona instanceof Candidato
            ? [$persona->id, $persona->colaborador_id]
            : [$persona->candidato_id, $persona->id];

        $items = $this->bitacora($candidatoId, $colaboradorId, $limite);

        if ($candidatoId !== null) {
            $items = [...$items, ...$this->seguimientos($candidatoId, $limite)];
        }

        if ($colaboradorId !== null) {
            $items = [...$items, ...$this->cambiosSalario($colaboradorId, $limite), ...$this->ajustesVacaciones($colaboradorId, $limite), ...$this->solicitudesInternas($colaboradorId, $limite)];
        }

        usort($items, fn (array $a, array $b) => strcmp($a['fecha'] ?? '', $b['fecha'] ?? ''));

        return array_slice($items, -$limite);
    }

    /**
     * @return list<ItemTimeline>
     */
    private function seguimientos(int $candidatoId, int $limite): array
    {
        $items = [];
        $seguimientos = SeguimientoCandidato::query()
            ->where('candidato_id', $candidatoId)
            ->with('registradoPor:id,name,apellidos,colaborador_id')
            ->latest('fecha')
            ->limit($limite)
            ->get();

        foreach ($seguimientos as $s) {
            $items[] = [
                'fecha' => $s->fecha->toIso8601String(),
                'titulo' => $s->estado_nuevo !== null && $s->tipo->value === 'cambio_estado'
                    ? sprintf('Reclutamiento: %s', EstadoCandidato::tryFrom($s->estado_nuevo)?->etiqueta() ?? $s->estado_nuevo)
                    : $s->tipo->etiqueta(),
                'descripcion' => $s->nota,
                'actor' => $s->registradoPor?->nombreCompleto(),
                'etapa' => 'reclutamiento',
                'evento' => 'seguimiento_'.$s->tipo->value,
            ];
        }

        return $items;
    }

    /**
     * @return list<ItemTimeline>
     */
    private function bitacora(?int $candidatoId, ?int $colaboradorId, int $limite): array
    {
        if ($candidatoId === null && $colaboradorId === null) {
            return [];
        }

        $actividades = Activity::query()
            ->where('log_name', AuditoriaService::LOG)
            ->where(function (Builder $q) use ($candidatoId, $colaboradorId): void {
                if ($colaboradorId !== null) {
                    $q->orWhere('properties->colaborador_id', $colaboradorId)
                        ->orWhere(fn (Builder $s) => $s->where('subject_type', (new Colaborador)->getMorphClass())->where('subject_id', $colaboradorId));
                }

                if ($candidatoId !== null) {
                    $q->orWhere('properties->candidato_id', $candidatoId)
                        ->orWhere(fn (Builder $s) => $s->where('subject_type', (new Candidato)->getMorphClass())->where('subject_id', $candidatoId));
                }
            })
            // Los avances de estado del candidato ya están en sus seguimientos.
            ->whereNotIn('event', ['candidato_estado', 'candidato_salida', 'candidato_registrado'])
            ->with('causer')
            ->latest('id')
            ->limit($limite)
            ->get();

        $items = [];

        foreach ($actividades as $a) {
            $evento = (string) ($a->event ?? $a->description);
            [$titulo, $etapa] = self::EVENTOS[$evento] ?? [Str::of($evento)->replace('_', ' ')->ucfirst()->toString(), 'general'];
            $propiedades = $a->properties?->toArray() ?? [];
            $causer = $a->causer;

            $items[] = [
                'fecha' => $a->created_at?->toIso8601String(),
                'titulo' => $this->tituloConDetalle($titulo, $evento, $propiedades),
                'descripcion' => $this->descripcion($propiedades),
                'actor' => $causer instanceof User ? $causer->nombreCompleto() : null,
                'etapa' => $etapa,
                'evento' => $evento,
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function tituloConDetalle(string $titulo, string $evento, array $p): string
    {
        return match ($evento) {
            'onboarding_modulo_aprobado', 'onboarding_intento_reprobado' => sprintf('%s: %s (%s)', $titulo, $p['modulo'] ?? '', isset($p['calificacion']) ? number_format((float) $p['calificacion'], 1) : '—'),
            'activo_entregado' => sprintf('%s: %s', $titulo, $p['activo'] ?? ''),
            'cierre_laboral_aplicado' => sprintf('%s por %s', $titulo, is_string($p['aplicada_por'] ?? null) ? $p['aplicada_por'] : 'Recursos Humanos'),
            'evaluacion_autorizada' => ($p['renovar'] ?? null) ? 'RH autorizó la renovación' : 'RH autorizó la NO renovación',
            'induccion_casos_calificados' => sprintf('%s: %s/30 (%s)', $titulo, number_format((float) ($p['calificacion_total'] ?? 0), 1), $p['nivel'] ?? '—'),
            'aprobacion_preautorizada', 'aprobacion_autorizada_rh', 'aprobacion_rechazada', 'aprobacion_devuelta' => sprintf('%s · %s', $titulo, ProcesoAprobacion::tryFrom((string) ($p['proceso'] ?? ''))?->etiqueta() ?? ''),
            'intervencion_solicitada' => sprintf('%s · %s', $titulo, RutaIntervencionCandidato::tryFrom((string) ($p['ruta'] ?? ''))?->etiqueta() ?? ''),
            default => $titulo,
        };
    }

    /**
     * Cambios de salario diario (CompensacionService::actualizarSalario(),
     * historial_salarios) — nunca se "pierden" en el timeline aunque el
     * cambio no venga de un evento de activity_log.
     *
     * @return list<ItemTimeline>
     */
    private function cambiosSalario(int $colaboradorId, int $limite): array
    {
        $items = [];

        $historial = HistorialSalario::query()
            ->select(['id', 'colaborador_id', 'salario_diario_anterior', 'salario_diario_nuevo', 'motivo', 'actor_id', 'created_at'])
            ->where('colaborador_id', $colaboradorId)
            ->with('actor:id,name,apellidos')
            ->latest('id')
            ->limit($limite)
            ->get();

        foreach ($historial as $h) {
            $items[] = [
                'fecha' => $h->created_at?->toIso8601String(),
                'titulo' => sprintf(
                    'Cambio de salario diario: $%s → $%s',
                    $h->salario_diario_anterior !== null ? number_format((float) $h->salario_diario_anterior, 2) : '—',
                    number_format((float) $h->salario_diario_nuevo, 2),
                ),
                'descripcion' => $h->motivo,
                'actor' => $h->actor?->nombreCompleto(),
                'etapa' => 'activo',
                'evento' => 'cambio_salario',
            ];
        }

        return $items;
    }

    /**
     * Ajustes históricos de vacaciones (importador de Excel u otra fuente
     * manual) — ajustes_historicos_vacaciones, ledger append-only.
     *
     * @return list<ItemTimeline>
     */
    private function ajustesVacaciones(int $colaboradorId, int $limite): array
    {
        $items = [];

        $ajustes = AjusteHistoricoVacaciones::query()
            ->select(['id', 'colaborador_id', 'dias', 'motivo', 'actor_id', 'created_at'])
            ->where('colaborador_id', $colaboradorId)
            ->with('actor:id,name,apellidos')
            ->latest('id')
            ->limit($limite)
            ->get();

        foreach ($ajustes as $a) {
            $items[] = [
                'fecha' => $a->created_at?->toIso8601String(),
                'titulo' => sprintf('Ajuste histórico de vacaciones: %s días', $a->dias),
                'descripcion' => $a->motivo,
                'actor' => $a->actor?->nombreCompleto(),
                'etapa' => 'activo',
                'evento' => 'ajuste_vacaciones',
            ];
        }

        return $items;
    }

    /**
     * Solicitudes internas (vacaciones, permisos, préstamos…) del
     * colaborador — autoservicio unificado (SolicitudesService).
     *
     * @return list<ItemTimeline>
     */
    private function solicitudesInternas(int $colaboradorId, int $limite): array
    {
        $items = [];

        $solicitudes = SolicitudInterna::query()
            ->select(['id', 'colaborador_id', 'tipo', 'estado', 'folio', 'revisado_por', 'created_at', 'updated_at'])
            ->where('colaborador_id', $colaboradorId)
            ->whereNotNull('tipo')
            ->with('revisadoPor:id,name,apellidos')
            ->latest('id')
            ->limit($limite)
            ->get();

        foreach ($solicitudes as $s) {
            $items[] = [
                'fecha' => ($s->updated_at ?? $s->created_at)?->toIso8601String(),
                'titulo' => sprintf('%s: %s', $s->tipo->etiqueta(), $s->estado->etiqueta()),
                'descripcion' => "Folio {$s->folio}",
                'actor' => $s->revisadoPor?->nombreCompleto(),
                'etapa' => 'activo',
                'evento' => 'solicitud_interna',
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function descripcion(array $p): ?string
    {
        foreach (['motivo', 'comentario', 'tipo_baja', 'referencia'] as $clave) {
            if (isset($p[$clave]) && is_string($p[$clave]) && $p[$clave] !== '') {
                // La causa se guarda como clave del enum («baja_inmediata»): se
                // muestra su etiqueta en español.
                return $clave === 'tipo_baja' ? (TipoBaja::tryFrom($p[$clave])?->etiqueta() ?? $p[$clave]) : $p[$clave];
            }
        }

        return null;
    }
}
