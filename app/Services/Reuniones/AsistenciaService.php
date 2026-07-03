<?php

namespace App\Services\Reuniones;

use App\Enums\EstadoAsistencia;
use App\Enums\EstadoMultimedia;
use App\Enums\OrigenRecursoMultimedia;
use App\Enums\TipoRecursoMultimedia;
use App\Enums\VisibilidadRecursoMultimedia;
use App\Models\Asistencia;
use App\Models\RecursoMultimedia;
use App\Models\SesionEnVivo;
use App\Models\User;
use App\Notifications\SesionEnVivoProgramadaNotification;
use App\Services\Capacitacion\ProgresoService;
use App\Services\Multimedia\MediaStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;

/**
 * Asistencia a una sesion en vivo. El marcado manual del instructor, la
 * corrección auditada y el resultado de la sincronización automática
 * (Fase 9, ver App\Jobs\CalcularAsistenciasSesionJob) comparten la misma
 * regla de finalización: en cuanto un colaborador queda en un estado que
 * cuenta como asistencia (EstadoAsistencia::completaLeccion()), se completa
 * su lección vía ProgresoService.
 */
class AsistenciaService
{
    public function __construct(
        private readonly ProgresoService $progresoService,
        private readonly MediaStorageService $storage,
    ) {}

    public function materializarParaSesion(SesionEnVivo $sesion): void
    {
        $sesion->loadMissing('leccion.modulo.curso');
        $curso = $sesion->leccion->modulo->curso;

        $usuarios = User::query()
            ->whereHas('inscripcionesCurso', fn ($query) => $query->where('curso_id', $curso->id))
            ->get();

        $usuariosNotificar = [];

        foreach ($usuarios as $usuario) {
            $asistencia = Asistencia::firstOrCreate(
                ['sesion_en_vivo_id' => $sesion->id, 'user_id' => $usuario->id],
                ['estado' => EstadoAsistencia::Pendiente->value],
            );

            if ($asistencia->wasRecentlyCreated) {
                $usuariosNotificar[] = $usuario;
            }
        }

        if ($usuariosNotificar !== []) {
            Notification::send($usuariosNotificar, new SesionEnVivoProgramadaNotification($sesion));
        }
    }

    public function marcarManual(Asistencia $asistencia, EstadoAsistencia $estado): void
    {
        $asistencia->update(['estado' => $estado->value]);

        $this->completarSiCorresponde($asistencia);
    }

    /**
     * Corrección manual auditada de una asistencia que ya tenía un estado
     * definitivo (incluyendo una ya calculada automáticamente). Registra
     * estado/minutos anteriores y nuevos, motivo obligatorio, evidencia
     * opcional, IP, user-agent y origen — ver
     * docs/AUDITORIA_CUMPLIMIENTO.md sección 6.
     */
    public function corregir(
        User $instructor,
        Asistencia $asistencia,
        EstadoAsistencia $estadoNuevo,
        string $motivo,
        ?int $minutosNuevos,
        ?UploadedFile $evidencia,
        string $ip,
        string $userAgent,
        string $origen = 'manual',
    ): void {
        $evidenciaId = $evidencia ? (string) $this->guardarEvidencia($instructor, $evidencia)->id : null;

        $asistencia->update([
            'estado_anterior' => $asistencia->estado->value,
            'minutos_anteriores' => $asistencia->minutos_totales,
            'estado' => $estadoNuevo->value,
            'minutos_totales' => $minutosNuevos ?? $asistencia->minutos_totales,
            'corregido_por' => $instructor->id,
            'motivo_correccion' => $motivo,
            'evidencia_correccion' => $evidenciaId,
            'correccion_ip' => $ip,
            'correccion_user_agent' => mb_substr($userAgent, 0, 255),
            'correccion_origen' => $origen,
        ]);

        $this->completarSiCorresponde($asistencia);
    }

    private function completarSiCorresponde(Asistencia $asistencia): void
    {
        if (! $asistencia->estado->completaLeccion()) {
            return;
        }

        $asistencia->loadMissing(['sesion.leccion', 'usuario']);

        try {
            $this->progresoService->completarLeccion($asistencia->usuario, $asistencia->sesion->leccion);
        } catch (\RuntimeException) {
            // La leccion se bloqueo por requisitos entre la sesion y su marcado (caso raro).
        }
    }

    private function guardarEvidencia(User $instructor, UploadedFile $archivo): RecursoMultimedia
    {
        $nombreInterno = $this->storage->nombreInterno($archivo->getClientOriginalName());
        $ruta = $this->storage->rutaDocumento($nombreInterno);
        $this->storage->guardar($archivo, $ruta);

        return RecursoMultimedia::create([
            'tipo' => TipoRecursoMultimedia::Documento->value,
            'nombre_original' => $archivo->getClientOriginalName(),
            'nombre_interno' => $nombreInterno,
            'disco' => config('media.disk'),
            'ruta_original' => $ruta,
            'mime_type' => $archivo->getMimeType(),
            'tamano_bytes' => $archivo->getSize(),
            'estado' => EstadoMultimedia::Disponible->value,
            'subido_por' => $instructor->id,
            'origen' => OrigenRecursoMultimedia::Sistema->value,
            'visibilidad' => VisibilidadRecursoMultimedia::Restringida->value,
            'propietario_id' => $instructor->id,
            'acceso_restringido' => true,
        ]);
    }
}
