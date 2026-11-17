<?php

namespace App\Services\MatrizComercial;

use App\Enums\EstadoUsuario;
use App\Enums\TipoAsignacionNodoComercial;
use App\Models\AsignacionNodoComercial;
use App\Models\Colaborador;
use App\Models\NodoComercial;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Árbol de la matriz comercial (MATRIZ -> Región -> Zona -> Ruta) con
 * cobertura cruzada contra colaboradores activos — ver
 * docs/HEADCOUNT_Y_VACANTES.md, sección "Matriz comercial vs Organigrama vs
 * Vacantes". Nunca calcula vacantes/headcount aquí (eso sigue siendo
 * App\Services\Headcount\HeadcountService, a nivel Sucursal): este servicio
 * solo responde "¿esta ruta tiene gestor asignado hoy?".
 *
 * El gestor/apoyo/volante de un nodo es siempre un Colaborador — puede o no
 * tener cuenta de acceso.
 */
class MatrizComercialService
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly AuditoriaService $auditoria,
    ) {}

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
                'nombre' => $nodo->responsable->nombreCompleto(),
            ] : null,
            'estado_operativo' => $nodo->metadata['estado_operativo'] ?? null,
            // Posición de la sucursal (gerencia/subgerencia/volante): se
            // muestra aparte, nunca como ruta asignable.
            'es_posicion' => $nodo->tipo->esPosicion(),
            'tipo_etiqueta' => $nodo->tipo->etiqueta(),
            'cobertura' => $this->cobertura($nodo),
            'apoyos' => $nodo->apoyosYVolantesActivos
                ->where('tipo_asignacion', TipoAsignacionNodoComercial::Apoyo)
                ->map(fn (AsignacionNodoComercial $a) => $this->colaboradorResumen($a))
                ->values()
                ->all(),
            'volantes' => $nodo->apoyosYVolantesActivos
                ->where('tipo_asignacion', TipoAsignacionNodoComercial::Volante)
                ->map(fn (AsignacionNodoComercial $a) => $this->colaboradorResumen($a))
                ->values()
                ->all(),
            'hijos' => $hijos->map(fn (NodoComercial $hijo) => $this->nodoConHijos($hijo, $todos))->all(),
        ];
    }

    /**
     * @return array{id: int|null, nombre: string}
     */
    private function colaboradorResumen(AsignacionNodoComercial $asignacion): array
    {
        return [
            'id' => $asignacion->colaborador_id,
            'nombre' => $asignacion->colaborador?->nombreCompleto() ?? '',
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

        $tieneResponsableActivo = $nodo->responsable_colaborador_id !== null
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
            fn (NodoComercial $r) => $r->responsable_colaborador_id !== null && $r->responsable?->estatus === EstadoUsuario::Activo,
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
     * `puesto_id`/`sucursal_principal_id` real del colaborador, no de esta
     * asignación de la matriz. `responsable_colaborador_id` queda como caché
     * sincronizada del gestor activo en App\Models\AsignacionNodoComercial
     * (fuente de verdad real, con historial).
     */
    public function asignarResponsable(NodoComercial $nodo, ?Colaborador $colaborador, ?User $actor = null): void
    {
        if ($colaborador !== null) {
            $this->asegurarRutaAsignable($nodo);
        }

        if ($actor !== null) {
            // Desde la pantalla: el servidor valida lo que el selector ya
            // acotaba (persona activa y dentro del alcance) — también al
            // quitar a quien hoy ocupa la ruta.
            if ($colaborador !== null) {
                $this->asegurarAsignablePor($actor, $colaborador, 'responsable_colaborador_id');
            }

            $actual = $nodo->responsable_colaborador_id !== null
                ? Colaborador::query()->withTrashed()->where('id', $nodo->responsable_colaborador_id)->first()
                : null;

            if ($actual !== null && ! $this->alcance->alcanzaColaborador($actor, $actual)) {
                throw ValidationException::withMessages(['responsable_colaborador_id' => 'Quien ocupa hoy esta ruta está fuera de tu alcance.']);
            }
        }

        $anterior = $nodo->responsable_colaborador_id;

        DB::transaction(function () use ($nodo, $colaborador): void {
            $this->cerrarAsignacionesActivas($nodo, TipoAsignacionNodoComercial::Gestor);

            if ($colaborador !== null) {
                AsignacionNodoComercial::create([
                    'colaborador_id' => $colaborador->id,
                    'user_id' => $colaborador->user?->id,
                    'nodo_comercial_id' => $nodo->id,
                    'tipo_asignacion' => TipoAsignacionNodoComercial::Gestor->value,
                    'activo' => true,
                    'fecha_inicio' => now(),
                ]);
            }

            $nodo->update([
                'responsable_colaborador_id' => $colaborador?->id,
                'responsable_user_id' => $colaborador?->user?->id,
            ]);
        });

        $this->auditoria->registrar('matriz_comercial.gestor_asignado', $nodo, $actor, [
            'nodo' => $nodo->nombre,
            'colaborador_anterior_id' => $anterior,
            'colaborador_id' => $colaborador?->id,
        ]);
    }

    /**
     * Solo personas activas y dentro del alcance de quien edita la matriz.
     */
    private function asegurarAsignablePor(User $actor, Colaborador $colaborador, string $campo): void
    {
        if ($colaborador->estatus !== EstadoUsuario::Activo || $colaborador->trashed()) {
            throw ValidationException::withMessages([$campo => sprintf('%s no está activo: solo se asignan colaboradores activos.', $colaborador->nombreCompleto())]);
        }

        if (! $this->alcance->alcanzaColaborador($actor, $colaborador)) {
            throw ValidationException::withMessages([$campo => sprintf('%s está fuera de tu alcance.', $colaborador->nombreCompleto())]);
        }
    }

    /**
     * Solo una RUTA (cartera de cobro) se le asigna a un gestor, apoyo o
     * volante. Las posiciones de gerencia/subgerencia/volante de la zona
     * son puestos de la sucursal y se ocupan desde el Organigrama.
     */
    private function asegurarRutaAsignable(NodoComercial $nodo): void
    {
        if (! $nodo->tipo->esCobertura()) {
            throw ValidationException::withMessages([
                'responsable_colaborador_id' => sprintf('«%s» es una %s, no una ruta de cobro: se ocupa desde el Organigrama.', $nodo->nombre, mb_strtolower($nodo->tipo->etiqueta())),
            ]);
        }
    }

    /**
     * Agrega un apoyo/volante adicional al nodo (no cierra a los demás: a
     * diferencia del gestor, puede haber varios apoyos/volantes activos a
     * la vez).
     */
    public function agregarApoyo(NodoComercial $nodo, Colaborador $colaborador, TipoAsignacionNodoComercial $tipo, ?User $actor = null): void
    {
        $this->asegurarRutaAsignable($nodo);

        if ($actor !== null) {
            $this->asegurarAsignablePor($actor, $colaborador, 'colaborador_id');
        }

        $yaActivo = AsignacionNodoComercial::query()
            ->where('nodo_comercial_id', $nodo->id)
            ->where('colaborador_id', $colaborador->id)
            ->where('tipo_asignacion', $tipo->value)
            ->where('activo', true)
            ->exists();

        if ($yaActivo) {
            return;
        }

        AsignacionNodoComercial::create([
            'colaborador_id' => $colaborador->id,
            'user_id' => $colaborador->user?->id,
            'nodo_comercial_id' => $nodo->id,
            'tipo_asignacion' => $tipo->value,
            'activo' => true,
            'fecha_inicio' => now(),
        ]);

        $this->auditoria->registrar('matriz_comercial.apoyo_agregado', $nodo, $actor, [
            'nodo' => $nodo->nombre,
            'colaborador_id' => $colaborador->id,
            'tipo' => $tipo->value,
        ]);
    }

    /**
     * Cierra la asignación activa de un colaborador específico en un nodo
     * (quitar un apoyo/volante puntual sin afectar a los demás).
     */
    public function quitarAsignacion(NodoComercial $nodo, Colaborador $colaborador, TipoAsignacionNodoComercial $tipo, ?User $actor = null): void
    {
        if ($actor !== null && ! $this->alcance->alcanzaColaborador($actor, $colaborador)) {
            throw ValidationException::withMessages(['colaborador_id' => sprintf('%s está fuera de tu alcance.', $colaborador->nombreCompleto())]);
        }

        $cerradas = AsignacionNodoComercial::query()
            ->where('nodo_comercial_id', $nodo->id)
            ->where('colaborador_id', $colaborador->id)
            ->where('tipo_asignacion', $tipo->value)
            ->where('activo', true)
            ->update(['activo' => false, 'fecha_fin' => now()]);

        if ($cerradas > 0) {
            $this->auditoria->registrar('matriz_comercial.asignacion_quitada', $nodo, $actor, [
                'nodo' => $nodo->nombre,
                'colaborador_id' => $colaborador->id,
                'tipo' => $tipo->value,
            ]);
        }
    }

    /**
     * Se llama cuando un colaborador se da de baja (ver
     * App\Services\MovimientosLaborales\MovimientoLaboralService::registrarBaja()):
     * cierra TODAS sus asignaciones activas en la matriz (gestor/apoyo/
     * volante) y, si era gestor de alguna ruta, limpia el caché
     * `responsable_colaborador_id` para que esa ruta vuelva a verse "sin
     * cubrir".
     */
    public function cerrarAsignacionesDe(Colaborador $colaborador): void
    {
        DB::transaction(function () use ($colaborador): void {
            NodoComercial::query()
                ->where('responsable_colaborador_id', $colaborador->id)
                ->update(['responsable_colaborador_id' => null, 'responsable_user_id' => null]);

            AsignacionNodoComercial::query()
                ->where('colaborador_id', $colaborador->id)
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
