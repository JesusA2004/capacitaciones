<?php

namespace App\Services\Expedientes;

use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Relocaliza el expediente COMPLETO de un colaborador (todos sus
 * EmployeeDocument + foto de perfil) de su carpeta histórica en el NAS a la
 * que le correspondería según su empresa/sucursal/nombre ACTUALES.
 *
 * Nunca se dispara automáticamente en un cambio de sucursal/nombre/numero_empleado
 * — ver CLAUDE.md y docs/ESTRUCTURA_EXPEDIENTES_NAS.md: mientras nadie pida
 * esta relocalización explícita, el expediente sigue viviendo (correcto y
 * funcional) en su carpeta histórica, DocumentoStorageService sigue subiendo
 * ahí (ver rutaBaseColaboradorPersistida) y MR. LANA PEOPLE sigue
 * funcionando con normalidad.
 *
 * Reglas de seguridad (mismas que ExpedienteNasOrganizacionService):
 * - Nunca sobrescribe un archivo destino existente.
 * - Nunca borra el archivo origen antes de confirmar que el destino quedó
 *   escrito correctamente (mismo hash SHA-256) Y que la BD ya se actualizó.
 * - Si algo falla a medio camino, revierte las copias parciales y deja el
 *   expediente intacto en su carpeta histórica — nunca un expediente
 *   partido entre dos carpetas.
 */
class ExpedienteRelocationService
{
    public function __construct(private readonly DocumentoStorageService $storage) {}

    /**
     * @return array{movido: bool, detalle: string, archivos: int, ruta_anterior: string|null, ruta_nueva: string|null}
     */
    public function relocalizar(User $colaborador): array
    {
        $rutaVieja = $colaborador->expediente_storage_path;

        if ($rutaVieja === null || trim($rutaVieja) === '') {
            return $this->resultado(false, 'El colaborador todavía no tiene ningún archivo de expediente; no hay nada que relocalizar.', 0, null, null);
        }

        $rutaNueva = $this->storage->rutaBaseColaborador($colaborador);

        if ($rutaNueva === $rutaVieja) {
            return $this->resultado(false, 'La ruta calculada con los datos actuales del colaborador no cambió respecto a la ruta persistida.', 0, $rutaVieja, $rutaNueva);
        }

        $items = $this->construirItems($colaborador, $rutaVieja, $rutaNueva);

        if ($items === []) {
            // No hay archivos físicos bajo la ruta vieja (caso raro: la
            // ruta se asignó pero nunca se subió nada) — solo actualizamos
            // la identidad de almacenamiento, no hay nada que copiar.
            $colaborador->forceFill(['expediente_storage_path' => $rutaNueva])->save();

            return $this->resultado(true, 'No había archivos bajo la ruta anterior; se actualizó únicamente la ruta base persistida.', 0, $rutaVieja, $rutaNueva);
        }

        $copiados = $this->copiarTodos($items, $colaborador->id);

        if ($copiados === null) {
            return $this->resultado(false, 'Fallo al copiar uno o más archivos; no se tocó nada. El expediente sigue en su carpeta histórica.', 0, $rutaVieja, $rutaNueva);
        }

        if (! $this->actualizarBd($items, $colaborador, $rutaNueva)) {
            foreach ($copiados as $item) {
                $this->storage->eliminar($item['new_path']);
            }

            return $this->resultado(false, 'Fallo al actualizar la base de datos; se revirtieron las copias. El expediente sigue en su carpeta histórica.', 0, $rutaVieja, $rutaNueva);
        }

        $huerfanos = $this->borrarOrigenes($items, $colaborador->id);
        $this->podarCadenaSiVacia($rutaVieja);

        return $this->resultado(
            true,
            $huerfanos > 0
                ? "{$huerfanos} archivo(s) de origen no se pudieron borrar tras relocalizar; revisar permisos del NAS manualmente."
                : 'Expediente relocalizado correctamente.',
            count($items),
            $rutaVieja,
            $rutaNueva,
        );
    }

    /**
     * @return array<int, array{tipo: string, modelo: EmployeeDocument|null, old_path: string, new_path: string}>
     */
    private function construirItems(User $colaborador, string $rutaVieja, string $rutaNueva): array
    {
        $items = [];

        $documentos = EmployeeDocument::withTrashed()
            ->where('user_id', $colaborador->id)
            ->where('disk', config('expedientes.disk'))
            ->get();

        foreach ($documentos as $documento) {
            $items[] = [
                'tipo' => 'documento',
                'modelo' => $documento,
                'old_path' => $documento->path,
                'new_path' => $this->reubicar($documento->path, $rutaVieja, $rutaNueva),
            ];
        }

        if ($colaborador->foto_path !== null) {
            $items[] = [
                'tipo' => 'foto',
                'modelo' => null,
                'old_path' => $colaborador->foto_path,
                'new_path' => $this->reubicar($colaborador->foto_path, $rutaVieja, $rutaNueva),
            ];
        }

        return $items;
    }

    /**
     * Copia todos los archivos, verificando hash uno por uno. Si cualquiera
     * falla, revierte (borra) las copias ya hechas y regresa null: nunca
     * deja una relocalización a medias.
     *
     * @param  array<int, array{tipo: string, modelo: EmployeeDocument|null, old_path: string, new_path: string}>  $items
     * @return array<int, array{tipo: string, modelo: EmployeeDocument|null, old_path: string, new_path: string}>|null
     */
    private function copiarTodos(array $items, int $userId): ?array
    {
        $copiados = [];

        try {
            foreach ($items as $item) {
                if (! $this->storage->existe($item['old_path'])) {
                    throw new RuntimeException("Falta el archivo de origen: {$item['old_path']}");
                }

                if ($this->storage->existe($item['new_path'])) {
                    throw new RuntimeException("Ya existe un archivo en el destino: {$item['new_path']}; no se sobrescribe.");
                }

                $hashOrigen = $this->storage->hashSha256($item['old_path']);
                $this->storage->disco()->copy($item['old_path'], $item['new_path']);

                if (! $this->storage->existe($item['new_path']) || $this->storage->hashSha256($item['new_path']) !== $hashOrigen) {
                    throw new RuntimeException("La copia a {$item['new_path']} no coincidió en SHA-256.");
                }

                $copiados[] = $item;
            }
        } catch (Throwable $e) {
            foreach ($copiados as $item) {
                $this->storage->eliminar($item['new_path']);
            }

            Log::error('ExpedienteRelocationService: fallo al copiar, se revirtieron las copias parciales.', ['user_id' => $userId, 'error' => $e->getMessage()]);

            return null;
        }

        return $copiados;
    }

    /**
     * @param  array<int, array{tipo: string, modelo: EmployeeDocument|null, old_path: string, new_path: string}>  $items
     */
    private function actualizarBd(array $items, User $colaborador, string $rutaNueva): bool
    {
        try {
            DB::transaction(function () use ($items, $colaborador, $rutaNueva) {
                foreach ($items as $item) {
                    if ($item['tipo'] === 'documento') {
                        $item['modelo']?->update(['path' => $item['new_path'], 'stored_name' => basename($item['new_path'])]);
                    } else {
                        $colaborador->update(['foto_path' => $item['new_path']]);
                    }
                }

                $colaborador->forceFill(['expediente_storage_path' => $rutaNueva])->save();
            });
        } catch (Throwable $e) {
            Log::error('ExpedienteRelocationService: fallo al actualizar BD.', ['user_id' => $colaborador->id, 'error' => $e->getMessage()]);

            return false;
        }

        return true;
    }

    /**
     * @param  array<int, array{tipo: string, modelo: EmployeeDocument|null, old_path: string, new_path: string}>  $items
     */
    private function borrarOrigenes(array $items, int $userId): int
    {
        foreach ($items as $item) {
            $this->storage->eliminar($item['old_path']);
        }

        $huerfanos = array_values(array_filter($items, fn (array $item) => $this->storage->existe($item['old_path'])));

        if ($huerfanos !== []) {
            Log::warning('ExpedienteRelocationService: algunos archivos de origen no se pudieron borrar tras relocalizar.', [
                'user_id' => $userId,
                'archivos' => array_column($huerfanos, 'old_path'),
            ]);
        }

        return count($huerfanos);
    }

    private function reubicar(string $rutaOriginal, string $baseVieja, string $baseNueva): string
    {
        return Str::startsWith($rutaOriginal, $baseVieja.'/')
            ? $baseNueva.substr($rutaOriginal, strlen($baseVieja))
            : $baseNueva.'/'.basename($rutaOriginal);
    }

    /**
     * Poda la carpeta del colaborador y, si también quedan vacías, la de
     * sucursal y empresa — nunca toca `expedientes/` en sí. Solo mira
     * allFiles() (recursivo): si ya no queda ningún archivo bajo la
     * carpeta, es seguro deleteDirectory() aunque queden subcarpetas vacías
     * colgando (p. ej. .../foto/ tras borrar la foto de perfil) — a
     * diferencia de ExpedienteNasOrganizacionService::carpetasLegacyVacias(),
     * que exige "sin archivos y sin subdirectorios" porque ahí cualquier
     * subcarpeta inesperada podría ser un huérfano real sin catalogar.
     */
    private function podarCadenaSiVacia(string $rutaColaborador): void
    {
        $carpeta = $rutaColaborador;

        while ($carpeta !== 'expedientes' && $carpeta !== '.' && $carpeta !== '') {
            if ($this->storage->disco()->allFiles($carpeta) !== []) {
                return;
            }

            $this->storage->disco()->deleteDirectory($carpeta);
            $carpeta = dirname($carpeta);
        }
    }

    /**
     * @return array{movido: bool, detalle: string, archivos: int, ruta_anterior: string|null, ruta_nueva: string|null}
     */
    private function resultado(bool $movido, string $detalle, int $archivos, ?string $rutaAnterior, ?string $rutaNueva): array
    {
        return [
            'movido' => $movido,
            'detalle' => $detalle,
            'archivos' => $archivos,
            'ruta_anterior' => $rutaAnterior,
            'ruta_nueva' => $rutaNueva,
        ];
    }
}
