<?php

namespace App\Services\Notificaciones;

use App\Models\User;
use App\Support\Paginacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Listado del módulo «Notificaciones»: por defecto las del usuario; con
 * notificaciones.ver_todas, las de todo el equipo (solo lectura). Cada fila
 * dice si es propia: solo las propias se pueden marcar como leídas.
 */
class ConsultaNotificacionesService
{
    /**
     * @param  array{estado?: string|null, usuario_id?: int|null, per_page?: mixed}  $filtros
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function listar(array $filtros, User $actual): LengthAwarePaginator
    {
        $usuarioId = $filtros['usuario_id'] ?? null;

        $paginador = DatabaseNotification::query()
            ->where('notifiable_type', (new User)->getMorphClass())
            ->when($usuarioId !== null, fn (Builder $q) => $q->where('notifiable_id', $usuarioId))
            ->when(($filtros['estado'] ?? null) === 'no_leidas', fn (Builder $q) => $q->whereNull('read_at'))
            ->orderByDesc('created_at')
            ->paginate(Paginacion::porPagina(30, $filtros['per_page'] ?? null));

        $notificaciones = $paginador->getCollection();
        $usuarios = User::query()->whereIn('id', $notificaciones->pluck('notifiable_id')->all())->get(['id', 'name', 'apellidos'])->keyBy('id');

        $filas = $notificaciones->map(fn (DatabaseNotification $n): array => $this->fila($n, $usuarios->get($n->notifiable_id), $actual));

        return new LengthAwarePaginator(
            $filas->values(),
            $paginador->total(),
            $paginador->perPage(),
            $paginador->currentPage(),
            ['path' => $paginador->path(), 'query' => request()->query()],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fila(DatabaseNotification $n, ?User $usuario, User $actual): array
    {
        $datos = $n->data;
        $tipo = $datos['type'] ?? $datos['tipo'] ?? null;

        return [
            'id' => (string) $n->id,
            'propia' => (int) $n->notifiable_id === $actual->id,
            'destinatario' => $usuario !== null ? trim(sprintf('%s %s', $usuario->name, $usuario->apellidos ?? '')) : null,
            'titulo' => is_string($datos['titulo'] ?? null) ? $datos['titulo'] : '',
            'mensaje' => is_string($datos['mensaje'] ?? null) ? $datos['mensaje'] : '',
            'tipo' => is_string($tipo) ? $tipo : null,
            'leida' => $n->read_at !== null,
            'creada_en' => $n->created_at?->toIso8601String(),
        ];
    }
}
