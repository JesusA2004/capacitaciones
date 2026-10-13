<?php

namespace App\Services\Notificaciones;

use App\Models\NotificacionEntrega;
use App\Models\User;
use App\Support\Paginacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * «Notificaciones del sistema» (permiso notificaciones.ver_todas): consulta
 * de SOLO LECTURA de los avisos de todas las personas, con filtros por
 * destinatario, tipo, módulo, leída/no leída, fecha y estado de entrega.
 * Nunca marca nada como leído: la bandeja de cada quien es suya.
 */
class ConsultaNotificacionesService
{
    /**
     * @param  array<string, mixed>  $filtros  usuario, tipo, modulo, estado, entrega, desde, hasta, per_page
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function listar(array $filtros): LengthAwarePaginator
    {
        $texto = fn (string $clave): string => is_string($filtros[$clave] ?? null) ? trim($filtros[$clave]) : '';
        $conPush = fn () => NotificacionEntrega::query()->where('push_dispositivos', '>', 0)->whereNotNull('notification_id')->select('notification_id');

        $paginador = DatabaseNotification::query()
            ->where('notifiable_type', (new User)->getMorphClass())
            ->when($texto('usuario'), fn (Builder $q, string $v) => $q->whereIn('notifiable_id', User::query()
                ->where(fn (Builder $u) => $u->where('name', 'like', "%{$v}%")->orWhere('apellidos', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%"))
                ->select('id')))
            ->when($texto('tipo'), fn (Builder $q, string $v) => $q->where(fn (Builder $t) => $t->where('data->type', $v)->orWhere('data->tipo', $v)))
            ->when($texto('modulo'), fn (Builder $q, string $v) => $q->where('data->related_type', $v))
            ->when($texto('estado') === 'leidas', fn (Builder $q) => $q->whereNotNull('read_at'))
            ->when($texto('estado') === 'no_leidas', fn (Builder $q) => $q->whereNull('read_at'))
            ->when($texto('desde'), fn (Builder $q, string $v) => $q->whereDate('created_at', '>=', $v))
            ->when($texto('hasta'), fn (Builder $q, string $v) => $q->whereDate('created_at', '<=', $v))
            ->when($texto('entrega') === 'con_push', fn (Builder $q) => $q->whereIn('id', $conPush()))
            ->when($texto('entrega') === 'sin_push', fn (Builder $q) => $q->whereNotIn('id', $conPush()))
            ->when($texto('entrega') === 'reconciliada', fn (Builder $q) => $q->whereIn('id', NotificacionEntrega::query()->where('origen', 'reconciliador')->whereNotNull('notification_id')->select('notification_id')))
            ->orderByDesc('created_at')
            ->paginate(Paginacion::porPagina(30, $filtros['per_page'] ?? null))
            ->withQueryString();

        $notificaciones = $paginador->getCollection();
        $entregas = NotificacionEntrega::query()->whereIn('notification_id', $notificaciones->pluck('id')->all())->get()->keyBy('notification_id');
        $usuarios = User::query()->whereIn('id', $notificaciones->pluck('notifiable_id')->all())->get(['id', 'name', 'apellidos'])->keyBy('id');

        $filas = $notificaciones->map(fn (DatabaseNotification $n): array => $this->fila($n, $usuarios->get($n->notifiable_id), $entregas->get($n->id)));

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
    private function fila(DatabaseNotification $n, ?User $usuario, ?NotificacionEntrega $entrega): array
    {
        $datos = $n->data;
        $tipo = $datos['type'] ?? $datos['tipo'] ?? null;

        return [
            'id' => (string) $n->id,
            'destinatario' => $usuario !== null ? ['id' => $usuario->id, 'nombre' => trim(sprintf('%s %s', $usuario->name, $usuario->apellidos ?? ''))] : null,
            'titulo' => is_string($datos['titulo'] ?? null) ? $datos['titulo'] : '',
            'mensaje' => is_string($datos['mensaje'] ?? null) ? $datos['mensaje'] : '',
            'tipo' => is_string($tipo) ? $tipo : null,
            'categoria' => $entrega?->categoria->value ?? (is_string($datos['categoria'] ?? null) ? $datos['categoria'] : null),
            'modulo' => is_string($datos['related_type'] ?? null) ? $datos['related_type'] : null,
            'leida' => $n->read_at !== null,
            'creada_en' => $n->created_at?->toIso8601String(),
            'push_dispositivos' => $entrega?->push_dispositivos,
            'origen' => $entrega?->origen,
        ];
    }

    /**
     * Valores para los filtros, tomados de los avisos recientes (sin
     * escanear toda la tabla).
     *
     * @return array{tipos: list<string>, modulos: list<string>}
     */
    public function opciones(): array
    {
        $tipos = [];
        $modulos = [];

        foreach (DatabaseNotification::query()->latest()->limit(1000)->get(['data']) as $notificacion) {
            $datos = $notificacion->data;
            $tipo = $datos['type'] ?? $datos['tipo'] ?? null;

            if (is_string($tipo) && $tipo !== '') {
                $tipos[$tipo] = true;
            }

            $modulo = $datos['related_type'] ?? null;

            if (is_string($modulo) && $modulo !== '') {
                $modulos[$modulo] = true;
            }
        }

        $tipos = array_map('strval', array_keys($tipos));
        $modulos = array_map('strval', array_keys($modulos));
        sort($tipos);
        sort($modulos);

        return ['tipos' => $tipos, 'modulos' => $modulos];
    }
}
