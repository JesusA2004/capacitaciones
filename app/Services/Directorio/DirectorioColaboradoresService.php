<?php

namespace App\Services\Directorio;

use App\Enums\AlcanceDatos;
use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Colaboradores\FotoColaboradorService;
use App\Services\Flujos\ConstructorEnlaces;
use App\Services\Permisos\AlcanceModuloService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Directorio de la empresa: nombre, puesto, sucursal, departamento y
 * contacto.
 *
 * Regla vigente (RH, 2026-11-11 — sustituye lo de abajo donde se contradiga):
 *  - Contacto = TELÉFONO PERSONAL (la empresa no asigna líneas), CORREO
 *    PERSONAL y CORREO CORPORATIVO. Los gestores no tienen correo corporativo.
 *  - Entra quien tiene `directorio.ver` o es de Corporativo (puedeEntrar);
 *    los gestores (rol colaborador) no lo tienen por defecto.
 *  - Gerente, subgerente y coordinadora de sucursal ven solo su círculo: su
 *    sucursal SIN gestores + Gerente y Subgerente Regional + su jefe directo,
 *    con contacto. De Subgerente Regional para arriba ven a toda la empresa.
 *
 * Contacto (cierre 2026-10-15, misma regla web y API, decidida aquí y
 * nunca en Vue): nombre, puesto, área y sucursal los ve todo colaborador;
 * el correo y el teléfono corporativos solo quien tiene
 * `directorio.contactos.ver_todos` o es personal corporativo. Un
 * colaborador operativo ve únicamente el contacto de su JEFE DIRECTO
 * (jefe_id, derivado del organigrama).
 *
 * Regla de la lista (2026-10-26): el alcance del módulo «Directorio» de
 * Roles y permisos decide A QUIÉNES ve cada quien (sucursalesVisibles):
 * Todos, Región (matriz comercial), Sucursal o, sin alcance, solo SU
 * sucursal — más su jefe directo, aunque esté en otra. El personal de
 * Corporativo ve a toda la empresa. Nunca se reduce a «solo él mismo»: un
 * directorio de una persona no sirve. aArray() es una lista CERRADA de campos de contacto corporativo: nunca el modelo
 * completo ni datos sensibles (salario, CURP, RFC, NSS, domicilio, correo o
 * teléfono personal).
 */
class DirectorioColaboradoresService
{
    public function __construct(
        private readonly AlcanceModuloService $alcanceModulo,
        private readonly FotoColaboradorService $fotos,
    ) {}

    /**
     * «Solo con correo» se IGNORA si quien busca no ve los contactos de
     * todos (veTodosLosContactos): filtrar en BD por un correo que no puede
     * ver le permitiría inferir quién tiene correo corporativo.
     *
     * @param  array<string, mixed>  $filtros  busqueda?, puesto_id?, sucursal_id?, departamento_id?, solo_con_correo?, solo_por_completar?
     * @return LengthAwarePaginator<int, Colaborador>
     */
    public function buscar(array $filtros = [], int $porPagina = 30, ?User $viewer = null): LengthAwarePaginator
    {
        return $this->filtrada($filtros, $viewer)
            ->paginate(min(50, max(1, $porPagina)))
            ->withQueryString();
    }

    /**
     * Tabla para exportar a Excel/PDF: misma consulta, alcance y lista cerrada
     * de aArray() que la pantalla; el contacto que no puede ver sale vacío.
     *
     * @param  array<string, mixed>  $filtros
     * @return array{titulo: string, columnas: array<int, string>, filas: array<int, array<int, string|int|float|null>>}
     */
    public function tablaExportable(array $filtros, User $viewer): array
    {
        $filas = [];

        foreach ($this->filtrada($filtros, $viewer)->get() as $colaborador) {
            $fila = $this->aArray($colaborador, $viewer);
            $filas[] = [
                $fila['nombre'],
                $fila['numero_empleado'],
                $fila['puesto'],
                $fila['sucursal'],
                $fila['departamento'],
                $fila['telefono'],
                $fila['correo_personal'],
                $fila['correo_corporativo'],
                $fila['datos_por_completar'] === null ? null : ($fila['datos_por_completar'] ? 'Datos por completar' : 'Completo'),
            ];
        }

        return [
            'titulo' => 'Directorio de colaboradores',
            'columnas' => ['Nombre', 'Número de empleado', 'Puesto', 'Sucursal', 'Departamento', 'Teléfono', 'Correo personal', 'Correo corporativo', 'Datos'],
            'filas' => $filas,
        ];
    }

    /**
     * Filtros de búsqueda sobre la consulta del directorio. «Solo con correo» y
     * «Solo datos por completar» se ignoran si quien busca no ve todos los
     * contactos: le permitirían inferir quién tiene contacto.
     *
     * @param  array<string, mixed>  $filtros
     * @return Builder<Colaborador>
     */
    private function filtrada(array $filtros, ?User $viewer): Builder
    {
        $filtros = $this->normalizarFiltros($filtros);
        $veTodos = $viewer !== null && $this->veTodosLosContactos($viewer);

        return $this->consulta($viewer)
            // Buscador único: cada palabra en nombre, apellidos, número,
            // puesto, sucursal o departamento («maría cuernavaca»).
            ->when($filtros['busqueda'], fn (Builder $q, string $busqueda) => $q->buscar($busqueda))
            ->when($filtros['puesto_id'], fn (Builder $q, int $v) => $q->where('puesto_id', $v))
            ->when($filtros['sucursal_id'], fn (Builder $q, int $v) => $q->where('sucursal_principal_id', $v))
            ->when($filtros['departamento_id'], fn (Builder $q, int $v) => $q->where('departamento_id', $v))
            ->when($filtros['solo_con_correo'] && $veTodos, fn (Builder $q) => $q->whereNotNull('correo_corporativo')->where('correo_corporativo', '!=', ''))
            // Misma regla que tieneDatosPorCompletar().
            ->when($filtros['solo_por_completar'] && $veTodos, fn (Builder $q) => $q->where(fn (Builder $o) => $o
                ->where(fn (Builder $t) => $t
                    ->where(fn (Builder $a) => $a->whereNull('telefono')->orWhere('telefono', ''))
                    ->where(fn (Builder $b) => $b->whereNull('telefono_corporativo')->orWhere('telefono_corporativo', '')))
                ->orWhereNull('correo_personal')->orWhere('correo_personal', '')
                ->orWhere(fn (Builder $c) => $c
                    ->where(fn (Builder $v) => $v->whereNull('correo_corporativo')->orWhere('correo_corporativo', ''))
                    ->whereDoesntHave('puesto', fn (Builder $p) => $p->whereIn('grupo_documental', (array) config('directorio.grupos_sin_correo_corporativo', []))))
                ->orWhereNull('puesto_id')->orWhereNull('sucursal_principal_id')))
            ->orderBy('name')
            ->orderBy('apellidos');
    }

    /**
     * Directorio COMPLETO para la web (sin paginar: toda la plantilla activa
     * visible son unas pocas centenas de personas como máximo), con lo
     * necesario para agruparlo por puesto, por área y por sucursal en pantalla.
     *
     * @return list<array<string, mixed>>
     */
    public function todos(?User $viewer = null): array
    {
        return array_values($this->consulta($viewer)
            ->orderBy('name')
            ->orderBy('apellidos')
            ->get()
            ->map(fn (Colaborador $c): array => $this->filaWeb($c, $viewer))
            ->all());
    }

    /**
     * Una fila del directorio web, recién leída (tras la edición rápida). Null
     * si con el cambio dejó de aparecer (p. ej. pasó a un puesto excluido).
     *
     * @return array<string, mixed>|null
     */
    public function filaWebDe(int $colaboradorId, ?User $viewer = null): ?array
    {
        $colaborador = $this->consulta($viewer)->whereKey($colaboradorId)->first();

        return $colaborador instanceof Colaborador ? $this->filaWeb($colaborador, $viewer) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function filaWeb(Colaborador $c, ?User $viewer): array
    {
        return [
            ...$this->aArray($c, $viewer),
            'puesto_id' => $c->puesto_id,
            'nivel' => $c->puesto->nivel_jerarquico ?? 99,
            'sucursal_id' => $c->sucursal_principal_id,
            'departamento_id' => $c->departamento_id,
            'es_corporativo' => (bool) ($c->sucursalPrincipal->es_corporativo ?? false),
            // Área: en Corporativo, el departamento del puesto (Sistemas,
            // Contraloría, RH…); en sucursal, el puesto (Gerentes,
            // Subgerentes, Coordinadoras…).
            'area' => $this->area($c),
            // Foto oficial del expediente (URL protegida), solo si quien mira
            // alcanza ese expediente; si no, la vista muestra iniciales.
            'foto_url' => $viewer !== null ? $this->fotos->urlPara($c, $viewer) : null,
            // Abrir expediente: mismo alcance que su pantalla; sin él, no hay enlace.
            'expediente_url' => $viewer !== null ? app(ConstructorEnlaces::class)->expediente($viewer, $c) : null,
        ];
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
     * Sucursales cuyo personal ve $viewer en el directorio; null = toda la
     * empresa (alcance «Todos» o personal de Corporativo). Sin viewer (uso
     * interno) no se acota.
     *
     * @return list<int>|null
     */
    public function sucursalesVisibles(?User $viewer): ?array
    {
        if ($viewer === null || $this->esCorporativo($viewer)) {
            return null;
        }

        $nivel = $this->alcanceModulo->nivel($viewer, 'directorio');

        if ($nivel === AlcanceDatos::Todos) {
            return null;
        }

        // Propios / Equipo = su propia sucursal (y adicionales asignadas).
        return array_values($this->alcanceModulo
            ->sucursalesPara($viewer, $nivel === AlcanceDatos::Region ? AlcanceDatos::Region : AlcanceDatos::Sucursal)
            ->all());
    }

    /**
     * Colaboradores activos que aparecen en el directorio: toda la plantilla
     * activa dentro del alcance de quien mira (sucursalesVisibles + su jefe
     * directo). Por defecto no se excluye a nadie (propuesta Etapa 1); el
     * mecanismo de exclusión por grupo/puesto sigue disponible vía
     * config/directorio.php por si RH decide ocultar algún grupo.
     *
     * @return Builder<Colaborador>
     */
    private function consulta(?User $viewer = null): Builder
    {
        $excluir = array_values(array_filter((array) config('directorio.excluir_grupos', []), 'is_string'));
        $sucursales = $this->sucursalesVisibles($viewer);
        $propio = $viewer?->colaborador;
        $circulo = $viewer !== null && $sucursales !== null && $this->veSoloSuCirculo($viewer);
        $fuera = array_values(array_filter((array) config('directorio.grupos_fuera_del_circulo', []), 'is_string'));
        $regionales = array_values(array_filter((array) config('directorio.puestos_regionales', []), 'is_string'));

        return Colaborador::query()
            ->when($sucursales !== null, fn (Builder $q) => $q->where(fn (Builder $a) => $a
                ->where(fn (Builder $s) => $s
                    ->whereIn('sucursal_principal_id', $sucursales === [] ? [0] : $sucursales)
                    // Círculo de sucursal: su gente sin los gestores.
                    ->when($circulo && $fuera !== [], fn (Builder $g) => $g->where(fn (Builder $p) => $p
                        ->whereNull('puesto_id')
                        ->orWhereHas('puesto', fn (Builder $x) => $x->where(fn (Builder $y) => $y->whereNull('grupo_documental')->orWhereNotIn('grupo_documental', $fuera))))))
                ->orWhere('id', $propio->id ?? 0)
                ->orWhere('id', $propio->jefe_id ?? 0)
                // …más la gerencia regional (Gerente y Subgerente Regional).
                ->when($circulo && $regionales !== [], fn (Builder $r) => $r->orWhereHas('puesto', function (Builder $p) use ($regionales): void {
                    $p->where(function (Builder $n) use ($regionales): void {
                        foreach ($regionales as $patron) {
                            $n->orWhere('nombre', 'like', $patron);
                        }
                    });
                }))))
            ->select(['id', 'name', 'apellidos', 'numero_empleado', 'puesto_id', 'sucursal_principal_id', 'departamento_id', 'correo_corporativo', 'correo_personal', 'telefono', 'telefono_corporativo', 'foto_path', 'jefe_id', 'gerente_id'])
            ->where('estatus', EstadoUsuario::Activo->value)
            ->when($excluir !== [], fn (Builder $q) => $q->where(fn (Builder $sub) => $sub
                ->whereNull('puesto_id')
                ->orWhereHas('puesto', fn (Builder $p) => $p
                    ->where(fn (Builder $g) => $g->whereNull('grupo_documental')->orWhereNotIn('grupo_documental', $excluir))
                    ->where(function (Builder $n): void {
                        foreach (array_filter((array) config('directorio.excluir_puestos', []), 'is_string') as $patron) {
                            $n->where('nombre', 'not like', $patron);
                        }
                    }))))
            ->with(['puesto:id,nombre,nivel_jerarquico,departamento_id,grupo_documental', 'puesto.departamento:id,nombre', 'sucursalPrincipal:id,nombre,es_corporativo', 'departamento:id,nombre']);
    }

    /**
     * Catálogos para los filtros del directorio (mismo dato en web y app).
     *
     * @return array{puestos: list<array{id: int, nombre: string}>, sucursales: list<array{id: int, nombre: string}>, departamentos: list<array{id: int, nombre: string}>}
     */
    public function opciones(?User $viewer = null): array
    {
        $sucursales = $this->sucursalesVisibles($viewer);

        return [
            'puestos' => $this->aLista(Puesto::query()->where('activo', true)
                ->where(fn (Builder $q) => $q->whereNull('grupo_documental')->orWhereNotIn('grupo_documental', (array) config('directorio.excluir_grupos', [])))
                ->where(function (Builder $n): void {
                    foreach (array_filter((array) config('directorio.excluir_puestos', []), 'is_string') as $patron) {
                        $n->where('nombre', 'not like', $patron);
                    }
                })
                ->orderBy('nombre')->get(['id', 'nombre'])),
            // Filtro de la app: solo las sucursales que puede ver.
            'sucursales' => $this->aLista(Sucursal::query()->where('activo', true)->when($sucursales !== null, fn (Builder $q) => $q->whereIn('id', $sucursales === [] ? [0] : $sucursales))->orderBy('nombre')->get(['id', 'nombre'])),
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
     * `datos_por_completar` (propuesta Etapa 1, pág. 5): true si le falta correo
     * o teléfono corporativo, puesto o sucursal. Solo se expone a quien ve todos los
     * contactos (RH); para los demás va null, igual que el filtro
     * «solo con correo», para no permitir inferir quién tiene contacto.
     *
     * @return array{id: int, nombre: string, numero_empleado: string|null, puesto: string|null, sucursal: string|null, departamento: string|null, correo_corporativo: string|null, correo_personal: string|null, telefono: string|null, telefono_corporativo: string|null, lleva_correo_corporativo: bool, contacto_visible: bool, datos_por_completar: bool|null, es_mi_jefe: bool}
     */
    public function aArray(Colaborador $colaborador, ?User $viewer = null): array
    {
        $visible = $viewer !== null && $this->puedeVerContacto($viewer, $colaborador);
        $veTodos = $viewer !== null && $this->veTodosLosContactos($viewer);
        $telefono = $this->telefonoDe($colaborador);
        $llevaCorporativo = $this->llevaCorreoCorporativo($colaborador);

        return [
            'id' => $colaborador->id,
            'nombre' => $colaborador->nombreCompleto(),
            'numero_empleado' => $colaborador->numero_empleado,
            'puesto' => $colaborador->puesto?->nombre,
            'sucursal' => $colaborador->sucursalPrincipal?->nombre,
            'departamento' => $colaborador->departamento?->nombre,
            // Los gestores no tienen correo corporativo: nunca se muestra uno.
            'correo_corporativo' => $visible && $llevaCorporativo ? $colaborador->correo_corporativo : null,
            'correo_personal' => $visible ? $colaborador->correo_personal : null,
            // El teléfono es el de la persona (la empresa no asigna líneas).
            'telefono' => $visible ? $telefono : null,
            // Compatibilidad con la app ya instalada: mismo teléfono.
            'telefono_corporativo' => $visible ? $telefono : null,
            'lleva_correo_corporativo' => $llevaCorporativo,
            'contacto_visible' => $visible,
            'datos_por_completar' => $veTodos ? $this->tieneDatosPorCompletar($colaborador) : null,
            'es_mi_jefe' => $viewer?->colaborador !== null && $viewer->colaborador->jefe_id === $colaborador->id,
        ];
    }

    /** Teléfono de la persona; el «corporativo» capturado antes solo como respaldo. */
    private function telefonoDe(Colaborador $colaborador): ?string
    {
        foreach ([$colaborador->telefono, $colaborador->telefono_corporativo] as $telefono) {
            if (is_string($telefono) && trim($telefono) !== '') {
                return $telefono;
            }
        }

        return null;
    }

    /** Los gestores no tienen correo corporativo (config/directorio.php). */
    private function llevaCorreoCorporativo(Colaborador $colaborador): bool
    {
        return ! in_array($colaborador->puesto?->grupo_documental, (array) config('directorio.grupos_sin_correo_corporativo', []), true);
    }

    /**
     * Le falta teléfono, correo personal, puesto o sucursal — o el correo
     * corporativo, si su puesto lo lleva (un gestor nunca).
     */
    private function tieneDatosPorCompletar(Colaborador $colaborador): bool
    {
        return $this->telefonoDe($colaborador) === null
            || in_array($colaborador->correo_personal, [null, ''], true)
            || ($this->llevaCorreoCorporativo($colaborador) && in_array($colaborador->correo_corporativo, [null, ''], true))
            || $colaborador->puesto_id === null
            || $colaborador->sucursal_principal_id === null;
    }

    /**
     * Entra al Directorio quien tiene `directorio.ver` (Roles y permisos) o
     * es personal de Corporativo. Misma regla en web, API y menú.
     */
    public function puedeEntrar(User $viewer): bool
    {
        return $viewer->can('directorio.ver') || $this->esCorporativo($viewer);
    }

    /**
     * Círculo de sucursal: quien no ve región ni toda la empresa (gerente,
     * subgerente, coordinadora) ve a su sucursal sin gestores + la gerencia
     * regional + su jefe directo.
     */
    private function veSoloSuCirculo(User $viewer): bool
    {
        return ! in_array($this->alcanceModulo->nivel($viewer, 'directorio'), [AlcanceDatos::Todos, AlcanceDatos::Region], true);
    }

    /** Contactos de TODO el directorio: permiso explícito o personal corporativo. */
    public function veTodosLosContactos(User $viewer): bool
    {
        if ($viewer->can('directorio.contactos.ver_todos')) {
            return true;
        }

        return $this->esCorporativo($viewer);
    }

    private function esCorporativo(User $viewer): bool
    {
        return (bool) ($viewer->colaborador?->sucursalPrincipal->es_corporativo ?? false);
    }

    public function puedeVerContacto(User $viewer, Colaborador $colaborador): bool
    {
        if ($this->veTodosLosContactos($viewer)) {
            return true;
        }

        // Círculo de sucursal: lo que lista ES su círculo de contacto (su
        // gerencia, su coordinadora y la gerencia regional).
        if ($this->veSoloSuCirculo($viewer)) {
            return true;
        }

        $propio = $viewer->colaborador;

        // Alcance de región sin permiso de contactos: el suyo y el de su jefe.
        return $propio !== null && ($propio->id === $colaborador->id || $propio->jefe_id === $colaborador->id);
    }

    /**
     * Filtros llegan de la query string (texto): un id no numérico se
     * ignora en vez de provocar un TypeError.
     *
     * @param  array<string, mixed>  $filtros
     * @return array{busqueda: string|null, puesto_id: int|null, sucursal_id: int|null, departamento_id: int|null, solo_con_correo: bool, solo_por_completar: bool}
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
            'solo_por_completar' => filter_var($filtros['solo_por_completar'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }
}
