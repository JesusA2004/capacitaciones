<?php

namespace App\Services\Capacitacion;

use App\Enums\EstadoAsignacion;
use App\Enums\EstadoProgreso;
use App\Models\AsignacionUsuario;
use App\Models\Curso;
use App\Models\InscripcionCurso;
use App\Models\Leccion;
use App\Models\ProgresoLeccion;
use App\Models\User;
use App\Services\Certificados\CertificadoService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Progreso de un colaborador dentro de un curso: inscripcion, bloqueo de
 * lecciones por requisitos previos (grafo explicito y/o orden secuencial
 * cuando el curso lo exige) y finalizacion del curso.
 *
 * Nota: para lecciones de tipo video, esta fase solo ofrece una finalizacion
 * manual ("marcar como completada"). La validacion respaldada por el
 * servidor contra la reproduccion real (heartbeats, segundos unicos vistos)
 * se implementa en la Fase 3 junto con el reproductor y sustituira esta
 * finalizacion manual especificamente para lecciones de video.
 */
class ProgresoService
{
    /**
     * Cache en memoria (por instancia, por usuario) de los IDs de lecciones
     * ya completadas. estadoBloqueoLeccion() y recalcularInscripcion() llaman
     * a leccionCompletada() una vez por leccion (requisitos, orden
     * secuencial, verificacion de todas las obligatorias); sin este cache,
     * una pantalla con muchas lecciones dispara una consulta nueva por cada
     * una (N+1). completarLeccion() actualiza este cache al vuelo para que
     * siga siendo correcto dentro de la misma peticion.
     *
     * @var array<int, Collection<int, int>>
     */
    private array $idsLeccionesCompletadasCache = [];

    public function __construct(private readonly CertificadoService $certificadoService) {}

    public function inscribir(User $usuario, Curso $curso, ?AsignacionUsuario $asignacionUsuario = null): InscripcionCurso
    {
        return InscripcionCurso::firstOrCreate(
            ['user_id' => $usuario->id, 'curso_id' => $curso->id],
            ['asignacion_usuario_id' => $asignacionUsuario?->id, 'estado' => EstadoProgreso::Pendiente->value],
        );
    }

    /**
     * @return array{bloqueada: bool, motivo: string|null}
     */
    public function estadoBloqueoLeccion(User $usuario, Leccion $leccion): array
    {
        $leccion->loadMissing(['requisitos', 'modulo.curso.modulos.lecciones']);

        foreach ($leccion->requisitos as $requisito) {
            if (! $this->leccionCompletada($usuario, $requisito)) {
                return ['bloqueada' => true, 'motivo' => "Debes completar antes: «{$requisito->titulo}»."];
            }
        }

        $curso = $leccion->modulo->curso;

        if ($curso->requiere_orden) {
            $anterior = $this->leccionObligatoriaAnterior($curso, $leccion);

            if ($anterior && ! $this->leccionCompletada($usuario, $anterior)) {
                return ['bloqueada' => true, 'motivo' => "Debes completar antes: «{$anterior->titulo}»."];
            }
        }

        return ['bloqueada' => false, 'motivo' => null];
    }

    public function leccionCompletada(User $usuario, Leccion $leccion): bool
    {
        return $this->idsLeccionesCompletadas($usuario)->contains($leccion->id);
    }

    /**
     * @return Collection<int, int>
     */
    private function idsLeccionesCompletadas(User $usuario): Collection
    {
        return $this->idsLeccionesCompletadasCache[$usuario->id] ??= ProgresoLeccion::query()
            ->where('user_id', $usuario->id)
            ->where('estado', EstadoProgreso::Completada->value)
            ->pluck('leccion_id');
    }

    /**
     * Verificacion compartida por los controladores de "Mi capacitación"
     * (reproductor de video, intentos de cuestionario, entregas de
     * actividad): 403 si el curso no esta asignado al usuario o si la
     * leccion sigue bloqueada por requisitos previos.
     */
    public function autorizarAccesoLeccion(User $usuario, Leccion $leccion): void
    {
        $curso = $leccion->modulo->curso;
        $tieneAcceso = $usuario->inscripcionesCurso()->where('curso_id', $curso->id)->exists();

        abort_unless($tieneAcceso, 403, 'No tienes esta lección asignada.');

        $bloqueo = $this->estadoBloqueoLeccion($usuario, $leccion);
        abort_if($bloqueo['bloqueada'], 403, $bloqueo['motivo'] ?? 'Esta lección está bloqueada.');
    }

    /**
     * @throws \RuntimeException cuando la leccion esta bloqueada por requisitos previos
     */
    public function completarLeccion(User $usuario, Leccion $leccion): ProgresoLeccion
    {
        $bloqueo = $this->estadoBloqueoLeccion($usuario, $leccion);

        if ($bloqueo['bloqueada']) {
            throw new \RuntimeException($bloqueo['motivo'] ?? 'Esta lección está bloqueada.');
        }

        $progreso = ProgresoLeccion::updateOrCreate(
            ['user_id' => $usuario->id, 'leccion_id' => $leccion->id],
            ['estado' => EstadoProgreso::Completada->value, 'completado_en' => now()],
        );

        $idsCompletadas = $this->idsLeccionesCompletadas($usuario);

        if (! $idsCompletadas->contains($leccion->id)) {
            $idsCompletadas->push($leccion->id);
        }

        $curso = $leccion->modulo->curso;
        $this->inscribir($usuario, $curso);
        $this->recalcularInscripcion($usuario, $curso);

        return $progreso;
    }

    public function recalcularInscripcion(User $usuario, Curso $curso): void
    {
        $curso->loadMissing('modulos.lecciones');

        $leccionesObligatorias = $curso->modulos->flatMap(fn ($modulo) => $modulo->lecciones)->where('obligatoria', true);

        $completadas = $leccionesObligatorias->every(fn (Leccion $leccion) => $this->leccionCompletada($usuario, $leccion));

        if (! $completadas || $leccionesObligatorias->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($usuario, $curso) {
            $inscripcion = $this->inscribir($usuario, $curso);

            if ($inscripcion->estado === EstadoProgreso::Completada) {
                return;
            }

            $inscripcion->update(['estado' => EstadoProgreso::Completada->value, 'completado_en' => now()]);

            AsignacionUsuario::query()
                ->where('user_id', $usuario->id)
                ->whereHas('asignacion', fn ($query) => $query->where('asignable_type', Curso::class)->where('asignable_id', $curso->id))
                ->where('estado', '!=', EstadoAsignacion::Cancelada->value)
                ->update(['estado' => EstadoAsignacion::Completada->value, 'completado_en' => now()]);

            $this->certificadoService->emitirSiAplica($inscripcion);
        });
    }

    /**
     * Busca la ultima leccion obligatoria que precede a la indicada dentro
     * de la secuencia completa del curso (todos los modulos, en orden).
     */
    private function leccionObligatoriaAnterior(Curso $curso, Leccion $leccion): ?Leccion
    {
        $secuencia = $curso->modulos->flatMap(fn ($modulo) => $modulo->lecciones);

        $anteriores = $secuencia->takeWhile(fn (Leccion $item) => $item->id !== $leccion->id);

        return $anteriores->where('obligatoria', true)->last();
    }
}
