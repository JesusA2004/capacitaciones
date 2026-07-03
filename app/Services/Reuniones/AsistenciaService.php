<?php

namespace App\Services\Reuniones;

use App\Enums\EstadoAsistencia;
use App\Models\Asistencia;
use App\Models\SesionEnVivo;
use App\Models\User;
use App\Notifications\SesionEnVivoProgramadaNotification;
use App\Services\Capacitacion\ProgresoService;
use Illuminate\Support\Facades\Notification;

/**
 * Asistencia a una sesion en vivo. El marcado manual del instructor y la
 * correccion auditada comparten la misma regla de finalizacion: en cuanto
 * un colaborador queda "presente"/"tarde", se completa su leccion via
 * ProgresoService (igual que el video/cuestionario/actividad en las fases
 * anteriores).
 */
class AsistenciaService
{
    public function __construct(private readonly ProgresoService $progresoService) {}

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

        $this->completarSiPresente($asistencia);
    }

    public function corregir(User $instructor, Asistencia $asistencia, EstadoAsistencia $estado, string $motivo): void
    {
        $asistencia->update([
            'estado' => $estado->value,
            'corregido_por' => $instructor->id,
            'motivo_correccion' => $motivo,
        ]);

        $this->completarSiPresente($asistencia);
    }

    private function completarSiPresente(Asistencia $asistencia): void
    {
        if (! in_array($asistencia->estado, [EstadoAsistencia::Presente, EstadoAsistencia::Tarde], true)) {
            return;
        }

        $asistencia->loadMissing(['sesion.leccion', 'usuario']);

        try {
            $this->progresoService->completarLeccion($asistencia->usuario, $asistencia->sesion->leccion);
        } catch (\RuntimeException) {
            // La leccion se bloqueo por requisitos entre la sesion y su marcado (caso raro).
        }
    }
}
