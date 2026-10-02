<?php

namespace App\Services\Organigrama;

use App\Enums\EstadoUsuario;
use App\Enums\TipoNodoComercial;
use App\Models\AsignacionNodoComercial;
use App\Models\CoberturaPuesto;
use App\Models\Colaborador;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Colaboradores\FotoColaboradorService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Organigrama POR PERSONAS: una tarjeta por colaborador (no una por puesto
 * con todos sus ocupantes juntos).
 *
 * Tres ámbitos de puesto (config/organigrama.php):
 *  - de SUCURSAL (Gerente de Sucursal, Coordinadora y lo que cuelga de
 *    ellos): cada sucursal es su propia rama; alguien solo cuelga de un
 *    superior de SU sucursal.
 *  - de REGIÓN (Gerente regional): uno por región de la matriz comercial
 *    (Q1, Q3…); una sucursal cuelga del gerente regional de SU región, que
 *    se obtiene de la zona de la matriz ligada a la sucursal.
 *  - CORPORATIVO (el resto): sin partir por sucursal ni región.
 *
 * Cómo se decide de quién cuelga cada persona: se sube por el árbol de
 * puestos; en cada puesto superior se buscan ocupantes de su mismo ámbito
 * (desempate: jefe directo, luego quien comparte ruta — volante ↔ gestor —,
 * luego nombre). Si no hay nadie:
 *  - si alguien lo CUBRE temporalmente (CoberturaPuesto), aparece esa
 *    persona marcada como cobertura — y también sigue en su propio puesto;
 *  - si no, una tarjeta "sin ocupar" en su lugar exacto de la cadena.
 *
 * Solo los gestores tienen ruta (la cartera que cobran): las rutas de la
 * matriz se muestran únicamente en puestos con `requiere_ruta`.
 *
 * @phpstan-type Referencia array{id: int, nombre: string}
 * @phpstan-type NodoOrganigrama array{
 *     clave: string,
 *     padre: string|null,
 *     tipo: 'persona'|'vacante'|'cobertura',
 *     de_sucursal: bool,
 *     puesto: array{id: int, nombre: string, nivel: int|null, tipo_puesto: string|null},
 *     sucursal: Referencia|null,
 *     region: Referencia|null,
 *     persona: array{id: int, nombre: string, foto_url: string|null, numero_empleado: string|null, expediente_url: string, sucursal: string|null}|null,
 *     rutas: list<array{nombre: string, tipo: string}>,
 *     cobertura: array{id: int, motivo: string, motivo_etiqueta: string, desde: string, nota: string|null, titular_de?: string|null}|null
 * }
 * @phpstan-type Ambito array{tipo: 'sucursal'|'region'|'corporativo', sucursal: int|null, region: int|null}
 */
class OrganigramaPersonasService
{
    /** @var Collection<int, Puesto> */
    private Collection $puestos;

    /**
     * Ocupantes activos por puesto_id, en orden alfabético, indexados por
     * id de colaborador (búsquedas O(1) en elegir()).
     *
     * @var array<int, array<int, Colaborador>>
     */
    private array $ocupantes = [];

    /**
     * Mismos ocupantes indexados por "s|puesto|sucursal" y "r|puesto|región":
     * resolverPadre() los consulta por cada persona; filtrar colecciones
     * Eloquent completas por cada una era O(n²) (13 s con ~2,700 personas).
     *
     * @var array<string, array<int, Colaborador>>
     */
    private array $ocupantesPorAmbito = [];

    /** @var array<int, bool> memo de esDeSucursal() por puesto_id */
    private array $deSucursalPorPuesto = [];

    /** @var array<int, list<int>> ids de rutas (nodos) por colaborador */
    private array $rutasPorColaborador = [];

    /** @var array<int, Referencia> región de la matriz por sucursal_id */
    private array $regionPorSucursal = [];

    /**
     * Puestos regionales ligados a su región en la matriz comercial
     * (nodos_comerciales.puesto_id del nodo Región): puesto_id => región y
     * región => puesto_id. "Gerente Regional Q1" siempre es de Q1, sin
     * importar en qué sucursal esté registrado quien lo ocupa.
     *
     * @var array<int, int>
     */
    private array $regionDePuesto = [];

    /** @var array<int, int> */
    private array $puestoDeRegion = [];

    /** @var array<int, string> */
    private array $nombresSucursal = [];

    /** @var array<string, CoberturaPuesto> coberturas vigentes por "puesto|ámbito" */
    private array $coberturas = [];

    /** @var array<string, NodoOrganigrama> */
    private array $nodos = [];

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly FotoColaboradorService $fotos,
        private readonly CoberturaPuestoService $coberturasService,
    ) {}

    /**
     * @return list<NodoOrganigrama>
     */
    public function arbol(User $usuario, Request $request): array
    {
        $this->prepararCatalogos();

        $sucursalesVisibles = $this->sucursalesVisibles($usuario, $request);

        $consulta = Colaborador::query()
            ->where('estatus', 'activo')
            ->whereNotNull('puesto_id')
            ->with('sucursalPrincipal:id,nombre')
            ->when($request->integer('empresa_id'), fn ($query, int $id) => $query->whereHas('sucursalPrincipal', fn ($s) => $s->where('empresa_id', $id)))
            ->when($request->integer('sucursal_id'), fn ($query, int $id) => $query->where('sucursal_principal_id', $id))
            ->when($request->integer('departamento_id'), fn ($query, int $id) => $query->where('departamento_id', $id))
            ->when($request->string('tipo_puesto')->toString(), fn ($query, string $tipo) => $query->whereHas('puesto', fn ($p) => $p->where('tipo_puesto', $tipo)))
            ->orderBy('name')
            ->orderBy('apellidos');

        $colaboradores = $this->alcance->limitarColaboradoresPorAlcance($consulta, $usuario)
            ->get(['id', 'name', 'apellidos', 'numero_empleado', 'foto_path', 'puesto_id', 'sucursal_principal_id', 'jefe_id']);

        $this->construirNodos($colaboradores, $sucursalesVisibles);

        // Sin filtro de sucursal/empresa, la estructura corporativa y
        // regional se ve completa: un puesto sin nadie aparece VACANTE.
        if (! $request->integer('sucursal_id') && ! $request->integer('empresa_id') && ! $request->integer('departamento_id') && $request->string('tipo_puesto')->toString() === '') {
            $this->agregarPuestosVacantes();
        }

        return array_values($this->nodos);
    }

    /**
     * Jefe directo de cada persona vigente SEGÚN EL ORGANIGRAMA (sin
     * alcance ni filtros: la estructura completa). Es la única fuente del
     * jefe directo: nadie lo captura a mano. Si el puesto superior está
     * vacante se sube por la cadena hasta la primera persona (o quien cubre
     * el puesto); null solo para quien encabeza la estructura.
     *
     * @return array<int, int|null> colaborador_id => jefe colaborador_id
     */
    public function jefesDerivados(): array
    {
        $this->prepararCatalogos();

        $colaboradores = Colaborador::query()
            ->whereIn('estatus', EstadoUsuario::valoresVigentes())
            ->whereNotNull('puesto_id')
            ->orderBy('name')
            ->orderBy('apellidos')
            ->get(['id', 'name', 'apellidos', 'numero_empleado', 'foto_path', 'puesto_id', 'sucursal_principal_id', 'jefe_id']);

        $sucursales = Sucursal::query()->where('activo', true)->pluck('id')->map(fn ($id) => (int) $id);

        $this->construirNodos($colaboradores, $sucursales);

        $jefes = [];

        foreach ($colaboradores as $colaborador) {
            $jefes[$colaborador->id] = $this->personaArriba($this->nodos['p'.$colaborador->id]['padre'] ?? null, $colaborador->id);
        }

        return $jefes;
    }

    /**
     * Primera persona real (titular o quien cubre) subiendo desde `$clave`.
     */
    private function personaArriba(?string $clave, int $excluirId): ?int
    {
        $visitados = [];

        while ($clave !== null && ! isset($visitados[$clave]) && isset($this->nodos[$clave])) {
            $visitados[$clave] = true;
            $nodo = $this->nodos[$clave];
            $personaId = $nodo['persona']['id'] ?? null;

            if ($personaId !== null && $personaId !== $excluirId) {
                return $personaId;
            }

            $clave = $nodo['padre'];
        }

        return null;
    }

    private function prepararCatalogos(): void
    {
        $this->nodos = [];
        $this->puestos = Puesto::query()
            ->get(['id', 'nombre', 'nivel_jerarquico', 'tipo_puesto', 'puesto_superior_id', 'requiere_ruta', 'activo'])
            ->keyBy('id');
        $this->cargarRegiones();
    }

    /**
     * @param  Collection<int, Colaborador>  $colaboradores
     * @param  Collection<int, int>  $sucursalesVisibles
     */
    private function construirNodos(Collection $colaboradores, Collection $sucursalesVisibles): void
    {
        $this->ocupantes = [];
        $this->ocupantesPorAmbito = [];
        $this->deSucursalPorPuesto = [];

        foreach ($colaboradores as $colaborador) {
            $puestoId = (int) $colaborador->puesto_id;
            $this->ocupantes[$puestoId][$colaborador->id] = $colaborador;
            $this->ocupantesPorAmbito[$this->llaveOcupantes('s', $puestoId, $colaborador->sucursal_principal_id)][$colaborador->id] = $colaborador;
            $this->ocupantesPorAmbito[$this->llaveOcupantes('r', $puestoId, $this->regionDePuesto[$puestoId] ?? $this->regionDe($colaborador->sucursal_principal_id)['id'] ?? null)][$colaborador->id] = $colaborador;
        }

        $rutas = $this->cargarRutas($colaboradores->pluck('id'));
        $this->cargarCoberturas($sucursalesVisibles);

        foreach ($colaboradores as $colaborador) {
            $puesto = $this->puestos->get((int) $colaborador->puesto_id);

            if ($puesto === null) {
                continue;
            }

            $sucursalId = $colaborador->sucursal_principal_id;

            $this->nodos['p'.$colaborador->id] = [
                'clave' => 'p'.$colaborador->id,
                'padre' => null,
                'tipo' => 'persona',
                'de_sucursal' => $this->esDeSucursal($puesto),
                'puesto' => $this->datosPuesto($puesto),
                'sucursal' => $this->referenciaSucursal($sucursalId),
                'region' => $this->esDeRegion($puesto)
                    ? (isset($this->regionDePuesto[$puesto->id]) ? $this->regionPorId($this->regionDePuesto[$puesto->id]) : $this->regionDe($sucursalId))
                    : null,
                'persona' => $this->datosPersona($colaborador),
                'rutas' => $puesto->requiere_ruta ? ($rutas[$colaborador->id] ?? []) : [],
                'cobertura' => null,
            ];
        }

        foreach ($colaboradores as $colaborador) {
            $clave = 'p'.$colaborador->id;

            if (! isset($this->nodos[$clave])) {
                continue;
            }

            $puesto = $this->puestos->get((int) $colaborador->puesto_id);
            $sucursalId = $colaborador->sucursal_principal_id;

            $this->nodos[$clave]['padre'] = $this->resolverPadre(
                $puesto?->puesto_superior_id,
                $sucursalId,
                $this->regionDe($sucursalId)['id'] ?? null,
                $colaborador,
            );
        }

        $this->agregarSucursalesSinPersonal($sucursalesVisibles);
        $this->agregarCoberturasRestantes();
    }

    /**
     * Puestos activos NO de sucursal (corporativos y regionales) que nadie
     * ocupa ni cubre: se muestran como VACANTE en su lugar del árbol en vez
     * de desaparecer (p. ej. "Gerente Regional Q3", "Asistente de Dirección
     * Comercial" sin titular).
     */
    private function agregarPuestosVacantes(): void
    {
        $presentes = [];

        foreach ($this->nodos as $nodo) {
            $presentes[$nodo['puesto']['id']] = true;
        }

        foreach ($this->puestos as $puesto) {
            if (! $puesto->activo || isset($presentes[$puesto->id]) || $this->esDeSucursal($puesto) || ($this->ocupantes[$puesto->id] ?? []) !== []) {
                continue;
            }

            $region = $this->regionDePuesto[$puesto->id] ?? null;

            // Genérico de región sin región propia ligada (puesto anterior):
            // no hay en qué región pintarlo.
            if ($region === null && $this->esDeRegion($puesto)) {
                continue;
            }

            // resolverPadre() crea la tarjeta VACANTE (o la de quien lo
            // cubre) del propio puesto y la cuelga de su superior.
            $this->resolverPadre($puesto->id, null, $region, null);
            $presentes[$puesto->id] = true;
        }
    }

    /**
     * Personas que se pueden elegir para cubrir un puesto (activas, dentro
     * del alcance de quien asigna), con su puesto y sucursal para
     * reconocerlas en el selector.
     *
     * @return list<array{value: string, label: string}>
     */
    public function candidatosCobertura(User $usuario): array
    {
        $consulta = Colaborador::query()
            ->where('estatus', 'activo')
            ->whereNotNull('puesto_id')
            ->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre'])
            ->orderBy('name')
            ->orderBy('apellidos');

        $opciones = [];

        foreach ($this->alcance->limitarColaboradoresPorAlcance($consulta, $usuario)->get(['id', 'name', 'apellidos', 'puesto_id', 'sucursal_principal_id']) as $c) {
            $opciones[] = [
                'value' => sprintf('%d', $c->id),
                'label' => sprintf(
                    '%s — %s%s',
                    trim(sprintf('%s %s', $c->name, $c->apellidos ?? '')),
                    $c->puesto->nombre ?? 'Sin puesto',
                    $c->sucursalPrincipal !== null ? sprintf(' · %s', $c->sucursalPrincipal->nombre) : '',
                ),
            ];
        }

        return $opciones;
    }

    /**
     * Clave del nodo del que cuelga quien ocupa un puesto cuyo superior es
     * `$puestoId`, en el contexto (sucursal/región) de quien pregunta.
     *
     * @param  list<int>  $visitados  protección contra ciclos en puesto_superior_id
     */
    private function resolverPadre(?int $puestoId, ?int $sucursalId, ?int $regionId, ?Colaborador $hijo, array $visitados = []): ?string
    {
        if ($puestoId === null || in_array($puestoId, $visitados, true)) {
            return null;
        }

        // Quien reporta a "un gerente regional" reporta al de SU región.
        $puestoId = $this->puestoRegionalPara($puestoId, $regionId ?? ($this->regionDe($sucursalId)['id'] ?? null));

        $puesto = $this->puestos->get($puestoId);

        if ($puesto === null) {
            return null;
        }

        $visitados[] = $puestoId;
        $ambito = $this->ambito($puesto, $sucursalId, $regionId);

        $ocupantes = match ($ambito['tipo']) {
            'sucursal' => $this->ocupantesPorAmbito[$this->llaveOcupantes('s', $puestoId, $ambito['sucursal'])] ?? [],
            'region' => $this->ocupantesPorAmbito[$this->llaveOcupantes('r', $puestoId, $ambito['region'])] ?? [],
            default => $this->ocupantes[$puestoId] ?? [],
        };

        $elegido = $this->elegir($ocupantes, $hijo);

        if ($elegido !== null) {
            return 'p'.$elegido->id;
        }

        $cobertura = $this->coberturas[$this->llave($puestoId, $ambito)] ?? null;

        if ($cobertura !== null) {
            return $this->nodoCobertura($cobertura, $puesto, $ambito, $visitados);
        }

        // Nadie ni cobertura: tarjeta "sin ocupar" en su lugar de la cadena,
        // creada una sola vez y colgada de quien corresponda más arriba.
        $clave = 'v'.$this->llave($puestoId, $ambito);

        if (! isset($this->nodos[$clave])) {
            $this->nodos[$clave] = [
                'clave' => $clave,
                'padre' => null,
                'tipo' => 'vacante',
                'de_sucursal' => $ambito['tipo'] === 'sucursal',
                'puesto' => $this->datosPuesto($puesto),
                'sucursal' => $ambito['tipo'] === 'sucursal' ? $this->referenciaSucursal($ambito['sucursal']) : null,
                'region' => $ambito['tipo'] === 'region' ? $this->regionPorId($ambito['region']) : null,
                'persona' => null,
                'rutas' => [],
                'cobertura' => null,
            ];
            $this->nodos[$clave]['padre'] = $this->resolverPadre(
                $puesto->puesto_superior_id,
                $ambito['sucursal'],
                $ambito['region'],
                null,
                $visitados,
            );
        }

        return $clave;
    }

    /**
     * @param  Ambito  $ambito
     * @param  list<int>  $visitados
     */
    private function nodoCobertura(CoberturaPuesto $cobertura, Puesto $puesto, array $ambito, array $visitados = []): string
    {
        $clave = 'c'.$cobertura->id;

        if (isset($this->nodos[$clave])) {
            return $clave;
        }

        $this->nodos[$clave] = [
            'clave' => $clave,
            'padre' => null,
            'tipo' => 'cobertura',
            'de_sucursal' => $ambito['tipo'] === 'sucursal',
            'puesto' => $this->datosPuesto($puesto),
            'sucursal' => $ambito['tipo'] === 'sucursal' ? $this->referenciaSucursal($ambito['sucursal']) : null,
            'region' => $ambito['tipo'] === 'region' ? $this->regionPorId($ambito['region']) : null,
            'persona' => $this->datosPersona($cobertura->colaborador),
            'rutas' => [],
            'cobertura' => [
                'id' => $cobertura->id,
                'motivo' => $cobertura->motivo->value,
                'motivo_etiqueta' => $cobertura->motivo->etiqueta(),
                'desde' => $cobertura->fecha_inicio->toDateString(),
                'nota' => $cobertura->nota,
                // "Cubierto temporalmente por X · Titular de <su puesto>":
                // la cobertura no cambia su puesto titular.
                'titular_de' => $cobertura->colaborador->puesto?->nombre,
            ],
        ];
        $this->nodos[$clave]['padre'] = $this->resolverPadre(
            $puesto->puesto_superior_id,
            $ambito['sucursal'],
            $ambito['region'],
            null,
            [...$visitados, $puesto->id],
        );

        return $clave;
    }

    /**
     * "Deben estar todas las sucursales": una sucursal visible sin nadie en
     * puestos de sucursal aparece igual, con el puesto que encabeza las
     * demás sucursales "sin ocupar" (o con quien lo cubre). Ese puesto es el
     * que más veces aparece como cabeza de rama de sucursal en los datos.
     *
     * @param  Collection<int, int>  $sucursalesVisibles
     */
    private function agregarSucursalesSinPersonal(Collection $sucursalesVisibles): void
    {
        $cabezas = collect($this->nodos)
            ->filter(fn (array $nodo) => $nodo['de_sucursal']
                && ($nodo['padre'] === null || ! ($this->nodos[$nodo['padre']]['de_sucursal'] ?? false)))
            ->countBy(fn (array $nodo) => $nodo['puesto']['id']);

        if ($cabezas->isEmpty()) {
            return;
        }

        $puestoCabeza = $this->puestos->get((int) $cabezas->sortDesc()->keys()->first());

        if ($puestoCabeza === null) {
            return;
        }

        $conPersonal = collect($this->nodos)
            ->filter(fn (array $nodo) => $nodo['de_sucursal'])
            ->map(fn (array $nodo) => $nodo['sucursal']['id'] ?? null)
            ->filter()
            ->unique();

        foreach ($sucursalesVisibles->diff($conPersonal) as $sucursalId) {
            $ambito = ['tipo' => 'sucursal', 'sucursal' => $sucursalId, 'region' => $this->regionDe($sucursalId)['id'] ?? null];
            $cobertura = $this->coberturas[$this->llave($puestoCabeza->id, $ambito)] ?? null;

            if ($cobertura !== null) {
                $this->nodoCobertura($cobertura, $puestoCabeza, $ambito);

                continue;
            }

            $clave = 'v'.$this->llave($puestoCabeza->id, $ambito);
            $this->nodos[$clave] = [
                'clave' => $clave,
                'padre' => $this->resolverPadre($puestoCabeza->puesto_superior_id, $sucursalId, $ambito['region'], null, [$puestoCabeza->id]),
                'tipo' => 'vacante',
                'de_sucursal' => true,
                'puesto' => $this->datosPuesto($puestoCabeza),
                'sucursal' => $this->referenciaSucursal($sucursalId),
                'region' => null,
                'persona' => null,
                'rutas' => [],
                'cobertura' => null,
            ];
        }
    }

    /**
     * Una cobertura vigente siempre se ve, aunque ninguna rama la haya
     * alcanzado (p. ej. cubre un puesto sin subordinados cargados).
     */
    private function agregarCoberturasRestantes(): void
    {
        foreach ($this->coberturas as $cobertura) {
            $puesto = $this->puestos->get($cobertura->puesto_id);

            if ($puesto === null || isset($this->nodos['c'.$cobertura->id])) {
                continue;
            }

            $ambito = $cobertura->region_id !== null
                ? ['tipo' => 'region', 'sucursal' => null, 'region' => $cobertura->region_id]
                : ['tipo' => 'sucursal', 'sucursal' => $cobertura->sucursal_id, 'region' => $this->regionDe($cobertura->sucursal_id)['id'] ?? null];

            $this->nodoCobertura($cobertura, $puesto, $ambito);
        }
    }

    /**
     * @return Ambito
     */
    private function ambito(Puesto $puesto, ?int $sucursalId, ?int $regionId): array
    {
        if ($sucursalId !== null && $this->esDeSucursal($puesto)) {
            return ['tipo' => 'sucursal', 'sucursal' => $sucursalId, 'region' => $regionId];
        }

        $region = $this->regionDePuesto[$puesto->id] ?? $regionId ?? ($this->regionDe($sucursalId)['id'] ?? null);

        if ($region !== null && $this->esDeRegion($puesto)) {
            return ['tipo' => 'region', 'sucursal' => null, 'region' => $region];
        }

        return ['tipo' => 'corporativo', 'sucursal' => null, 'region' => null];
    }

    /**
     * @param  Ambito  $ambito
     */
    private function llave(int $puestoId, array $ambito): string
    {
        return match ($ambito['tipo']) {
            'sucursal' => sprintf('%d-s%d', $puestoId, $ambito['sucursal']),
            'region' => sprintf('%d-r%d', $puestoId, $ambito['region']),
            default => (string) $puestoId,
        };
    }

    /**
     * Superior de `$hijo` entre los ocupantes del puesto (sin contarlo a él
     * mismo): su jefe directo si está ahí, si no quien comparte ruta con
     * él, si no el primero en orden alfabético. Null si no hay nadie más.
     * Recorre arreglos indexados por id (nada de Collection::where sobre
     * modelos Eloquent, que era el cuello de botella).
     *
     * @param  array<int, Colaborador>  $candidatos  indexados por id, en orden alfabético
     */
    private function elegir(array $candidatos, ?Colaborador $hijo): ?Colaborador
    {
        $hijoId = $hijo?->id;

        if ($hijo !== null) {
            $jefeId = $hijo->jefe_id;

            if ($jefeId !== null && $jefeId !== $hijoId && isset($candidatos[$jefeId])) {
                return $candidatos[$jefeId];
            }

            $rutasHijo = $this->rutasPorColaborador[$hijoId] ?? [];

            if ($rutasHijo !== []) {
                foreach ($candidatos as $id => $candidato) {
                    if ($id !== $hijoId && array_intersect($this->rutasPorColaborador[$id] ?? [], $rutasHijo) !== []) {
                        return $candidato;
                    }
                }
            }
        }

        foreach ($candidatos as $id => $candidato) {
            if ($id !== $hijoId) {
                return $candidato;
            }
        }

        return null;
    }

    /**
     * Rutas activas de la Matriz comercial por colaborador. Se usan para
     * mostrarlas en la tarjeta del gestor y para emparejar volante ↔ gestor.
     *
     * @param  Collection<int, int>  $ids
     * @return array<int, list<array{nombre: string, tipo: string}>>
     */
    private function cargarRutas(Collection $ids): array
    {
        $this->rutasPorColaborador = [];
        $porColaborador = [];

        $asignaciones = AsignacionNodoComercial::query()
            ->whereIn('colaborador_id', $ids)
            ->where('activo', true)
            ->with('nodo:id,nombre')
            ->get(['id', 'colaborador_id', 'nodo_comercial_id', 'tipo_asignacion']);

        foreach ($asignaciones as $asignacion) {
            if ($asignacion->colaborador_id === null || $asignacion->nodo === null) {
                continue;
            }

            $this->rutasPorColaborador[$asignacion->colaborador_id][] = $asignacion->nodo_comercial_id;
            $porColaborador[$asignacion->colaborador_id][] = [
                'nombre' => $asignacion->nodo->nombre,
                'tipo' => $asignacion->tipo_asignacion->value,
            ];
        }

        return $porColaborador;
    }

    /**
     * Región de cada sucursal según la matriz comercial (zona ligada a la
     * sucursal → su región padre).
     */
    private function cargarRegiones(): void
    {
        $this->regionPorSucursal = [];
        $this->regionDePuesto = [];
        $this->puestoDeRegion = [];

        foreach (NodoComercial::query()->where('tipo', TipoNodoComercial::Region->value)->whereNotNull('puesto_id')->get(['id', 'puesto_id']) as $region) {
            $this->regionDePuesto[(int) $region->puesto_id] = $region->id;
            $this->puestoDeRegion[$region->id] = (int) $region->puesto_id;
        }
        $this->nombresSucursal = Sucursal::query()->pluck('nombre', 'id')->all();

        $zonas = NodoComercial::query()
            ->where('tipo', TipoNodoComercial::Zona->value)
            ->whereNotNull('sucursal_id')
            ->with('padre:id,nombre,tipo')
            ->get(['id', 'parent_id', 'sucursal_id']);

        foreach ($zonas as $zona) {
            if ($zona->sucursal_id !== null && $zona->padre !== null && $zona->padre->tipo === TipoNodoComercial::Region) {
                $this->regionPorSucursal[$zona->sucursal_id] = ['id' => $zona->padre->id, 'nombre' => $zona->padre->nombre];
            }
        }
    }

    /**
     * @param  Collection<int, int>  $sucursalesVisibles
     */
    private function cargarCoberturas(Collection $sucursalesVisibles): void
    {
        $this->coberturas = [];
        $regionesVisibles = $sucursalesVisibles
            ->map(fn (int $id) => $this->regionDe($id)['id'] ?? null)
            ->filter()
            ->unique();

        foreach ($this->coberturasService->vigentes() as $cobertura) {
            $puesto = $this->puestos->get($cobertura->puesto_id);

            if ($puesto === null) {
                continue;
            }

            if ($cobertura->region_id !== null) {
                if (in_array($cobertura->region_id, $regionesVisibles->all(), true)) {
                    $this->coberturas[$this->llave($puesto->id, ['tipo' => 'region', 'sucursal' => null, 'region' => $cobertura->region_id])] = $cobertura;
                }

                continue;
            }

            if ($cobertura->sucursal_id !== null && $sucursalesVisibles->contains($cobertura->sucursal_id)) {
                $this->coberturas[$this->llave($puesto->id, ['tipo' => 'sucursal', 'sucursal' => $cobertura->sucursal_id, 'region' => null])] = $cobertura;
            }
        }
    }

    /**
     * Sucursales activas dentro del alcance y de los filtros de la pantalla.
     *
     * @return Collection<int, int>
     */
    private function sucursalesVisibles(User $usuario, Request $request): Collection
    {
        $consulta = Sucursal::query()
            ->where('activo', true)
            ->when($request->integer('empresa_id'), fn ($query, int $id) => $query->where('empresa_id', $id))
            ->when($request->integer('sucursal_id'), fn ($query, int $id) => $query->where('id', $id))
            ->orderBy('nombre');

        return $this->alcance->limitarPorSucursal($consulta, $usuario, 'id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
    }

    /**
     * De sucursal = es uno de los puestos raíz de sucursal (config
     * organigrama.puestos_raiz_sucursal) o cuelga de alguno de ellos.
     */
    private function esDeSucursal(Puesto $puesto): bool
    {
        return $this->deSucursalPorPuesto[$puesto->id] ??= $this->calcularEsDeSucursal($puesto);
    }

    private function calcularEsDeSucursal(Puesto $puesto): bool
    {
        /** @var list<string> $raices */
        $raices = config('organigrama.puestos_raiz_sucursal', []);
        $visitados = [];
        $actual = $puesto;

        while ($actual !== null && ! in_array($actual->id, $visitados, true)) {
            if (in_array($actual->nombre, $raices, true)) {
                return true;
            }

            $visitados[] = $actual->id;
            $actual = $actual->puesto_superior_id !== null ? $this->puestos->get($actual->puesto_superior_id) : null;
        }

        return false;
    }

    /**
     * Si $puestoId es un puesto regional y la región de quien pregunta tiene
     * su propio puesto regional, regresa ese (Gerente de Sucursal de una
     * sucursal de Q3 → "Gerente Regional Q3", aunque el catálogo lo cuelgue
     * de "Gerente Regional Q1" como superior de referencia).
     */
    private function puestoRegionalPara(int $puestoId, ?int $regionId): int
    {
        if (! isset($this->regionDePuesto[$puestoId]) || $regionId === null) {
            return $puestoId;
        }

        return $this->puestoDeRegion[$regionId] ?? $puestoId;
    }

    private function esDeRegion(Puesto $puesto): bool
    {
        if (isset($this->regionDePuesto[$puesto->id])) {
            return true;
        }

        /** @var list<string> $deRegion */
        $deRegion = config('organigrama.puestos_de_region', []);

        return in_array($puesto->nombre, $deRegion, true);
    }

    private function llaveOcupantes(string $tipo, int $puestoId, ?int $ambitoId): string
    {
        return sprintf('%s|%d|%s', $tipo, $puestoId, $ambitoId ?? '-');
    }

    /**
     * @return Referencia|null
     */
    private function regionDe(?int $sucursalId): ?array
    {
        return $sucursalId !== null ? ($this->regionPorSucursal[$sucursalId] ?? null) : null;
    }

    /**
     * @return Referencia|null
     */
    private function regionPorId(?int $regionId): ?array
    {
        foreach ($this->regionPorSucursal as $region) {
            if ($region['id'] === $regionId) {
                return $region;
            }
        }

        $nombre = $regionId !== null ? NodoComercial::query()->whereKey($regionId)->value('nombre') : null;

        return $regionId !== null && is_string($nombre) ? ['id' => $regionId, 'nombre' => $nombre] : null;
    }

    /**
     * @return Referencia|null
     */
    private function referenciaSucursal(?int $sucursalId): ?array
    {
        return $sucursalId !== null && isset($this->nombresSucursal[$sucursalId])
            ? ['id' => $sucursalId, 'nombre' => $this->nombresSucursal[$sucursalId]]
            : null;
    }

    /**
     * @return array{id: int, nombre: string, nivel: int|null, tipo_puesto: string|null}
     */
    private function datosPuesto(Puesto $puesto): array
    {
        return [
            'id' => $puesto->id,
            'nombre' => $puesto->nombre,
            'nivel' => $puesto->nivel_jerarquico,
            'tipo_puesto' => $puesto->tipo_puesto?->value,
        ];
    }

    /**
     * @return array{id: int, nombre: string, foto_url: string|null, numero_empleado: string|null, expediente_url: string, sucursal: string|null}
     */
    private function datosPersona(Colaborador $colaborador): array
    {
        return [
            'id' => $colaborador->id,
            'nombre' => trim(sprintf('%s %s', $colaborador->name, $colaborador->apellidos ?? '')),
            'foto_url' => $this->fotos->url($colaborador),
            'numero_empleado' => $colaborador->numero_empleado,
            'expediente_url' => route('rh.expedientes.show', $colaborador->id, false),
            'sucursal' => $this->nombresSucursal[$colaborador->sucursal_principal_id ?? 0] ?? null,
        ];
    }
}
