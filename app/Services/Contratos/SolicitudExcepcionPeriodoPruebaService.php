<?php

namespace App\Services\Contratos;

use App\Enums\EstadoExcepcionDireccionGeneral;
use App\Enums\PrioridadTarea;
use App\Enums\TipoTarea;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\SolicitudExcepcionPeriodoPrueba;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Solicitudes\SolicitudDocumentoStorageService;
use App\Services\Tareas\NotificadorRhService;
use App\Services\Tareas\TareaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Excepción a la regla canónica de renovación del periodo de prueba
 * (FO-RH-EPP-001, nota al pie: "toda excepción requiere autorización
 * escrita de Dirección General"). Nunca un botón "forzar aprobado" en la
 * pantalla de RH — esta es la única puerta, y solo Dirección General
 * (permiso 'excepciones.autorizar') la resuelve. Nunca Regional ni
 * Dirección Comercial.
 */
class SolicitudExcepcionPeriodoPruebaService
{
    public const PERMISO_AUTORIZAR = 'excepciones.autorizar';

    public function __construct(
        private readonly TareaService $tareas,
        private readonly NotificadorRhService $notificador,
        private readonly AuditoriaService $auditoria,
        private readonly SolicitudDocumentoStorageService $almacenamiento,
    ) {}

    /**
     * @param  array<string, mixed>  $datos  motivo (validado por CrearSolicitudExcepcionRequest).
     */
    public function crear(EvaluacionPeriodoPrueba $evaluacion, User $solicitante, array $datos, ?UploadedFile $documentoSoporte = null): SolicitudExcepcionPeriodoPrueba
    {
        if ($evaluacion->solicitudesExcepcion->contains(fn (SolicitudExcepcionPeriodoPrueba $s) => $s->estado === EstadoExcepcionDireccionGeneral::Pendiente)) {
            throw ValidationException::withMessages(['estado' => 'Ya hay una excepción pendiente para esta evaluación.']);
        }

        $solicitud = DB::transaction(function () use ($evaluacion, $solicitante, $datos, $documentoSoporte): SolicitudExcepcionPeriodoPrueba {
            $solicitud = SolicitudExcepcionPeriodoPrueba::query()->create([
                'evaluacion_periodo_prueba_id' => $evaluacion->id,
                'motivo' => trim((string) $datos['motivo']),
                'solicitante_id' => $solicitante->id,
                'estado' => EstadoExcepcionDireccionGeneral::Pendiente,
            ]);

            if ($documentoSoporte !== null) {
                $nombreInterno = $this->almacenamiento->nombreInterno($documentoSoporte->getClientOriginalName());
                $ruta = $this->almacenamiento->guardar($documentoSoporte, "excepciones-periodo-prueba/{$solicitud->id}/{$nombreInterno}");
                $solicitud->update(['documento_soporte_path' => $ruta]);
            }

            return $solicitud;
        });

        $solicitud->loadMissing('evaluacion.colaborador');
        $nombre = $solicitud->evaluacion->colaborador->nombreCompleto();

        $this->tareas->abrir(TipoTarea::ExcepcionPeriodoPruebaPendiente, $solicitud, [
            'titulo' => "Excepción de renovación por autorizar: {$nombre}",
            'descripcion' => $solicitud->motivo,
            'prioridad' => PrioridadTarea::Alta,
            'colaborador' => $solicitud->evaluacion->colaborador,
            'permiso' => self::PERMISO_AUTORIZAR,
            'accion' => 'resolver_excepcion',
        ]);

        $this->notificador->notificarEvento(
            'excepcion_periodo_prueba_solicitada',
            $solicitud->evaluacion->colaborador,
            ['creador' => $solicitante, 'excluir' => [$solicitante->id]],
            'Excepción de renovación por autorizar',
            "{$nombre}: {$solicitud->motivo}",
            $solicitud,
            'resolver_excepcion',
            'alta',
        );

        $this->auditoria->registrar('excepcion_periodo_prueba_solicitada', $solicitud, $solicitante, ['evaluacion_id' => $evaluacion->id]);

        return $solicitud;
    }

    public function resolver(SolicitudExcepcionPeriodoPrueba $solicitud, User $actor, bool $aprobar, ?string $comentario = null): SolicitudExcepcionPeriodoPrueba
    {
        $solicitud = DB::transaction(function () use ($solicitud, $actor, $aprobar, $comentario): SolicitudExcepcionPeriodoPrueba {
            $solicitud = SolicitudExcepcionPeriodoPrueba::query()->lockForUpdate()->findOrFail($solicitud->id);

            if ($solicitud->estado !== EstadoExcepcionDireccionGeneral::Pendiente) {
                throw ValidationException::withMessages(['estado' => 'Esta excepción ya fue resuelta.']);
            }

            $solicitud->update([
                'estado' => $aprobar ? EstadoExcepcionDireccionGeneral::Aprobada : EstadoExcepcionDireccionGeneral::Rechazada,
                'resuelto_por' => $actor->id,
                'resuelto_en' => now(),
                'comentario_resolucion' => $comentario,
            ]);

            return $solicitud;
        });

        $solicitud->loadMissing(['evaluacion.colaborador', 'solicitante']);
        $this->tareas->resolver(TipoTarea::ExcepcionPeriodoPruebaPendiente, $solicitud, $actor);

        $this->notificador->notificarEvento(
            'excepcion_periodo_prueba_resuelta',
            $solicitud->evaluacion->colaborador,
            ['creador' => $solicitud->solicitante, 'excluir' => [$actor->id]],
            $aprobar ? 'Excepción aprobada por Dirección General' : 'Excepción rechazada por Dirección General',
            $comentario ?? ($aprobar ? 'Ya puedes autorizar la renovación.' : 'La evaluación se mantiene como no renueva.'),
            $solicitud,
            'ver_evaluacion',
        );

        $this->auditoria->registrar('excepcion_periodo_prueba_resuelta', $solicitud, $actor, ['aprobar' => $aprobar]);

        return $solicitud;
    }

    /**
     * @return array<string, mixed>
     */
    public function aArray(SolicitudExcepcionPeriodoPrueba $solicitud): array
    {
        return [
            'id' => $solicitud->id,
            'evaluacion_id' => $solicitud->evaluacion_periodo_prueba_id,
            'motivo' => $solicitud->motivo,
            'tiene_documento_soporte' => $solicitud->documento_soporte_path !== null,
            'solicitante' => $solicitud->solicitante->name,
            'estado' => $solicitud->estado->value,
            'estado_etiqueta' => $solicitud->estado->etiqueta(),
            'resuelto_en' => $solicitud->resuelto_en?->toIso8601String(),
            'comentario_resolucion' => $solicitud->comentario_resolucion,
            'creado_en' => $solicitud->created_at?->toIso8601String(),
        ];
    }
}
