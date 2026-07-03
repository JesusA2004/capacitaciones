<?php

namespace App\Services\Multimedia;

use App\Enums\EstadoCargaMultimedia;
use App\Enums\EstadoMultimedia;
use App\Enums\OrigenRecursoMultimedia;
use App\Enums\TipoRecursoMultimedia;
use App\Enums\VisibilidadRecursoMultimedia;
use App\Jobs\ProcesarVideoJob;
use App\Models\CargaMultimedia;
use App\Models\RecursoMultimedia;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Carga de video por bloques reanudable (Fase 9, ver
 * docs/AUDITORIA_CUMPLIMIENTO.md sección 7). Antes de esta fase
 * `cargas_multimedia` existía en el esquema pero ningún código la usaba: la
 * carga era un único request con el archivo completo.
 *
 * Cada bloque se recibe y se guarda como un archivo independiente numerado
 * (MediaStorageService::rutaBloque); el ensamblado final concatena los
 * bloques en orden solo cuando todos llegaron. Esto permite recibir bloques
 * fuera de orden, reintentar uno solo, y pausar/reanudar sin perder lo ya
 * recibido.
 */
class CargaResumibleService
{
    public function __construct(private readonly MediaStorageService $storage) {}

    /**
     * @param  array{nombre_original: string, tipo: string, tamano_total_bytes: int, hash_esperado?: string|null}  $datos
     */
    public function iniciar(User $usuario, array $datos): CargaMultimedia
    {
        $cargaExistente = CargaMultimedia::query()
            ->where('user_id', $usuario->id)
            ->where('nombre_original', $datos['nombre_original'])
            ->where('tamano_total_bytes', $datos['tamano_total_bytes'])
            ->whereIn('estado', [EstadoCargaMultimedia::EnProgreso->value, EstadoCargaMultimedia::Pausada->value])
            ->first();

        if ($cargaExistente) {
            // Misma carga (mismo nombre + tamaño) que ya estaba en progreso:
            // se reanuda en vez de duplicarla, para que recargar la página o
            // reabrir el diálogo de carga no genere una sesión nueva.
            return $cargaExistente;
        }

        $tamanoBloque = config('media.carga_resumible.tamano_bloque_mb') * 1024 * 1024;
        $totalBloques = (int) ceil($datos['tamano_total_bytes'] / $tamanoBloque);

        return CargaMultimedia::create([
            'identificador' => (string) Str::uuid(),
            'user_id' => $usuario->id,
            'nombre_original' => $datos['nombre_original'],
            'tipo' => $datos['tipo'],
            'tamano_total_bytes' => $datos['tamano_total_bytes'],
            'tamano_bloque_bytes' => $tamanoBloque,
            'total_bloques' => $totalBloques,
            'bytes_recibidos' => 0,
            'bloques_recibidos' => [],
            'hash_esperado' => $datos['hash_esperado'] ?? null,
            'estado' => EstadoCargaMultimedia::EnProgreso->value,
            'expira_en' => now()->addHours((int) config('media.carga_resumible.expira_horas')),
        ]);
    }

    /**
     * @throws \RuntimeException si la carga no admite bloques (pausada, cancelada, expirada...)
     *                           o si el número de bloque no corresponde a esta carga
     */
    public function recibirBloque(CargaMultimedia $carga, int $numeroBloque, UploadedFile $bloque): CargaMultimedia
    {
        if ($carga->estado !== EstadoCargaMultimedia::EnProgreso) {
            throw new \RuntimeException('Esta carga no admite bloques nuevos en su estado actual ('.$carga->estado->etiqueta().').');
        }

        if ($carga->total_bloques === null || $numeroBloque < 0 || $numeroBloque >= $carga->total_bloques) {
            throw new \RuntimeException('Número de bloque fuera de rango para esta carga.');
        }

        $yaRecibido = in_array($numeroBloque, $carga->bloques_recibidos ?? [], true);

        $this->storage->guardar($bloque, $this->storage->rutaBloque($carga->identificador, $numeroBloque));

        if (! $yaRecibido) {
            $bloquesRecibidos = [...($carga->bloques_recibidos ?? []), $numeroBloque];
            sort($bloquesRecibidos);

            $carga->update([
                'bloques_recibidos' => $bloquesRecibidos,
                'bytes_recibidos' => $carga->bytes_recibidos + $bloque->getSize(),
            ]);
        }

        if ($carga->estaCompleta()) {
            return $this->ensamblar($carga->fresh());
        }

        return $carga->fresh();
    }

    public function pausar(CargaMultimedia $carga): CargaMultimedia
    {
        if ($carga->estado === EstadoCargaMultimedia::EnProgreso) {
            $carga->update(['estado' => EstadoCargaMultimedia::Pausada->value]);
        }

        return $carga->fresh();
    }

    public function reanudar(CargaMultimedia $carga): CargaMultimedia
    {
        if ($carga->estado === EstadoCargaMultimedia::Pausada) {
            $carga->update([
                'estado' => EstadoCargaMultimedia::EnProgreso->value,
                'expira_en' => now()->addHours((int) config('media.carga_resumible.expira_horas')),
            ]);
        }

        return $carga->fresh();
    }

    public function cancelar(CargaMultimedia $carga): CargaMultimedia
    {
        $this->storage->eliminarCarpeta($this->storage->rutaCargaTemporal($carga->identificador));
        $carga->update(['estado' => EstadoCargaMultimedia::Cancelada->value]);

        return $carga->fresh();
    }

    /**
     * Marca como expiradas las cargas abandonadas (sin completarse antes de
     * `expira_en`) y limpia sus bloques temporales. Pensado para el
     * Scheduler (política de retención), no para el flujo normal del
     * usuario.
     */
    public function limpiarExpiradas(): int
    {
        $expiradas = CargaMultimedia::query()
            ->whereNotIn('estado', [
                EstadoCargaMultimedia::Completada->value,
                EstadoCargaMultimedia::Cancelada->value,
                EstadoCargaMultimedia::Expirada->value,
            ])
            ->where('expira_en', '<', now())
            ->get();

        foreach ($expiradas as $carga) {
            $this->storage->eliminarCarpeta($this->storage->rutaCargaTemporal($carga->identificador));
            $carga->update(['estado' => EstadoCargaMultimedia::Expirada->value]);
        }

        return $expiradas->count();
    }

    private function ensamblar(CargaMultimedia $carga): CargaMultimedia
    {
        $carga->update(['estado' => EstadoCargaMultimedia::Ensamblando->value]);

        $nombreInterno = $this->storage->nombreInterno($carga->nombre_original);
        $rutaDestino = $this->storage->rutaOriginal($nombreInterno);

        try {
            $this->storage->ensamblarBloques($carga->identificador, $carga->total_bloques, $rutaDestino);

            $tamanoFinal = $this->storage->tamano($rutaDestino);

            if ($tamanoFinal !== $carga->tamano_total_bytes) {
                throw new \RuntimeException("El tamaño ensamblado ({$tamanoFinal} bytes) no coincide con el esperado ({$carga->tamano_total_bytes} bytes).");
            }

            $hashCalculado = $this->storage->hashSha256($rutaDestino);

            if ($carga->hash_esperado && ! hash_equals(strtolower($carga->hash_esperado), $hashCalculado)) {
                throw new \RuntimeException('El hash del archivo ensamblado no coincide con el hash esperado; la carga pudo corromperse en tránsito.');
            }
        } catch (\RuntimeException $excepcion) {
            $this->storage->eliminar($rutaDestino);
            $carga->update(['estado' => EstadoCargaMultimedia::Error->value, 'error' => $excepcion->getMessage()]);

            return $carga->fresh();
        }

        $recurso = RecursoMultimedia::create([
            'tipo' => $carga->tipo->value,
            'nombre_original' => $carga->nombre_original,
            'nombre_interno' => $nombreInterno,
            'disco' => config('media.disk'),
            'ruta_original' => $rutaDestino,
            'tamano_bytes' => $tamanoFinal,
            'hash_sha256' => $hashCalculado,
            'estado' => $carga->tipo === TipoRecursoMultimedia::Video ? EstadoMultimedia::Pendiente->value : EstadoMultimedia::Disponible->value,
            'subido_por' => $carga->user_id,
            'origen' => OrigenRecursoMultimedia::Biblioteca->value,
            'visibilidad' => VisibilidadRecursoMultimedia::Publica->value,
            'acceso_restringido' => false,
        ]);

        if ($carga->tipo === TipoRecursoMultimedia::Video) {
            ProcesarVideoJob::dispatch($recurso);
        }

        $this->storage->eliminarCarpeta($this->storage->rutaCargaTemporal($carga->identificador));

        $carga->update([
            'estado' => EstadoCargaMultimedia::Completada->value,
            'hash_calculado' => $hashCalculado,
            'recurso_multimedia_id' => $recurso->id,
        ]);

        return $carga->fresh();
    }
}
