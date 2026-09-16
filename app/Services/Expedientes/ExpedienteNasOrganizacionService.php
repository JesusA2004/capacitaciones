<?php

namespace App\Services\Expedientes;

use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Reorganiza en el NAS los archivos de expediente que quedaron con la ruta
 * legacy (`expedientes/{id}/{uuid}.ext`) a la ruta legible actual que ya usa
 * `DocumentoStorageService` para las subidas nuevas
 * (`expedientes/{empresa}/{sucursal}/{numero - nombre}/{tipo} - v{n}.ext`).
 * Ver docs/ESTRUCTURA_EXPEDIENTES_NAS.md.
 *
 * Reglas de seguridad (no negociables, ver encargo de migración):
 * - Nunca sobrescribe un archivo destino existente.
 * - Nunca borra el archivo origen antes de confirmar que el destino quedó
 *   escrito correctamente (mismo hash SHA-256).
 * - Nunca borra archivos huérfanos ni filas sin archivo: solo los reporta.
 * - Cada operación aplicada queda en un manifiesto para poder auditar o
 *   revertir (rollback()).
 */
class ExpedienteNasOrganizacionService
{
    public function __construct(private readonly DocumentoStorageService $storage) {}

    /**
     * Calcula (sin tocar disco/BD, salvo lecturas de existencia) el plan de
     * migración completo: documentos de expediente + fotos de perfil.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function planificar(): Collection
    {
        /** @var Collection<int, array<string, mixed>> $documentos */
        $documentos = EmployeeDocument::withTrashed()
            ->where('disk', config('expedientes.disk'))
            ->with(['usuario.sucursalPrincipal.empresa', 'tipo'])
            ->orderBy('id')
            ->get()
            ->map(fn (EmployeeDocument $documento) => $this->planificarDocumento($documento))
            ->values();

        /** @var Collection<int, array<string, mixed>> $fotos */
        $fotos = User::withTrashed()
            ->whereNotNull('foto_path')
            ->with('sucursalPrincipal.empresa')
            ->orderBy('id')
            ->get()
            ->map(fn (User $colaborador) => $this->planificarFoto($colaborador))
            ->filter()
            ->values();

        return $documentos->concat($fotos)->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function planificarDocumento(EmployeeDocument $documento): array
    {
        if ($documento->usuario === null || $documento->tipo === null) {
            return [
                'tipo' => 'documento',
                'employee_document_id' => $documento->id,
                'user_id' => $documento->user_id,
                'old_path' => $documento->path,
                'new_path' => null,
                'accion' => 'sin_colaborador_o_tipo',
                'detalle' => 'El colaborador o el tipo de documento de esta fila ya no existen.',
            ];
        }

        $extension = $documento->extension ?: pathinfo($documento->path, PATHINFO_EXTENSION);
        $nuevaRuta = $this->storage->rutaDocumento($documento->usuario, $documento->tipo, $documento->version, $extension);

        return $this->clasificar('documento', $documento->id, $documento->user_id, $documento->path, $nuevaRuta, $documento->hash);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function planificarFoto(User $colaborador): ?array
    {
        if ($colaborador->foto_path === null) {
            return null;
        }

        $extension = pathinfo($colaborador->foto_path, PATHINFO_EXTENSION);
        $nuevaRuta = $this->storage->rutaFoto($colaborador, $extension);

        return $this->clasificar('foto', null, $colaborador->id, $colaborador->foto_path, $nuevaRuta, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function clasificar(string $tipo, ?int $documentoId, int $userId, string $rutaActual, string $rutaNueva, ?string $hashConocido): array
    {
        $base = [
            'tipo' => $tipo,
            'employee_document_id' => $documentoId,
            'user_id' => $userId,
            'old_path' => $rutaActual,
            'new_path' => $rutaNueva,
        ];

        if ($rutaActual === $rutaNueva) {
            return [...$base, 'accion' => 'sin_cambio', 'detalle' => null];
        }

        if (! $this->storage->existe($rutaActual)) {
            return [...$base, 'accion' => 'faltante', 'detalle' => 'El archivo ya no existe en el NAS en la ruta registrada.'];
        }

        $hashOrigen = $hashConocido ?? $this->storage->hashSha256($rutaActual);

        if ($this->storage->existe($rutaNueva)) {
            $hashDestino = $this->storage->hashSha256($rutaNueva);

            return $hashOrigen === $hashDestino
                ? [...$base, 'accion' => 'duplicado', 'detalle' => 'El destino ya existe con el mismo contenido (mismo SHA-256); no se toca el origen.']
                : [...$base, 'accion' => 'conflicto', 'detalle' => 'El destino ya existe con contenido DIFERENTE (SHA-256 distinto); requiere revisión manual.'];
        }

        return [...$base, 'accion' => 'mover', 'detalle' => null, 'hash_origen' => $hashOrigen];
    }

    /**
     * Ejecuta el plan. En modo dry-run (aplicar=false) no toca el disco ni
     * la BD para las acciones "mover" — solo las reporta con el resultado
     * que tendrían. Siempre regresa una fila de manifiesto por cada item del
     * plan, incluyendo los que no requieren acción.
     *
     * @param  Collection<int, array<string, mixed>>  $plan
     * @return Collection<int, array<string, mixed>>
     */
    public function ejecutar(Collection $plan, bool $aplicar): Collection
    {
        return $plan->map(function (array $item) use ($aplicar) {
            if ($item['accion'] !== 'mover') {
                return [...$item, 'resultado' => $item['accion']];
            }

            if (! $aplicar) {
                return [...$item, 'resultado' => 'pendiente_de_aplicar'];
            }

            return $this->moverUno($item);
        });
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function moverUno(array $item): array
    {
        $rutaActual = $item['old_path'];
        $rutaNueva = $item['new_path'];
        $hashOrigen = $item['hash_origen'];

        try {
            $this->storage->disco()->copy($rutaActual, $rutaNueva);

            if (! $this->storage->existe($rutaNueva)) {
                throw new RuntimeException('La copia no quedó escrita en el destino.');
            }

            $hashDestino = $this->storage->hashSha256($rutaNueva);

            if ($hashDestino !== $hashOrigen) {
                $this->storage->eliminar($rutaNueva);

                return [...$item, 'resultado' => 'error_copia', 'detalle' => 'El SHA-256 del destino no coincidió tras copiar; se eliminó la copia parcial. El origen NO se tocó.'];
            }
        } catch (Throwable $e) {
            Log::error('expedientes:organizar-nas — fallo al copiar.', ['item' => $item, 'error' => $e->getMessage()]);

            return [...$item, 'resultado' => 'error_copia', 'detalle' => $e->getMessage()];
        }

        if ($item['tipo'] === 'documento') {
            EmployeeDocument::withTrashed()->whereKey($item['employee_document_id'])->update([
                'path' => $rutaNueva,
                'stored_name' => basename($rutaNueva),
            ]);
        } else {
            User::withTrashed()->whereKey($item['user_id'])->update(['foto_path' => $rutaNueva]);
        }

        if (! $this->storage->existe($rutaNueva)) {
            // No debería poder pasar (ya se verificó arriba), pero si el NAS
            // es inconsistente entre lecturas, mejor no borrar el origen.
            return [...$item, 'resultado' => 'error_verificacion_final', 'detalle' => 'El destino dejó de existir justo antes de borrar el origen; el origen se conservó.'];
        }

        $this->storage->eliminar($rutaActual);

        Log::info('expedientes:organizar-nas — archivo reorganizado.', [
            'employee_document_id' => $item['employee_document_id'],
            'user_id' => $item['user_id'],
            'old_path' => $rutaActual,
            'new_path' => $rutaNueva,
        ]);

        return [...$item, 'resultado' => 'ok'];
    }

    /**
     * Rutas físicas bajo expedientes/ que no corresponden a ningún
     * employee_documents.path ni users.foto_path conocido (incluye
     * colaboradores/documentos con soft-delete: sus archivos siguen siendo
     * "conocidos", nunca huérfanos). Nunca se borran automáticamente.
     *
     * @return array<int, string>
     */
    public function huerfanos(): array
    {
        $conocidas = EmployeeDocument::withTrashed()->where('disk', config('expedientes.disk'))->pluck('path')
            ->concat(User::withTrashed()->whereNotNull('foto_path')->pluck('foto_path'))
            ->map(fn (string $ruta) => str_replace('\\', '/', $ruta))
            ->all();

        $conocidas = array_flip($conocidas);

        $todas = $this->storage->disco()->allFiles('expedientes');

        return array_values(array_filter($todas, fn (string $ruta) => ! isset($conocidas[str_replace('\\', '/', $ruta)])));
    }

    /**
     * Carpetas legacy `expedientes/{id}/` que quedaron completamente vacías
     * tras migrar — solo se listan/borran si de verdad no tienen ningún
     * archivo dentro (ni siquiera huérfanos).
     *
     * @return array<int, string>
     */
    public function carpetasLegacyVacias(): array
    {
        $vacias = [];

        foreach ($this->storage->disco()->directories('expedientes') as $carpeta) {
            $nombre = basename($carpeta);

            if (! ctype_digit($nombre)) {
                continue;
            }

            if ($this->storage->disco()->allFiles($carpeta) === [] && $this->storage->disco()->allDirectories($carpeta) === []) {
                $vacias[] = $carpeta;
            }
        }

        return $vacias;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $manifiesto
     */
    public function escribirManifiesto(Collection $manifiesto): string
    {
        $carpeta = storage_path('app/expedientes-migrations');

        if (! File::isDirectory($carpeta)) {
            File::makeDirectory($carpeta, 0755, true);
        }

        $ruta = $carpeta.'/'.now()->format('Y-m-d-His').'-organizacion.json';

        File::put($ruta, $manifiesto->values()->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $ruta;
    }

    /**
     * Revierte las filas con resultado="ok" de un manifiesto: copia el
     * archivo de vuelta a old_path, verifica el hash, actualiza la BD y
     * borra new_path. Nunca revierte duplicado/conflicto/faltante (esos
     * nunca movieron nada).
     *
     * @return array<int, array<string, mixed>>
     */
    public function rollback(string $rutaManifiesto): array
    {
        if (! File::exists($rutaManifiesto)) {
            throw new RuntimeException("No existe el manifiesto: {$rutaManifiesto}");
        }

        $filas = json_decode(File::get($rutaManifiesto), true, flags: JSON_THROW_ON_ERROR);
        $resultados = [];

        foreach ($filas as $fila) {
            if (($fila['resultado'] ?? null) !== 'ok') {
                continue;
            }

            $resultados[] = $this->revertirUno($fila);
        }

        return $resultados;
    }

    /**
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    private function revertirUno(array $fila): array
    {
        $rutaNueva = $fila['new_path'];
        $rutaVieja = $fila['old_path'];

        if (! $this->storage->existe($rutaNueva)) {
            return [...$fila, 'rollback' => 'error', 'rollback_detalle' => 'El archivo migrado ya no existe en new_path.'];
        }

        $this->storage->disco()->copy($rutaNueva, $rutaVieja);

        if (! $this->storage->existe($rutaVieja) || $this->storage->hashSha256($rutaVieja) !== $this->storage->hashSha256($rutaNueva)) {
            return [...$fila, 'rollback' => 'error', 'rollback_detalle' => 'La copia de vuelta no coincidió en hash; new_path se conservó intacto.'];
        }

        if ($fila['tipo'] === 'documento') {
            EmployeeDocument::withTrashed()->whereKey($fila['employee_document_id'])->update([
                'path' => $rutaVieja,
                'stored_name' => basename($rutaVieja),
            ]);
        } else {
            User::withTrashed()->whereKey($fila['user_id'])->update(['foto_path' => $rutaVieja]);
        }

        $this->storage->eliminar($rutaNueva);

        return [...$fila, 'rollback' => 'ok'];
    }
}
