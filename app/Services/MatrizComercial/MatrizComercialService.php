<?php

namespace App\Services\MatrizComercial;

use App\Enums\EstadoUsuario;
use App\Enums\TipoAsignacionNodoComercial;
use App\Models\AsignacionNodoComercial;
use App\Models\NodoComercial;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Árbol de la matriz comercial (MATRIZ -> Región -> Zona -> Ruta) con
 * cobertura cruzada contra colaboradores activos — ver
 * docs/HEADCOUNT_Y_VACANTES.md, sección "Matriz comercial vs Organigrama vs
 * Vacantes". Nunca calcula vacantes/headcount aquí (eso sigue siendo
 * App\Services\Headcount\HeadcountService, a nivel Sucursal): este servicio
 * solo responde "¿esta ruta tiene gestor asignado hoy?".
 */
class MatrizComercialService
{
    /**
     * Árbol completo, ya anidado (cada nodo trae 'hijos'), listo para
     * renderizar. La raíz MATRIZ siempre existe tras MatrizComercialSeeder.
     *
     * @return array<string, mixed>|null
     */
    public function arbol(): ?array
    {
        $raiz = NodoComercial::query()
            ->whereNull('parent_id')
            ->with(['sucursal:id,nombre', 'responsable:id,name,apellidos,foto_path'])
            ->first();

        if ($raiz === null) {
            return null;
        }

        $todos = NodoComercial::query()
            ->with(['sucursal:id,nombre', 'responsable:id,name,apellidos,foto_path', 'apoyosYVolantesActivos'])
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get();

        return $this->nodoConHijos($raiz, $todos);
    }

    /**
     * @param  Collection<int, NodoComercial>  $todos
     * @return array<string, mixed>
     */
    private function nodoConHijos(NodoComercial $nodo, Collection $todos): array
    {
        $hijos = $todos->where('parent_id', $nodo->id)->values();

        return [
            'id' => $nodo->id,
            'tipo' => $nodo->tipo->value,
            'nombre' => $nodo->nombre,
            'activa' => $nodo->activa,
            'region' => $nodo->region,
            'sucursal' => $nodo->sucursal?->only(['id', 'nombre']),
            'responsable' => $nodo->responsable !== null ? [
                'id' => $nodo->responsable->id,
                'nombre' => trim("{$nodo->responsable->name} {$nodo->responsable->apellidos}"),
            ] : null,
            'estado_operativo' => $nodo->metadata['estado_operativo'] ?? null,
            'cobertura' => $this->cobertura($nodo),
            'apoyos' => $nodo->apoyosYVolantesActivos
                ->where('tipo_asignacion', TipoAsignacionNodoComercial::Apoyo)
                ->map(fn (AsignacionNodoComercial $a) => $this->usuarioResumen($a))
                ->values()
                ->all(),
            'volantes' => $nodo->apoyosYVolantesActivos
                ->where('tipo_asignacion', TipoAsignacionNodoComercial::Volante)
                ->map(fn (AsignacionNodoComercial $a) => $this->usuarioResumen($a))
                ->values()
                ->all(),
            'hijos' => $hijos->map(fn (NodoComercial $hijo) => $this->nodoConHijos($hijo, $todos))->all(),
        ];
    }

    /**
     * @return array{id: int, nombre: string}
     */
    private function usuarioResumen(AsignacionNodoComercial $asignacion): array
    {
        return [
            'id' => $asignacion->user_id,
            'nombre' => trim("{$asignacion->usuario->name} {$asignacion->usuario->apellidos}"),
        ];
    }

    /**
     * 'no_aplica' para niveles que no son de cobertura (matriz/región/zona);
     * 'cubierta'/'sin_cubrir' para ruta según tenga responsable ACTIVO.
     */
    private function cobertura(NodoComercial $nodo): string
    {
        if (! $nodo->tipo->esCobertura()) {
            return 'no_aplica';
        }

        if (! $nodo->activa) {
            return 'inactiva';
        }

        $tieneResponsableActivo = $nodo->responsable_user_id !== null
            && $nodo->responsable?->estatus === EstadoUsuario::Activo;

        return $tieneResponsableActivo ? 'cubierta' : 'sin_cubrir';
    }

    /**
     * @return array{total_rutas: int, activas: int, inactivas: int, cubiertas: int, sin_cubrir: int, porcentaje_cobertura: float}
     */
    public function resumen(): array
    {
        $rutas = NodoComercial::query()
            ->where('tipo', 'ruta')
            ->with('responsable:id,estatus')
            ->get();

        $activas = $rutas->where('activa', true);
        $cubiertas = $activas->filter(
            fn (NodoComercial $r) => $r->responsable_user_id !== null && $r->responsable?->estatus === EstadoUsuario::Activo,
        );

        return [
            'total_rutas' => $rutas->count(),
            'activas' => $activas->count(),
            'inactivas' => $rutas->count() - $activas->count(),
            'cubiertas' => $cubiertas->count(),
            'sin_cubrir' => $activas->count() - $cubiertas->count(),
            'porcentaje_cobertura' => $activas->count() > 0
                ? round(($cubiertas->count() / $activas->count()) * 100, 1)
                : 0.0,
        ];
    }

    /**
     * Asigna (o quita, con null) el gestor responsable de un nodo de
     * cobertura (ruta). No toca headcount/vacantes: esos se derivan del
     * `puesto_id`/`sucursal_principal_id` real del usuario, no de esta
     * asignación de la matriz. `responsable_user_id` queda como caché
     * sincronizada del gestor activo en App\Models\AsignacionNodoComercial
     * (fuente de verdad real, con historial).
     */
    public function asignarResponsable(NodoComercial $nodo, ?User $usuario): void
    {
        DB::transaction(function () use ($nodo, $usuario): void {
            $this->cerrarAsignacionesActivas($nodo, TipoAsignacionNodoComercial::Gestor);

            if ($usuario !== null) {
                AsignacionNodoComercial::create([
                    'user_id' => $usuario->id,
                    'nodo_comercial_id' => $nodo->id,
                    'tipo_asignacion' => TipoAsignacionNodoComercial::Gestor->value,
                    'activo' => true,
                    'fecha_inicio' => now(),
                ]);
            }

            $nodo->update(['responsable_user_id' => $usuario?->id]);
        });
    }

    /**
     * Agrega un apoyo/volante adicional al nodo (no cierra a los demás: a
     * diferencia del gestor, puede haber varios apoyos/volantes activos a
     * la vez).
     */
    public function agregarApoyo(NodoComercial $nodo, User $usuario, TipoAsignacionNodoComercial $tipo): void
    {
        $yaActivo = AsignacionNodoComercial::query()
            ->where('nodo_comercial_id', $nodo->id)
            ->where('user_id', $usuario->id)
            ->where('tipo_asignacion', $tipo->value)
            ->where('activo', true)
            ->exists();

        if ($yaActivo) {
            return;
        }

        AsignacionNodoComercial::create([
            'user_id' => $usuario->id,
            'nodo_comercial_id' => $nodo->id,
            'tipo_asignacion' => $tipo->value,
            'activo' => true,
            'fecha_inicio' => now(),
        ]);
    }

    /**
     * Cierra la asignación activa de un usuario específico en un nodo
     * (quitar un apoyo/volante puntual sin afectar a los demás).
     */
    public function quitarAsignacion(NodoComercial $nodo, User $usuario, TipoAsignacionNodoComercial $tipo): void
    {
        AsignacionNodoComercial::query()
            ->where('nodo_comercial_id', $nodo->id)
            ->where('user_id', $usuario->id)
            ->where('tipo_asignacion', $tipo->value)
            ->where('activo', true)
            ->update(['activo' => false, 'fecha_fin' => now()]);
    }

    /**
     * Se llama cuando un colaborador se da de baja (ver
     * App\Services\MovimientosLaborales\MovimientoLaboralService::registrarBaja()):
     * cierra TODAS sus asignaciones activas en la matriz (gestor/apoyo/
     * volante) y, si era gestor de alguna ruta, limpia el caché
     * `responsable_user_id` para que esa ruta vuelva a verse "sin cubrir".
     */
    public function cerrarAsignacionesDe(User $usuario): void
    {
        DB::transaction(function () use ($usuario): void {
            NodoComercial::query()
                ->where('responsable_user_id', $usuario->id)
                ->update(['responsable_user_id' => null]);

            AsignacionNodoComercial::query()
                ->where('user_id', $usuario->id)
                ->where('activo', true)
                ->update(['activo' => false, 'fecha_fin' => now()]);
        });
    }

    private function cerrarAsignacionesActivas(NodoComercial $nodo, TipoAsignacionNodoComercial $tipo): void
    {
        AsignacionNodoComercial::query()
            ->where('nodo_comercial_id', $nodo->id)
            ->where('tipo_asignacion', $tipo->value)
            ->where('activo', true)
            ->update(['activo' => false, 'fecha_fin' => now()]);
    }
}
