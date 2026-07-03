<?php

namespace App\Services\Asignaciones;

use App\Enums\EstadoAsignacion;
use App\Enums\TipoDestinoAsignacion;
use App\Jobs\MaterializarAsignacionJob;
use App\Models\Asignacion;
use App\Models\AsignacionDestino;
use App\Models\AsignacionUsuario;
use App\Models\Curso;
use App\Models\User;
use App\Notifications\AsignacionCreadaNotification;
use App\Services\Capacitacion\ProgresoService;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * Motor central de asignaciones: resuelve a que usuarios corresponde una
 * asignacion (individual, por sucursal/departamento/puesto/rol o a todos) y
 * materializa el resultado en asignaciones_usuario de forma idempotente, para
 * que el historial se conserve aunque un colaborador cambie despues de
 * sucursal, puesto o rol.
 */
class AsignacionService
{
    public function __construct(private readonly ProgresoService $progresoService) {}

    /**
     * @param  array<string, mixed>  $datos
     * @param  array<int, array{tipo: string, id: int|null}>  $destinos
     */
    public function crear(Curso $elemento, array $datos, array $destinos): Asignacion
    {
        $asignacion = $elemento->asignaciones()->create($datos);

        foreach ($destinos as $destino) {
            AsignacionDestino::create([
                'asignacion_id' => $asignacion->id,
                'tipo_destino' => $destino['tipo'],
                'destino_id' => $destino['id'] ?? null,
            ]);
        }

        MaterializarAsignacionJob::dispatch($asignacion);

        return $asignacion;
    }

    public function cancelar(Asignacion $asignacion): void
    {
        $asignacion->update(['activa' => false, 'cancelada_en' => now()]);
        $asignacion->asignacionesUsuario()->where('estado', '!=', EstadoAsignacion::Completada->value)
            ->update(['estado' => EstadoAsignacion::Cancelada->value]);
    }

    /**
     * Crea (idempotentemente) los registros asignaciones_usuario para todos
     * los usuarios que correspondan a los destinos de la asignacion.
     */
    public function materializar(Asignacion $asignacion): void
    {
        $usuarios = $this->resolverUsuarios($asignacion->destinos);

        foreach ($usuarios as $usuario) {
            $asignacionUsuario = $asignacion->asignacionesUsuario()->firstOrCreate(
                ['user_id' => $usuario->id],
                ['estado' => EstadoAsignacion::Pendiente->value, 'fecha_limite' => $asignacion->fecha_limite],
            );

            if ($asignacionUsuario->wasRecentlyCreated) {
                $usuario->notify(new AsignacionCreadaNotification($asignacionUsuario));
            }

            $this->inscribirSiEsCurso($asignacion, $usuario, $asignacionUsuario);
        }
    }

    /**
     * Aplica al usuario nuevo todas las asignaciones vigentes que le
     * correspondan segun su sucursal, departamento, puesto, roles, o "todos".
     */
    public function aplicarVigentesA(User $usuario): void
    {
        $destinos = AsignacionDestino::query()
            ->whereHas('asignacion', fn ($query) => $query->where('activa', true))
            ->get();

        foreach ($destinos as $destino) {
            if ($this->destinoAplicaAUsuario($destino, $usuario)) {
                $asignacionUsuario = $destino->asignacion->asignacionesUsuario()->firstOrCreate(
                    ['user_id' => $usuario->id],
                    ['estado' => EstadoAsignacion::Pendiente->value, 'fecha_limite' => $destino->asignacion->fecha_limite],
                );

                if ($asignacionUsuario->wasRecentlyCreated) {
                    $usuario->notify(new AsignacionCreadaNotification($asignacionUsuario));
                }

                $this->inscribirSiEsCurso($destino->asignacion, $usuario, $asignacionUsuario);
            }
        }
    }

    private function inscribirSiEsCurso(Asignacion $asignacion, User $usuario, AsignacionUsuario $asignacionUsuario): void
    {
        if ($asignacion->asignable_type === Curso::class) {
            $curso = Curso::findOrFail($asignacion->asignable_id);
            $this->progresoService->inscribir($usuario, $curso, $asignacionUsuario);
        }
    }

    /**
     * @param  Collection<int, AsignacionDestino>  $destinos
     * @return Collection<int, User>
     */
    public function resolverUsuarios(Collection $destinos): Collection
    {
        $usuarios = collect();

        foreach ($destinos as $destino) {
            $usuarios = $usuarios->merge($this->resolverUsuariosDeDestino($destino));
        }

        return $usuarios->unique('id')->values();
    }

    /**
     * @return Collection<int, User>
     */
    public function resolverUsuariosDeDestino(AsignacionDestino $destino): Collection
    {
        return match ($destino->tipo_destino) {
            TipoDestinoAsignacion::Usuario => User::query()->whereKey($destino->destino_id)->get(),
            TipoDestinoAsignacion::Sucursal => User::query()->where('sucursal_principal_id', $destino->destino_id)->get(),
            TipoDestinoAsignacion::Departamento => User::query()->where('departamento_id', $destino->destino_id)->get(),
            TipoDestinoAsignacion::Puesto => User::query()->where('puesto_id', $destino->destino_id)->get(),
            TipoDestinoAsignacion::Rol => User::role($this->nombreRol($destino->destino_id))->get(),
            TipoDestinoAsignacion::Todos => User::all(),
        };
    }

    private function destinoAplicaAUsuario(AsignacionDestino $destino, User $usuario): bool
    {
        return match ($destino->tipo_destino) {
            TipoDestinoAsignacion::Usuario => $destino->destino_id === $usuario->id,
            TipoDestinoAsignacion::Sucursal => $destino->destino_id === $usuario->sucursal_principal_id,
            TipoDestinoAsignacion::Departamento => $destino->destino_id === $usuario->departamento_id,
            TipoDestinoAsignacion::Puesto => $destino->destino_id === $usuario->puesto_id,
            TipoDestinoAsignacion::Rol => $usuario->roles()->whereKey($destino->destino_id)->exists(),
            TipoDestinoAsignacion::Todos => true,
        };
    }

    private function nombreRol(?int $rolId): string
    {
        return Role::findOrFail($rolId)->name;
    }
}
