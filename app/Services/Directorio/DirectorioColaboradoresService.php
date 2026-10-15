<?php

namespace App\Services\Directorio;

use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Directorio de la empresa: nombre, puesto, sucursal, departamento y
 * contacto CORPORATIVO (correo/teléfono — solo lo tienen puestos
 * administrativos/gerenciales, y nunca se inventa uno para quien no lo tiene
 * capturado).
 *
 * Contacto (cierre 2026-10-15, misma regla web y API, decidida aquí y
 * nunca en Vue): nombre, puesto, área y sucursal los ve todo colaborador;
 * el correo y el teléfono corporativos solo quien tiene
 * `directorio.contactos.ver_todos` o es personal corporativo. Un
 * colaborador operativo ve únicamente el contacto de su JEFE DIRECTO
 * (jefe_id, derivado del organigrama).
 *
 * Regla de la lista: GLOBAL para cualquier colaborador autenticado, SIN
 * acotar por alcance organizacional — a propósito:
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

        return $this->consulta()
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
            // «Solo con correo» filtra sobre lo que la persona puede ver.
            ->when($filtros['solo_con_correo'], fn (Builder $q) => $q->whereNotNull('correo_corporativo')->where('correo_corporativo', '!=', ''))
            ->orderBy('name')
            ->orderBy('apellidos')
            ->paginate(min(50, max(1, $porPagina)))
            ->withQueryString();
    }

    /**
     * Directorio COMPLETO para la web (sin paginar: sin gestores son pocas
     * decenas de personas), con lo necesario para agruparlo por puesto, por
     * área y por sucursal en pantalla.
     *
     * @return list<array<string, mixed>>
     */
    public function todos(?User $viewer = null): array
    {
        return array_values($this->consulta()
            ->orderBy('name')
            ->orderBy('apellidos')
            ->get()
            ->map(fn (Colaborador $c): array => [
                ...$this->aArray($c, $viewer),
                'puesto_id' => $c->puesto_id,
                'nivel' => $c->puesto->nivel_jerarquico ?? 99,
                'sucursal_id' => $c->sucursal_principal_id,
                'es_corporativo' => (bool) ($c->sucursalPrincipal->es_corporativo ?? false),
                // Área: en Corporativo, el departamento del puesto (Sistemas,
                // Contraloría, RH…); en sucursal, el puesto (Gerentes,
                // Subgerentes, Coordinadoras…).
                'area' => $this->area($c),
            ])
            ->all());
    }

    private function area(Colaborador $c): string
    {
        if ($c->sucursalPrincipal->es_corporativo ?? false) {
            $departamento = $c->puesto->departamento->nombre ?? $c->departamento->nombre ?? 'Sin área';

            return (string) (config('directorio.alias_areas_corporativo')[$departamento] ?? $departamento);
        }

        $puesto = $c->puesto->nombre ?? 'Sin puesto';

        foreach ((array) config('directorio.areas_sucursal', []) as $patron => $etiqueta) {
            if (preg_match('/^'.str_replace('%', '.*', preg_quote((string) $patron, '/')).'$/iu', $puesto) === 1) {
                return (string) $etiqueta;
            }
        }

        return $puesto;
    }

    /**
     * Colaboradores activos que aparecen en el directorio: sin los grupos
     * excluidos (gestores, config/directorio.php). Un puesto sin grupo sí
     * aparece.
     *
     * @return Builder<Colaborador>
     */
    private function consulta(): Builder
    {
        $excluir = array_values(array_filter((array) config('directorio.excluir_grupos', []), 'is_string'));

        return Colaborador::query()
            ->select(['id', 'name', 'apellidos', 'numero_empleado', 'puesto_id', 'sucursal_principal_id', 'departamento_id', 'correo_corporativo', 'telefono_corporativo'])
            ->where('estatus', 'activo')
            ->when($excluir !== [], fn (Builder $q) => $q->where(fn (Builder $sub) => $sub
                ->whereNull('puesto_id')
                ->orWhereHas('puesto', fn (Builder $p) => $p
                    ->where(fn (Builder $g) => $g->whereNull('grupo_documental')->orWhereNotIn('grupo_documental', $excluir))
                    ->where(function (Builder $n): void {
                        foreach (array_filter((array) config('directorio.excluir_puestos', []), 'is_string') as $patron) {
                            $n->where('nombre', 'not like', $patron);
                        }
                    }))))
            ->with(['puesto:id,nombre,nivel_jerarquico,departamento_id', 'puesto.departamento:id,nombre', 'sucursalPrincipal:id,nombre,es_corporativo', 'departamento:id,nombre']);
    }

    /**
     * Catálogos para los filtros del directorio (mismo dato en web y app).
     *
     * @return array{puestos: list<array{id: int, nombre: string}>, sucursales: list<array{id: int, nombre: string}>, departamentos: list<array{id: int, nombre: string}>}
     */
    public function opciones(): array
    {
        return [
            'puestos' => $this->aLista(Puesto::query()->where('activo', true)
                ->where(fn (Builder $q) => $q->whereNull('grupo_documental')->orWhereNotIn('grupo_documental', (array) config('directorio.excluir_grupos', [])))
                ->where(function (Builder $n): void {
                    foreach (array_filter((array) config('directorio.excluir_puestos', []), 'is_string') as $patron) {
                        $n->where('nombre', 'not like', $patron);
                    }
                })
                ->orderBy('nombre')->get(['id', 'nombre'])),
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
     * aquí lo publica a toda la empresa. El contacto solo sale si quien
     * mira tiene derecho a verlo (puedeVerContacto); si no, va nulo.
     *
     * @return array{id: int, nombre: string, numero_empleado: string|null, puesto: string|null, sucursal: string|null, departamento: string|null, correo_corporativo: string|null, telefono_corporativo: string|null, contacto_visible: bool, es_mi_jefe: bool}
     */
    public function aArray(Colaborador $colaborador, ?User $viewer = null): array
    {
        $visible = $viewer !== null && $this->puedeVerContacto($viewer, $colaborador);

        return [
            'id' => $colaborador->id,
            'nombre' => $colaborador->nombreCompleto(),
            'numero_empleado' => $colaborador->numero_empleado,
            'puesto' => $colaborador->puesto?->nombre,
            'sucursal' => $colaborador->sucursalPrincipal?->nombre,
            'departamento' => $colaborador->departamento?->nombre,
            'correo_corporativo' => $visible ? $colaborador->correo_corporativo : null,
            'telefono_corporativo' => $visible ? $colaborador->telefono_corporativo : null,
            'contacto_visible' => $visible,
            'es_mi_jefe' => $viewer?->colaborador !== null && $viewer->colaborador->jefe_id === $colaborador->id,
        ];
    }

    /** Contactos de TODO el directorio: permiso explícito o personal corporativo. */
    public function veTodosLosContactos(User $viewer): bool
    {
        if ($viewer->can('directorio.contactos.ver_todos')) {
            return true;
        }

        return (bool) ($viewer->colaborador?->sucursalPrincipal?->es_corporativo ?? false);
    }

    public function puedeVerContacto(User $viewer, Colaborador $colaborador): bool
    {
        if ($this->veTodosLosContactos($viewer)) {
            return true;
        }

        $propio = $viewer->colaborador;

        // Operativo: su propio contacto y el de su jefe directo, nada más.
        return $propio !== null && ($propio->id === $colaborador->id || $propio->jefe_id === $colaborador->id);
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
