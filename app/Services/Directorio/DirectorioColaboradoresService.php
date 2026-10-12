<?php

namespace App\Services\Directorio;

use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Models\Sucursal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Directorio de la empresa: nombre, puesto, sucursal, departamento y
 * contacto CORPORATIVO (correo/teléfono — solo lo tienen puestos
 * administrativos/gerenciales, y nunca se inventa uno para quien no lo tiene
 * capturado).
 *
 * Regla única (web y API móvil): GLOBAL para cualquier colaborador
 * autenticado, SIN acotar por alcance organizacional — a propósito:
 * AlcanceOrganizacionalService reduce a un colaborador sin rol de gerencia a
 * verse solo a sí mismo, lo cual rompería el propósito de un directorio
 * ("encontrar el correo de un compañero de otra sucursal"). Por eso aArray()
 * es una lista CERRADA de campos de contacto corporativo: nunca el modelo
 * completo ni datos sensibles (salario, CURP, RFC, NSS, domicilio, correo o
 * teléfono personal).
 */
class DirectorioColaboradoresService
{
    /**
     * @param  array<string, mixed>  $filtros  busqueda?, puesto_id?, sucursal_id?, departamento_id?, solo_con_correo?
     * @return LengthAwarePaginator<int, Colaborador>
     */
    public function buscar(array $filtros = [], int $porPagina = 30): LengthAwarePaginator
    {
        $filtros = $this->normalizarFiltros($filtros);

        return Colaborador::query()
            ->select(['id', 'name', 'apellidos', 'numero_empleado', 'puesto_id', 'sucursal_principal_id', 'departamento_id', 'correo_corporativo', 'telefono_corporativo'])
            ->where('estatus', 'activo')
            ->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre', 'departamento:id,nombre'])
            ->when($filtros['busqueda'], function (Builder $q, string $busqueda): void {
                // Cada palabra debe aparecer en el nombre o en los apellidos:
                // así "María López" encuentra a María (name) López (apellidos).
                foreach (preg_split('/\s+/', $busqueda) ?: [] as $palabra) {
                    $q->where(fn (Builder $sub) => $sub
                        ->where('name', 'like', sprintf('%%%s%%', $palabra))
                        ->orWhere('apellidos', 'like', sprintf('%%%s%%', $palabra))
                        ->orWhere('numero_empleado', $palabra));
                }
            })
            ->when($filtros['puesto_id'], fn (Builder $q, int $v) => $q->where('puesto_id', $v))
            ->when($filtros['sucursal_id'], fn (Builder $q, int $v) => $q->where('sucursal_principal_id', $v))
            ->when($filtros['departamento_id'], fn (Builder $q, int $v) => $q->where('departamento_id', $v))
            ->when($filtros['solo_con_correo'], fn (Builder $q) => $q->whereNotNull('correo_corporativo')->where('correo_corporativo', '!=', ''))
            ->orderBy('name')
            ->orderBy('apellidos')
            ->paginate(min(50, max(1, $porPagina)))
            ->withQueryString();
    }

    /**
     * Catálogos para los filtros del directorio (mismo dato en web y app).
     *
     * @return array{puestos: list<array{id: int, nombre: string}>, sucursales: list<array{id: int, nombre: string}>, departamentos: list<array{id: int, nombre: string}>}
     */
    public function opciones(): array
    {
        return [
            'puestos' => $this->aLista(Puesto::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre'])),
            'sucursales' => $this->aLista(Sucursal::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre'])),
            'departamentos' => $this->aLista(Departamento::query()->orderBy('nombre')->get(['id', 'nombre'])),
        ];
    }

    /**
     * @param  iterable<Puesto|Sucursal|Departamento>  $modelos
     * @return list<array{id: int, nombre: string}>
     */
    private function aLista(iterable $modelos): array
    {
        $lista = [];

        foreach ($modelos as $modelo) {
            $lista[] = ['id' => (int) $modelo->id, 'nombre' => (string) $modelo->nombre];
        }

        return $lista;
    }

    /**
     * Lista CERRADA de campos que salen del directorio — agregar un campo
     * aquí lo publica a toda la empresa.
     *
     * @return array{id: int, nombre: string, numero_empleado: string|null, puesto: string|null, sucursal: string|null, departamento: string|null, correo_corporativo: string|null, telefono_corporativo: string|null}
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

    /**
     * Filtros llegan de la query string (texto): un id no numérico se
     * ignora en vez de provocar un TypeError.
     *
     * @param  array<string, mixed>  $filtros
     * @return array{busqueda: string|null, puesto_id: int|null, sucursal_id: int|null, departamento_id: int|null, solo_con_correo: bool}
     */
    private function normalizarFiltros(array $filtros): array
    {
        $id = function (mixed $valor): ?int {
            $entero = filter_var($valor, FILTER_VALIDATE_INT);

            return is_int($entero) && $entero > 0 ? $entero : null;
        };
        $busqueda = is_string($filtros['busqueda'] ?? null) ? trim(mb_substr($filtros['busqueda'], 0, 100)) : '';

        return [
            'busqueda' => $busqueda !== '' ? $busqueda : null,
            'puesto_id' => $id($filtros['puesto_id'] ?? null),
            'sucursal_id' => $id($filtros['sucursal_id'] ?? null),
            'departamento_id' => $id($filtros['departamento_id'] ?? null),
            'solo_con_correo' => filter_var($filtros['solo_con_correo'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }
}
