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
     * Su motivo es texto libre y suele citar un importe («era 20,000, debe ser 25,000»).
     */
    private const EVENTOS_CON_POSIBLES_IMPORTES = '/^(correccion_|documento_regenerado|documento_revision_firmado|expediente_datos|contrato_|salario|sueldo|recibo|nomina)/';

    /**
     * Evento de AuditoriaService → [título, etapa].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const EVENTOS = [
        // Cambios administrativos de datos (detalle antes/después en el
        // «Historial de cambios» del expediente, HistorialCambiosDatosService).
        'expediente_datos_editados' => ['Datos personales editados por RH', 'activo'],
        'expediente_datos_laborales_editados' => ['Datos laborales editados por RH', 'activo'],
        'expediente_datos_actualizados' => ['Corrección de datos aplicada', 'activo'],
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
        // Eventos que antes salían con su clave cruda («Solicitud empleo
        // seccion guardada»): título en español correcto, desde el origen.
        'candidato_actualizado' => ['Datos del candidato actualizados', 'reclutamiento'],
        'candidato_cv_cargado' => ['Currículum cargado', 'reclutamiento'],
        'candidato_socioeconomico_cargado' => ['Estudio socioeconómico cargado', 'reclutamiento'],
        'candidato_evidencias_agregadas' => ['Archivos agregados al expediente del candidato', 'reclutamiento'],
        'candidato_qr_contratacion_reemitido' => ['QR de contratación reemitido', 'contratacion'],
        'aprobacion_autorizada_intervencion' => ['Autorización por intervención', 'aprobacion'],
        'decision_reabierta' => ['Decisión reabierta', 'aprobacion'],
        'solicitud_empleo_seccion_guardada' => ['Solicitud de empleo: sección guardada', 'contratacion'],
        'solicitud_empleo_fecha_emision' => ['Solicitud de empleo: fecha de emisión elegida', 'contratacion'],
        'registro_app_datos_capturados' => ['Registro en la app: datos capturados', 'contratacion'],
        'registro_app_completado' => ['Registro en la app completado', 'contratacion'],
        'alta_imss_registrada' => ['Alta en el IMSS registrada', 'contratacion'],
        'contrato_ingreso_corregido' => ['Fecha de ingreso del contrato corregida', 'contratacion'],
        'contrato_terminado' => ['Contrato terminado', 'contratacion'],
        'contratos_regularizados' => ['Contratos regularizados', 'contratacion'],
        'paquete_documental_generado' => ['Paquete de documentos generado', 'documentos'],
        'datos_documento_completados' => ['Datos del documento completados', 'documentos'],
        'documento_enviado_a_firma' => ['Documento enviado a firma', 'documentos'],
        'documento_regenerado' => ['Documento regenerado', 'documentos'],
        'documento_revision_firmado' => ['Revisión del documento firmado', 'documentos'],
        'documento_consultado' => ['Documento consultado', 'documentos'],
        'documento_descargado' => ['Documento descargado', 'documentos'],
        'documento_descargado_word' => ['Documento descargado en Word', 'documentos'],
        'documento_maestro_probado' => ['Plantilla de documento probada con sus datos', 'documentos'],
        'expediente_descargado_zip' => ['Expediente descargado (ZIP)', 'documentos'],
        'expediente_historico_vinculado' => ['Expediente histórico vinculado', 'documentos'],
        'expediente_synology_ligado_manual' => ['Carpeta del NAS ligada manualmente', 'documentos'],
        'carta_compromiso_generada' => ['Carta compromiso generada', 'documentos'],
        'carta_compromiso_generada_automaticamente' => ['Carta compromiso generada automáticamente', 'documentos'],
        'carta_compromiso_firmada' => ['Carta compromiso firmada', 'documentos'],
        'carta_compromiso_cumplida' => ['Carta compromiso cumplida', 'documentos'],
        'descriptivo_puesto_acusado' => ['Descriptivo de puesto: acuse de recibido', 'documentos'],
        'responsiva_generada' => ['Responsiva generada', 'documentos'],
        'responsiva_regenerada' => ['Responsiva regenerada', 'documentos'],
        'responsiva_firmada_fisicamente' => ['Responsiva firmada', 'documentos'],
        'responsiva_devuelta' => ['Responsiva devuelta', 'documentos'],
        'responsiva_cancelada' => ['Responsiva cancelada', 'documentos'],
        'aviso_sistemas_alta_enviado' => ['Aviso de alta enviado a Sistemas', 'accesos'],
        'aviso_sistemas_alta_fallido' => ['Aviso de alta a Sistemas: no se pudo enviar', 'accesos'],
        'aviso_sistemas_alta_reenvio_solicitado' => ['Reenvío del aviso de alta a Sistemas', 'accesos'],
        'sistemas_alta_atendida' => ['Sistemas atendió el alta', 'accesos'],
        'sistemas_alta_reabierta' => ['Alta en Sistemas reabierta', 'accesos'],
        'cuenta_creada' => ['Cuenta de acceso creada', 'accesos'],
        'cuenta_reactivada' => ['Cuenta de acceso reactivada', 'accesos'],
        'credenciales_generadas' => ['Credenciales generadas', 'accesos'],
        'credenciales_enviadas_por_correo' => ['Credenciales enviadas por correo', 'accesos'],
        'invitacion_qr_reemplazada_por_credenciales' => ['QR reemplazado por credenciales', 'accesos'],
        'username_asignado' => ['Usuario asignado', 'accesos'],
        'contrasena_restablecida' => ['Contraseña restablecida', 'accesos'],
        'acceso_revocado' => ['Acceso revocado', 'accesos'],
        'acceso_restablecido' => ['Acceso restablecido', 'accesos'],
        'correccion_aplicada' => ['Corrección de datos aplicada', 'activo'],
        'correccion_respondida' => ['Solicitud de corrección respondida', 'activo'],
        'colaborador_creado' => ['Colaborador creado', 'contratacion'],
        'colaborador_creado_desde_importacion' => ['Colaborador creado desde importación', 'contratacion'],
        'colaborador_actualizado' => ['Datos del colaborador actualizados', 'activo'],
        'colaborador_reingreso' => ['Reingreso del colaborador', 'reingreso'],
        'jefe_directo_cambiado' => ['Jefe directo actualizado', 'activo'],
        'foto_perfil_actualizada' => ['Fotografía actualizada', 'activo'],
        'foto_perfil_cambio_solicitado' => ['Cambio de fotografía solicitado', 'activo'],
        'foto_perfil_cambio_aprobado' => ['Cambio de fotografía aprobado', 'activo'],
        'foto_perfil_cambio_rechazado' => ['Cambio de fotografía rechazado', 'activo'],
        'solicitud_visto_bueno' => ['Visto bueno de una solicitud', 'activo'],
        'prestamo_autorizado' => ['Préstamo autorizado', 'activo'],
        'prestamo_rechazado' => ['Préstamo rechazado', 'activo'],
        'prestamo_resguardado' => ['Préstamo resguardado', 'activo'],
        'incapacidad_registrada' => ['Incapacidad registrada', 'activo'],
        'incapacidad_corregida' => ['Incapacidad corregida', 'activo'],
        'incapacidad_cancelada' => ['Incapacidad cancelada', 'activo'],
        'incapacidad_documento_cargado' => ['Documento de incapacidad cargado', 'activo'],
        'incapacidad_documento_consultado' => ['Documento de incapacidad consultado', 'activo'],
        'recibo_nomina_preparado' => ['Recibo de nómina preparado', 'activo'],
        'recibo_nomina_generado' => ['Recibo de nómina generado', 'activo'],
        'recibo_nomina_emitido' => ['Recibo de nómina emitido', 'activo'],
        'recibo_nomina_editado' => ['Recibo de nómina editado', 'activo'],
        'recibo_nomina_cancelado' => ['Recibo de nómina cancelado', 'activo'],
        'recibo_nomina_pdf_reparado' => ['PDF del recibo de nómina reparado', 'activo'],
        'recibo_nomina_incidencias_aplicadas' => ['Incidencias aplicadas al recibo de nómina', 'activo'],
        'acta_creada' => ['Acta administrativa creada', 'activo'],
        'acta_actualizada' => ['Acta administrativa actualizada', 'activo'],
        'acta_cerrada' => ['Acta administrativa cerrada', 'activo'],
        'acta_anexo' => ['Anexo agregado al acta', 'activo'],
        'acta_seguimiento' => ['Seguimiento del acta', 'activo'],
        'acta_documento_generado' => ['Documento del acta generado', 'activo'],
        'acta_entrega_copia' => ['Copia del acta entregada', 'activo'],
        'acta_negativa_firma' => ['Negativa de firma del acta', 'activo'],
        'induccion_asignada' => ['Inducción asignada', 'onboarding'],
        'induccion_asignacion_retirada' => ['Asignación de inducción retirada', 'onboarding'],
        'induccion_presentada_por_rh' => ['Inducción presentada por RH', 'onboarding'],
        'induccion_recalificada' => ['Evaluación de inducción recalificada', 'onboarding'],
        'reinduccion_iniciada' => ['Reinducción iniciada', 'onboarding'],
        'reinduccion_cancelada' => ['Reinducción cancelada', 'onboarding'],
        'onboarding_modulo_guardado' => ['Módulo de inducción guardado', 'onboarding'],
        'onboarding_modulos_formales_agregados' => ['Módulos formales de inducción agregados', 'onboarding'],
        'evaluacion_asignada' => ['Evaluación de periodo de prueba asignada', 'periodo_prueba'],
        'excepcion_periodo_prueba_solicitada' => ['Excepción de periodo de prueba solicitada', 'periodo_prueba'],
        'excepcion_periodo_prueba_resuelta' => ['Excepción de periodo de prueba resuelta', 'periodo_prueba'],
        'cierre_etapa_procedimiento' => ['Baja: etapa del procedimiento registrada', 'cierre'],
        'cierre_evidencia_desempeno' => ['Baja: evidencia de desempeño agregada', 'cierre'],
        'cierre_negativa_firma' => ['Baja: negativa de firma registrada', 'cierre'],
        'cierre_testigos_acta' => ['Baja: testigos del acta registrados', 'cierre'],
    ];

    /**
     * Sin permiso expreso (por omisión) no salen sueldos, ni los motivos de eventos que
     * pueden citar un importe: el evento se conserva, sin su texto.
     *
     * @return list<ItemTimeline>
     */
    public function de(Candidato|Colaborador $persona, int $limite = 300, bool $conSueldos = false, bool $conFiniquitos = false): array
    {
        [$candidatoId, $colaboradorId] = $persona instanceof Candidato
            ? [$persona->id, $persona->colaborador_id]
            : [$persona->candidato_id, $persona->id];

        $items = $this->bitacora($candidatoId, $colaboradorId, $limite, $conSueldos, $conFiniquitos);

        if ($candidatoId !== null) {
            $items = [...$items, ...$this->seguimientos($candidatoId, $limite)];
        }

        if ($colaboradorId !== null) {
            $items = [...$items, ...($conSueldos ? $this->cambiosSalario($colaboradorId, $limite) : []), ...$this->ajustesVacaciones($colaboradorId, $limite), ...$this->solicitudesInternas($colaboradorId, $limite)];
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
    private function bitacora(?int $candidatoId, ?int $colaboradorId, int $limite, bool $conSueldos, bool $conFiniquitos): array
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
                'descripcion' => $this->puedeLeerDetalle($evento, $conSueldos, $conFiniquitos) ? $this->descripcion($propiedades) : null,
                'actor' => $causer instanceof User ? $causer->nombreCompleto() : null,
                'etapa' => $etapa,
                'evento' => $evento,
            ];
        }

        return $items;
    }

    private function puedeLeerDetalle(string $evento, bool $conSueldos, bool $conFiniquitos): bool
    {
        if (str_starts_with($evento, 'finiquito')) {
            return $conFiniquitos;
        }

        return $conSueldos || preg_match(self::EVENTOS_CON_POSIBLES_IMPORTES, $evento) !== 1;
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
                // El tipo se guarda como clave del enum («rescision», o una
                // causa anterior como «baja_inmediata» en el historial): se
                // muestra su etiqueta en español.
                return $clave === 'tipo_baja' ? TipoBaja::etiquetaDeValor($p[$clave]) : $p[$clave];
            }
        }

        return null;
    }
}
