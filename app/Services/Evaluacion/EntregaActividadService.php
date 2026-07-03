<?php

namespace App\Services\Evaluacion;

use App\Enums\EstadoEntregaActividad;
use App\Enums\EstadoMultimedia;
use App\Enums\OrigenRecursoMultimedia;
use App\Enums\TipoEntregaActividad;
use App\Enums\TipoRecursoMultimedia;
use App\Enums\VisibilidadRecursoMultimedia;
use App\Models\Actividad;
use App\Models\EntregaActividad;
use App\Models\RecursoMultimedia;
use App\Models\User;
use App\Notifications\ActividadCalificadaNotification;
use App\Services\Capacitacion\ProgresoService;
use App\Services\Multimedia\MediaStorageService;
use Illuminate\Http\UploadedFile;

/**
 * Entregas de actividad: cada reenvio (tras un rechazo) crea una fila nueva
 * con version incrementada, la propia tabla es su historial. Los archivos
 * entregados se guardan reutilizando RecursoMultimedia/MediaStorageService
 * (el mismo disco 'nas' y la misma abstraccion de almacenamiento que la
 * biblioteca multimedia de la Fase 3), en vez de duplicar la logica de
 * guardado de archivos.
 */
class EntregaActividadService
{
    public function __construct(
        private readonly ProgresoService $progresoService,
        private readonly MediaStorageService $storage,
    ) {}

    public function ultimaEntrega(User $usuario, Actividad $actividad): ?EntregaActividad
    {
        return EntregaActividad::query()
            ->where('actividad_id', $actividad->id)
            ->where('user_id', $usuario->id)
            ->orderByDesc('version')
            ->first();
    }

    public function puedeEntregar(User $usuario, Actividad $actividad): bool
    {
        $ultima = $this->ultimaEntrega($usuario, $actividad);

        return ! $ultima || $ultima->estado === EstadoEntregaActividad::Rechazada;
    }

    /**
     * @param  array{contenido_texto?: string|null, url?: string|null, archivo?: UploadedFile|null}  $datos
     *
     * @throws \RuntimeException si ya existe una entrega vigente para esta actividad
     */
    public function entregar(User $usuario, Actividad $actividad, array $datos): EntregaActividad
    {
        if (! $this->puedeEntregar($usuario, $actividad)) {
            throw new \RuntimeException('Ya tienes una entrega vigente para esta actividad.');
        }

        $ultimaEntrega = $this->ultimaEntrega($usuario, $actividad);
        $version = ($ultimaEntrega->version ?? 0) + 1;

        $recursoMultimediaId = null;

        if ($actividad->tipo_entrega === TipoEntregaActividad::Archivo && isset($datos['archivo'])) {
            $recursoMultimediaId = $this->guardarArchivo($usuario, $datos['archivo']);
        }

        return EntregaActividad::create([
            'actividad_id' => $actividad->id,
            'user_id' => $usuario->id,
            'version' => $version,
            'contenido_texto' => $datos['contenido_texto'] ?? null,
            'url' => $datos['url'] ?? null,
            'recurso_multimedia_id' => $recursoMultimediaId,
            'estado' => EstadoEntregaActividad::Entregada->value,
            'entregado_en' => now(),
        ]);
    }

    public function calificar(EntregaActividad $entrega, bool $aprobada, ?int $calificacion, ?string $retroalimentacion, User $calificador): void
    {
        $entrega->update([
            'estado' => $aprobada ? EstadoEntregaActividad::Aprobada->value : EstadoEntregaActividad::Rechazada->value,
            'calificacion' => $calificacion,
            'retroalimentacion' => $retroalimentacion,
            'calificado_en' => now(),
            'calificado_por' => $calificador->id,
        ]);

        $entrega->loadMissing(['actividad.leccion', 'usuario']);

        if ($aprobada) {
            try {
                $this->progresoService->completarLeccion($entrega->usuario, $entrega->actividad->leccion);
            } catch (\RuntimeException) {
                // La leccion se bloqueo por requisitos entre la entrega y su calificacion (caso raro).
            }
        }

        $entrega->usuario->notify(new ActividadCalificadaNotification($entrega));
    }

    private function guardarArchivo(User $usuario, UploadedFile $archivo): int
    {
        $nombreInterno = $this->storage->nombreInterno($archivo->getClientOriginalName());
        $ruta = $this->storage->rutaDocumento($nombreInterno);
        $this->storage->guardar($archivo, $ruta);

        $recurso = RecursoMultimedia::create([
            'tipo' => TipoRecursoMultimedia::Documento->value,
            'nombre_original' => $archivo->getClientOriginalName(),
            'nombre_interno' => $nombreInterno,
            'disco' => config('media.disk'),
            'ruta_original' => $ruta,
            'mime_type' => $archivo->getMimeType(),
            'tamano_bytes' => $archivo->getSize(),
            'estado' => EstadoMultimedia::Disponible->value,
            'subido_por' => $usuario->id,
            'origen' => OrigenRecursoMultimedia::Actividad->value,
            'visibilidad' => VisibilidadRecursoMultimedia::Restringida->value,
            'propietario_id' => $usuario->id,
            'acceso_restringido' => true,
        ]);

        return $recurso->id;
    }
}
