<?php

namespace App\Services\Tareas;

use App\Enums\TipoTarea;
use App\Models\TareaRh;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * ¿Qué pendientes le llegan a esta cuenta? (docs/PENDIENTES.md)
 *
 * Cada tipo de pendiente tiene su permiso `pendientes.recibir_<tipo>`
 * (Roles y permisos → Pendientes). Aplica SOLO a los pendientes asignados
 * por permiso («quien tenga X dentro de su alcance»): los asignados a una
 * cuenta por nombre (su firma, su evaluación, la entrevista que debe
 * aprobar) siempre llegan.
 *
 * Mientras el permiso todavía no existe en la base (entre el `git pull` y
 * la migración) el tipo se recibe como hasta hoy: el despliegue nunca le
 * quita un pendiente a nadie.
 */
class RecepcionPendientesService
{
    /** @var array<int, list<string>> */
    private array $memo = [];

    public function __construct(private readonly PermissionRegistrar $registrar) {}

    /**
     * Valores de TipoTarea que esta cuenta NO recibe cuando se asignan por
     * permiso.
     *
     * @return list<string>
     */
    public function tiposExcluidos(User $usuario): array
    {
        if (isset($this->memo[$usuario->id])) {
            return $this->memo[$usuario->id];
        }

        try {
            $existentes = $this->registrar->getPermissions(['guard_name' => 'web'])->pluck('name')->flip();
            $propios = $usuario->getAllPermissions()->pluck('name')->flip();
            $excluidos = [];

            foreach (TipoTarea::configurables() as $tipo) {
                $permiso = $tipo->permisoRecibir();

                if ($existentes->has($permiso) && ! $propios->has($permiso)) {
                    $excluidos[] = $tipo->value;
                }
            }
        } catch (Throwable $e) {
            // Una falla de configuración nunca esconde pendientes.
            Log::warning('RecepcionPendientesService: no se pudo calcular qué pendientes recibe la cuenta.', ['user_id' => $usuario->id, 'error' => $e->getMessage()]);
            $excluidos = [];
        }

        return $this->memo[$usuario->id] = $excluidos;
    }

    public function recibe(User $usuario, TareaRh $tarea): bool
    {
        if ($tarea->asignado_user_id !== null) {
            return true;
        }

        return ! in_array($tarea->tipo->value, $this->tiposExcluidos($usuario), true);
    }

    /**
     * Catálogo para «Roles y permisos → Pendientes»: cada tipo con su
     * permiso «Recibir», etapa, si es informativo, con qué permisos de
     * acción se ha asignado (de los pendientes reales, para avisar cuando un
     * rol lo tiene encendido pero no puede atenderlo) y cuántos hay abiertos.
     *
     * @return list<array{tipo: string, permiso: string, etiqueta: string, etapa: string, etapa_clave: string, informativo: bool, asignado_por: list<string>, directo: bool, abiertos: int}>
     */
    public function catalogo(): array
    {
        $asignaciones = TareaRh::query()
            ->toBase()
            ->select('tipo', 'asignado_permiso')
            ->selectRaw('max(case when asignado_user_id is null then 0 else 1 end) as directo')
            ->selectRaw('sum(case when resuelta_en is null then 1 else 0 end) as abiertos')
            ->groupBy('tipo', 'asignado_permiso')
            ->get()
            ->groupBy('tipo');

        $catalogo = [];

        foreach (TipoTarea::configurables() as $tipo) {
            $filas = $asignaciones->get($tipo->value, collect());
            $etapa = $tipo->etapa();

            $catalogo[] = [
                'tipo' => $tipo->value,
                'permiso' => $tipo->permisoRecibir(),
                'etiqueta' => $tipo->etiqueta(),
                'etapa' => $etapa?->etiqueta() ?? 'Permanencia y organización',
                'etapa_clave' => $etapa->value ?? 'permanencia',
                'informativo' => $tipo->esInformativo(),
                'asignado_por' => array_values(array_unique(array_filter(
                    array_map(fn ($f): string => is_string($f->asignado_permiso ?? null) ? $f->asignado_permiso : '', $filas->all()),
                    fn (string $p): bool => $p !== '',
                ))),
                'directo' => $filas->contains(fn ($f): bool => (int) ($f->directo ?? 0) === 1),
                'abiertos' => (int) $filas->sum(fn ($f): int => (int) ($f->abiertos ?? 0)),
            ];
        }

        return $catalogo;
    }

    public function olvidar(): void
    {
        $this->memo = [];
    }
}
