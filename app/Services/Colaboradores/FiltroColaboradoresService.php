<?php

namespace App\Services\Colaboradores;

use App\Models\Colaborador;
use Illuminate\Database\Eloquent\Builder;

/**
 * Capa de consulta compartida de los listados de colaboradores (explorador
 * web de Expedientes, API de expedientes y API de colaboradores): aplica
 * los filtros comunes con un solo criterio. Cada llamador conserva su
 * alcance, su estado por omisión, sus parámetros y su recurso de salida.
 *
 * Solo AGREGA condiciones; nunca ensancha el alcance ya aplicado.
 */
class FiltroColaboradoresService
{
    public function __construct(private readonly BuscadorColaboradoresService $buscador) {}

    /**
     * @param  Builder<Colaborador>  $query
     * @param  array{busqueda?: string, empresa_id?: int, sucursal_id?: int, departamento_id?: int, puesto_id?: int}  $filtros  0 o '' = sin filtro.
     * @param  bool  $conCorreo  La búsqueda también mira el correo personal (solo quien ve expedientes).
     * @return Builder<Colaborador>
     */
    public function aplicar(Builder $query, array $filtros, bool $conCorreo = false): Builder
    {
        $busqueda = $filtros['busqueda'] ?? '';
        $empresa = $filtros['empresa_id'] ?? 0;
        $sucursal = $filtros['sucursal_id'] ?? 0;
        $departamento = $filtros['departamento_id'] ?? 0;
        $puesto = $filtros['puesto_id'] ?? 0;

        return $query
            // Buscador único: cada palabra en nombre, apellidos, número,
            // puesto, sucursal o departamento («demo cuernavaca»).
            ->when($busqueda !== '', fn (Builder $q) => $conCorreo
                ? $q->where(fn (Builder $sub) => $sub
                    ->where(fn (Builder $b) => $this->buscador->aplicar($b, $busqueda))
                    ->orWhere('correo_personal', 'like', sprintf('%%%s%%', $busqueda)))
                : $this->buscador->aplicar($q, $busqueda))
            ->when($empresa > 0, fn (Builder $q) => $q->whereHas('sucursalPrincipal', fn (Builder $sub) => $sub->where('empresa_id', $empresa)))
            ->when($sucursal > 0, fn (Builder $q) => $q->where('sucursal_principal_id', $sucursal))
            ->when($departamento > 0, fn (Builder $q) => $q->where('departamento_id', $departamento))
            ->when($puesto > 0, fn (Builder $q) => $q->where('puesto_id', $puesto));
    }
}
