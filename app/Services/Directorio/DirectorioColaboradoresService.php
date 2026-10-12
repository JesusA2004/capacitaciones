<?php

namespace App\Services\Directorio;

use App\Models\Colaborador;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Directorio de la empresa: nombre, puesto, sucursal y correo corporativo
 * (solo lo tienen puestos administrativos/gerenciales — Gerente, Subgerente,
 * Coordinadora… — no todos los colaboradores, y nunca se inventa uno para
 * quien no lo tiene capturado). Visible a CUALQUIER colaborador autenticado,
 * SIN acotar por alcance organizacional: a propósito — AlcanceOrganizacionalService
 * reduce a un colaborador sin rol de gerencia a verse solo a sí mismo, lo
 * cual rompería el propósito de un directorio ("encontrar el correo de un
 * compañero de otra sucursal"). Solo expone contacto corporativo, nunca
 * datos sensibles (salario, CURP, RFC, domicilio) — por eso no necesita el
 * mismo alcance que Expedientes.
 */
class DirectorioColaboradoresService
{
    /**
     * @param  array<string, mixed>  $filtros  busqueda?, puesto_id?, sucursal_id?, departamento_id?, solo_con_correo?
     * @return LengthAwarePaginator<int, Colaborador>
     */
    public function buscar(array $filtros = []): LengthAwarePaginator
    {
        return Colaborador::query()
            ->where('estatus', 'activo')
            ->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre', 'departamento:id,nombre'])
            ->when($filtros['busqueda'] ?? null, fn (Builder $q, string $v) => $q
                ->where(fn (Builder $sub) => $sub->where('name', 'like', "%{$v}%")->orWhere('apellidos', 'like', "%{$v}%")))
            ->when($filtros['puesto_id'] ?? null, fn (Builder $q, int $v) => $q->where('puesto_id', $v))
            ->when($filtros['sucursal_id'] ?? null, fn (Builder $q, int $v) => $q->where('sucursal_principal_id', $v))
            ->when($filtros['departamento_id'] ?? null, fn (Builder $q, int $v) => $q->where('departamento_id', $v))
            ->when($filtros['solo_con_correo'] ?? false, fn (Builder $q) => $q->whereNotNull('correo_corporativo'))
            ->orderBy('name')
            ->orderBy('apellidos')
            ->paginate(30)
            ->withQueryString();
    }

    /**
     * @return array<string, mixed>
     */
    public function aArray(Colaborador $colaborador): array
    {
        return [
            'id' => $colaborador->id,
            'nombre' => $colaborador->nombreCompleto(),
            'numero_empleado' => $colaborador->numero_empleado,
            'puesto' => $colaborador->puesto?->nombre,
            'sucursal' => $colaborador->sucursalPrincipal?->nombre,
            'departamento' => $colaborador->departamento?->nombre,
            'correo_corporativo' => $colaborador->correo_corporativo,
            'telefono_corporativo' => $colaborador->telefono_corporativo,
        ];
    }
}
