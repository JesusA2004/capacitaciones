<?php

namespace App\Services\Administracion;

use App\Models\Puesto;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * Arbol de jerarquia de puestos (organigrama): usado por el panel web
 * (Administracion\JerarquiaPuestoController, vista con zoom/expand-collapse)
 * y por la API movil (Api\V1\Administracion\JerarquiaPuestoController), para
 * no duplicar la consulta ni el criterio de filtros entre ambos.
 */
class JerarquiaPuestoService
{
    private const FILTROS = ['empresa_id', 'sucursal_id', 'departamento_id', 'tipo_puesto'];

    /**
     * @return Collection<int, Puesto>
     */
    public function arbol(Request $request): Collection
    {
        return Puesto::query()
            ->with([
                'departamento:id,nombre',
                'puestoSuperior:id,nombre',
                'puestoCrecimiento:id,nombre',
                'respaldos:id,nombre',
                'puestosQuePuedeCubrir:id,nombre',
                'candidatos:id,puesto_objetivo_id,nombre,apellidos,estado',
            ])
            ->withCount([
                'colaboradores as colaboradores_count' => fn ($query) => $query->where('estatus', 'activo'),
                'candidatos',
                'vacantes as vacantes_abiertas_count' => fn ($query) => $query->whereNotIn('estado', ['cubierta', 'cancelada']),
            ])
            // Un puesto no tiene empresa/sucursal propia: se filtra por
            // "tiene al menos un colaborador activo en esa empresa/sucursal"
            // (ver docs/JERARQUIA_PUESTOS.md).
            ->when($request->integer('empresa_id'), fn ($query, int $id) => $query->whereHas(
                'colaboradores',
                fn ($sub) => $sub->whereHas('sucursalPrincipal', fn ($s) => $s->where('empresa_id', $id)),
            ))
            ->when($request->integer('sucursal_id'), fn ($query, int $id) => $query->whereHas(
                'colaboradores',
                fn ($sub) => $sub->where('sucursal_principal_id', $id),
            ))
            ->when($request->integer('departamento_id'), fn ($query, int $id) => $query->where('departamento_id', $id))
            ->when($request->string('tipo_puesto')->toString(), fn ($query, string $tipo) => $query->where('tipo_puesto', $tipo))
            ->orderBy('nivel_jerarquico')
            ->orderBy('nombre')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    public function filtrosAceptados(): array
    {
        return self::FILTROS;
    }
}
