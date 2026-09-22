<?php

namespace App\Services\Tareas;

use App\Enums\PrioridadTarea;
use App\Enums\TipoTarea;
use App\Models\Colaborador;
use App\Models\TareaRh;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bandeja de trabajo (tareas_rh): pendientes con objeto relacionado,
 * acción, prioridad y ciclo leído/resuelto.
 *
 * Deduplicación: cada tarea tiene una `clave` determinista (tipo + objeto +
 * destinatario). Mientras está abierta la clave se copia a `clave_abierta`
 * (índice único), así que el scheduler/jobs pueden llamar a
 * abrir() las veces que sea sin duplicar pendientes, incluso en paralelo.
 * Una vez resuelta, `clave_abierta` queda en null y el mismo pendiente puede
 * volver a abrirse si vuelve a hacer falta.
 *
 * Crear/resolver tareas es un efecto secundario: un fallo aquí nunca
 * revierte la operación de negocio (se registra en el log).
 */
class TareaService
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    /**
     * @param  array{titulo?: string, descripcion?: string|null, prioridad?: PrioridadTarea, colaborador?: Colaborador|null, usuario?: User|null, permiso?: string|null, accion?: string|null, vence_en?: Carbon|string|null, datos?: array<string, mixed>}  $opciones
     */
    public function abrir(TipoTarea $tipo, ?Model $relacionado, array $opciones = []): ?TareaRh
    {
        $usuario = $opciones['usuario'] ?? null;
        $permiso = $opciones['permiso'] ?? null;
        $clave = $this->clave($tipo, $relacionado, $usuario, $permiso);

        try {
            $existente = TareaRh::query()->where('clave_abierta', $clave)->first();

            if ($existente !== null) {
                return $existente;
            }

            $colaborador = $opciones['colaborador'] ?? null;

            return TareaRh::query()->create([
                'tipo' => $tipo,
                'titulo' => $opciones['titulo'] ?? $tipo->etiqueta(),
                'descripcion' => $opciones['descripcion'] ?? null,
                'prioridad' => $opciones['prioridad'] ?? PrioridadTarea::Media,
                'relacionado_type' => $relacionado?->getMorphClass(),
                'relacionado_id' => $relacionado?->getKey(),
                'colaborador_id' => $colaborador?->id,
                'asignado_user_id' => $usuario?->id,
                'asignado_permiso' => $permiso,
                'accion' => $opciones['accion'] ?? null,
                'vence_en' => $opciones['vence_en'] ?? null,
                'clave' => $clave,
                'clave_abierta' => $clave,
                'datos' => $opciones['datos'] ?? null,
            ]);
        } catch (QueryException $e) {
            // Carrera contra otra ejecución que abrió la misma tarea entre el
            // SELECT y el INSERT: el índice único la frenó, se regresa la
            // tarea que ganó.
            return TareaRh::query()->where('clave_abierta', $clave)->first();
        } catch (Throwable $e) {
            Log::warning('TareaService: no se pudo abrir la tarea.', ['tipo' => $tipo->value, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Resuelve las tareas abiertas de ese tipo sobre ese objeto (para todos
     * los destinatarios), p. ej. al firmar un documento se resuelve su
     * "firma pendiente".
     *
     * @param  list<TipoTarea>|TipoTarea  $tipos
     */
    public function resolver(array|TipoTarea $tipos, Model $relacionado, ?User $actor = null): int
    {
        $tipos = is_array($tipos) ? $tipos : [$tipos];

        try {
            return TareaRh::query()
                ->whereNull('resuelta_en')
                ->whereIn('tipo', array_map(fn (TipoTarea $t) => $t->value, $tipos))
                ->where('relacionado_type', $relacionado->getMorphClass())
                ->where('relacionado_id', $relacionado->getKey())
                ->update([
                    'resuelta_en' => now(),
                    'resuelta_por' => $actor?->id,
                    'clave_abierta' => null,
                    'updated_at' => now(),
                ]);
        } catch (Throwable $e) {
            Log::warning('TareaService: no se pudieron resolver tareas.', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Resolución manual desde la bandeja por el propio destinatario.
     */
    public function resolverManual(TareaRh $tarea, User $actor): TareaRh
    {
        $tarea->update(['resuelta_en' => now(), 'resuelta_por' => $actor->id, 'clave_abierta' => null]);

        return $tarea->refresh();
    }

    public function marcarLeida(TareaRh $tarea): TareaRh
    {
        if ($tarea->read_at === null) {
            $tarea->update(['read_at' => now()]);
        }

        return $tarea->refresh();
    }

    /**
     * true si el usuario es destinatario de la tarea: asignada a su cuenta,
     * o asignada por permiso y el usuario lo tiene con alcance sobre el
     * colaborador involucrado.
     */
    public function esDestinatario(User $usuario, TareaRh $tarea): bool
    {
        if ($tarea->asignado_user_id !== null) {
            return $tarea->asignado_user_id === $usuario->id;
        }

        if ($tarea->asignado_permiso === null || ! $usuario->can($tarea->asignado_permiso)) {
            return false;
        }

        $colaborador = $tarea->colaborador;

        return $colaborador === null
            || $this->alcance->tieneAlcanceGlobal($usuario)
            || $this->alcance->puedeVerExpediente($usuario, $colaborador);
    }

    /**
     * Bandeja del usuario: tareas asignadas a su cuenta + tareas asignadas
     * por permiso que tiene, acotadas por su alcance organizacional.
     *
     * @param  array{estado?: string|null, tipo?: string|null, per_page?: int|string|null}  $filtros
     * @return LengthAwarePaginator<int, TareaRh>
     */
    public function bandeja(User $usuario, array $filtros = []): LengthAwarePaginator
    {
        return $this->queryBandeja($usuario, $filtros)
            ->with(['colaborador:id,name,apellidos,numero_empleado,sucursal_principal_id'])
            ->orderByRaw("case prioridad when 'urgente' then 4 when 'alta' then 3 when 'media' then 2 else 1 end desc")
            ->orderBy('vence_en')
            ->orderByDesc('id')
            ->paginate(max(1, min(100, (int) ($filtros['per_page'] ?? 20))));
    }

    /**
     * @return array{abiertas: int, no_leidas: int, vencidas: int}
     */
    public function conteos(User $usuario): array
    {
        $base = $this->queryBandeja($usuario, ['estado' => 'abiertas']);

        return [
            'abiertas' => (clone $base)->count(),
            'no_leidas' => (clone $base)->whereNull('read_at')->count(),
            'vencidas' => (clone $base)->whereNotNull('vence_en')->whereDate('vence_en', '<', now()->toDateString())->count(),
        ];
    }

    /**
     * @param  array{estado?: string|null, tipo?: string|null}  $filtros
     * @return Builder<TareaRh>
     */
    private function queryBandeja(User $usuario, array $filtros): Builder
    {
        $permisos = $usuario->getAllPermissions()->pluck('name')->all();
        $colaboradoresVisibles = $this->alcance->tieneAlcanceGlobal($usuario)
            ? null
            : $this->alcance->limitarColaboradoresPorAlcance(Colaborador::query(), $usuario)->select('id');

        $query = TareaRh::query()->where(function (Builder $q) use ($usuario, $permisos, $colaboradoresVisibles): void {
            $q->where('asignado_user_id', $usuario->id);

            if ($permisos !== []) {
                $q->orWhere(function (Builder $sub) use ($permisos, $colaboradoresVisibles): void {
                    $sub->whereNull('asignado_user_id')->whereIn('asignado_permiso', $permisos);

                    if ($colaboradoresVisibles !== null) {
                        $sub->where(fn (Builder $c) => $c->whereNull('colaborador_id')->orWhereIn('colaborador_id', $colaboradoresVisibles));
                    }
                });
            }
        });

        $estado = $filtros['estado'] ?? 'abiertas';

        return $query
            ->when($estado === 'abiertas', fn (Builder $q) => $q->whereNull('resuelta_en'))
            ->when($estado === 'resueltas', fn (Builder $q) => $q->whereNotNull('resuelta_en'))
            ->when($filtros['tipo'] ?? null, fn (Builder $q, string $tipo) => $q->where('tipo', $tipo));
    }

    private function clave(TipoTarea $tipo, ?Model $relacionado, ?User $usuario, ?string $permiso): string
    {
        return sprintf(
            '%s:%s:%s:%s',
            $tipo->value,
            $relacionado !== null ? class_basename($relacionado).'#'.$relacionado->getKey() : '-',
            $usuario !== null ? 'u'.$usuario->id : '-',
            $permiso ?? '-',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function aArray(TareaRh $tarea): array
    {
        return [
            'id' => $tarea->id,
            'tipo' => $tarea->tipo->value,
            'tipo_etiqueta' => $tarea->tipo->etiqueta(),
            'titulo' => $tarea->titulo,
            'descripcion' => $tarea->descripcion,
            'prioridad' => $tarea->prioridad->value,
            'accion' => $tarea->accion,
            'related_type' => $tarea->relacionado_type !== null ? class_basename($tarea->relacionado_type) : null,
            'related_id' => $tarea->relacionado_id,
            'colaborador' => $tarea->colaborador !== null ? [
                'id' => $tarea->colaborador->id,
                'nombre' => $tarea->colaborador->nombreCompleto(),
                'numero_empleado' => $tarea->colaborador->numero_empleado,
            ] : null,
            'vence_en' => $tarea->vence_en?->toDateString(),
            'read_at' => $tarea->read_at?->toIso8601String(),
            'resolved_at' => $tarea->resuelta_en?->toIso8601String(),
            'creada_en' => $tarea->created_at?->toIso8601String(),
            'datos' => $tarea->datos,
        ];
    }
}
