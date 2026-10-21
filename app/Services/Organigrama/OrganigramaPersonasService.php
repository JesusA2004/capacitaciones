<?php

namespace App\Services\Organigrama;

use App\Enums\EstadoUsuario;
use App\Enums\TipoNodoComercial;
use App\Models\AsignacionNodoComercial;
use App\Models\ClasificacionRegion;
use App\Models\CoberturaPuesto;
use App\Models\Colaborador;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Colaboradores\FotoColaboradorService;
use App\Services\Expedientes\MigracionInicial\Normalizador;
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
 *     cobertura: array{id: int, motivo: string, motivo_etiqueta: string, desde: string, nota: string|null, titular_de?: string|null, registrada_por?: string|null, registrada_en?: string|null}|null,
 *     clasificacion?: Clasificacion|null
 * }
 * @phpstan-type Clasificacion array{estado: 'clasificado'|'por_clasificar', region: Referencia|null, sugerida: Referencia|null, clasificada_por: string|null, clasificada_en: string|null}
 * @phpstan-type Simulacion array<int, array{puesto_id: int, sucursal_principal_id: int|null, region_id?: int|null}>
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

    /**
     * Lado Q1/Q3 que RH clasificó por colaborador (clasificaciones_region):
     * colaborador_id => región. Solo cuenta para puestos clasificables
     * (los que reportan a «el gerente regional de su región»).
     *
     * @var array<int, int>
     */
    private array $regionClasificada = [];

    /** @var array<int, array{por: string|null, en: string|null}> */
    private array $auditoriaClasificacion = [];

    /**
     * Puestos que NUNCA son jefe del personal de sucursal: Dirección
     * Comercial (ciclo_laboral.organizacion.puestos_direccion_comercial) y
     * todo lo que está arriba de ella. Memo por carga de catálogos.
     *
     * @var array<int, true>|null
     */
    private ?array $puestosTope = null;

    /** @var list<int>|null candidatos a regional de respaldo (memo por carga) */
    private ?array $candidatosRespaldo = null;

    /** @var array<int, true> personal de sucursal sin jefe asignable en el último cálculo */
    private array $sinJefeAsignable = [];

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly FotoColaboradorService $fotos,
        private readonly CoberturaPuestoService $coberturasService,
        private readonly MiniOrganigramaService $miniOrganigrama,
        private readonly RegionalRespaldoService $regionalRespaldo,
    ) {}

    /**
     * @return list<NodoOrganigrama>
     */
    public function arbol(User $usuario, Request $request): array
    {
        $this->prepararCatalogos();

        $sucursalesVisibles = $this->sucursalesVisibles($usuario, $request);
        $raizPuestoId = $request->integer('raiz_puesto_id');

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
        // regional se ve completa: un puesto sin nadie aparece VACANTE (un
        // mini-organigrama por raíz también quiere ver sus vacantes; se
        // recortan después junto con el resto del árbol).
        if (! $request->integer('sucursal_id') && ! $request->integer('empresa_id') && ! $request->integer('departamento_id') && $request->string('tipo_puesto')->toString() === '') {
            $this->agregarPuestosVacantes();
        }

        if ($raizPuestoId !== 0) {
            return $this->recortarPorRaiz($raizPuestoId);
        }

        return array_values($this->nodos);
    }

    /**
     * Mini-organigrama: solo el puesto raíz y los que cuelgan de él
     * (MiniOrganigramaService, sobre el catálogo real de Puesto). El/los
     * nodo(s) de la raíz quedan sin padre para que el frontend los pinte
     * como la cabeza de ese árbol recortado.
     *
     * @return list<NodoOrganigrama>
     */
    private function recortarPorRaiz(int $raizPuestoId): array
    {
        $idsPermitidos = $this->miniOrganigrama->descendientes($raizPuestoId);

        $recortados = array_values(array_filter(
            $this->nodos,
            fn (array $nodo) => in_array($nodo['puesto']['id'], $idsPermitidos, true),
        ));

        foreach ($recortados as &$nodo) {
            if ($nodo['puesto']['id'] === $raizPuestoId) {
                $nodo['padre'] = null;
            }
        }

        return $recortados;
    }

    /**
     * Jefe directo de cada persona vigente SEGÚN EL ORGANIGRAMA (sin
     * alcance ni filtros: la estructura completa). Es la única fuente del
     * jefe directo: nadie lo captura a mano. Si el puesto superior está
     * vacante se sube por la cadena hasta la primera persona (o quien cubre
     * el puesto); null para quien encabeza la estructura.
     *
     * Tope del personal de SUCURSAL (regla del dueño, 2026-10-21): su jefe
     * nunca es Dirección Comercial ni nadie arriba de ella. Si la cadena
     * llega ahí (gerencia de su sucursal y regional de su región vacantes),
     * su jefe es la regional de respaldo (RegionalRespaldoService); si no
     * existe ningún regional queda null y se marca «sin jefe asignable»
     * (sinJefeAsignable()).
     *
     * `$simulacion` (vista previa de «Vas a mover a X…»): aplica en memoria,
     * sin guardar nada, otro puesto/sucursal/región a esas personas.
     *
     * @param  Simulacion  $simulacion
     * @return array<int, int|null> colaborador_id => jefe colaborador_id
     */
    public function jefesDerivados(array $simulacion = []): array
    {
        $colaboradores = $this->construirEstructuraCompleta($simulacion);

        $jefes = [];
        $this->sinJefeAsignable = [];

        foreach ($colaboradores as $colaborador) {
            $resolucion = $this->resolverJefe($colaborador->id);
            $jefes[$colaborador->id] = $resolucion['jefe_id'];

            if ($resolucion['sin_jefe_asignable']) {
                $this->sinJefeAsignable[$colaborador->id] = true;
            }
        }

        return $jefes;
    }

    /**
     * Personal de sucursal que en el último jefesDerivados() quedó sin jefe
     * porque no hay gerencia, ni regional de su región, ni regional de
     * respaldo (nadie vigente en un puesto regional).
     *
     * @return list<int>
     */
    public function sinJefeAsignable(): array
    {
        return array_keys($this->sinJefeAsignable);
    }

    /**
     * Decisión del jefe de UNA persona sobre la estructura ya construida:
     * la primera persona arriba en la cadena, salvo que sea personal de
     * sucursal y esa persona sea Dirección Comercial (o superior) o no haya
     * nadie: entonces la regional de respaldo.
     *
     * @return array{jefe_id: int|null, descartado: string|null, respaldo: bool, sin_jefe_asignable: bool}
     */
    private function resolverJefe(int $colaboradorId): array
    {
        $nodo = $this->nodos['p'.$colaboradorId] ?? null;

        if ($nodo === null) {
            return ['jefe_id' => null, 'descartado' => null, 'respaldo' => false, 'sin_jefe_asignable' => false];
        }

        $clave = $this->claveDePersonaArriba($nodo['padre'], $colaboradorId);
        $arriba = $clave !== null ? $this->nodos[$clave] : null;

        if (! $nodo['de_sucursal'] || ($arriba !== null && ! isset($this->puestosTope()[$arriba['puesto']['id']]))) {
            return ['jefe_id' => $arriba['persona']['id'] ?? null, 'descartado' => null, 'respaldo' => false, 'sin_jefe_asignable' => false];
        }

        // Personal de sucursal cuya cadena llegó a Dirección Comercial (o a
        // nadie): la regional de respaldo, nunca Dirección Comercial.
        $this->candidatosRespaldo ??= $this->regionalRespaldo->candidatos();
        $respaldo = null;

        foreach ($this->candidatosRespaldo as $candidato) {
            // Solo quien sigue vigente en ESTA estructura (una vista previa
            // pudo moverlo en memoria).
            if ($candidato !== $colaboradorId && isset($this->nodos['p'.$candidato])) {
                $respaldo = $candidato;

                break;
            }
        }

        return [
            'jefe_id' => $respaldo,
            'descartado' => $clave,
            'respaldo' => $respaldo !== null,
            'sin_jefe_asignable' => $respaldo === null,
        ];
    }

    /**
     * Puestos tope para el personal de sucursal: Dirección Comercial y sus
     * superiores (Dirección General).
     *
     * @return array<int, true>
     */
    private function puestosTope(): array
    {
        if ($this->puestosTope !== null) {
            return $this->puestosTope;
        }

        /** @var list<string> $nombres */
        $nombres = (array) config('ciclo_laboral.organizacion.puestos_direccion_comercial', []);
        $tope = [];

        foreach ($this->puestos as $puesto) {
            if (! $this->nombreEnLista($puesto->nombre, $nombres)) {
                continue;
            }

            $actual = $puesto;

            while ($actual !== null && ! isset($tope[$actual->id])) {
                $tope[$actual->id] = true;
                $actual = $actual->puesto_superior_id !== null ? $this->puestos->get($actual->puesto_superior_id) : null;
            }
        }

        return $this->puestosTope = $tope;
    }

    /**
     * Por qué una persona tiene el jefe que tiene: la cadena del organigrama
     * desde su puesto hacia arriba, nivel por nivel (titular, cobertura o
     * VACANTE) hasta la primera persona — la misma que usa jefesDerivados().
     * En cada plaza vacante se listan quienes TIENEN ese puesto en ese ámbito
     * pero no cuentan porque su estatus no es vigente (baja, suspendido…),
     * para distinguir «no hay nadie» de «hay alguien mal registrado».
     * Si la cadena del personal de sucursal llega a Dirección Comercial, ese
     * paso sale marcado como descartado y se agrega el de la regional de
     * respaldo (tipo «respaldo») o se avisa «sin jefe asignable».
     * Diagnóstico para `organigrama:explicar-jefe` (solo lectura).
     *
     * @return array{vigente: bool, pasos: list<array{tipo: string, puesto: string, ambito: string, persona_id: int|null, persona: string|null, no_vigentes: list<string>, nota: string|null}>, jefe_id: int|null, respaldo: bool, sin_jefe_asignable: bool}
     */
    public function explicarJefe(int $colaboradorId): array
    {
        $this->construirEstructuraCompleta([]);
        $nodo = $this->nodos['p'.$colaboradorId] ?? null;

        if ($nodo === null) {
            return ['vigente' => false, 'pasos' => [], 'jefe_id' => null, 'respaldo' => false, 'sin_jefe_asignable' => false];
        }

        $resolucion = $this->resolverJefe($colaboradorId);
        $pasos = [];
        $visitados = [];
        $clave = $nodo['padre'];

        while ($clave !== null && ! isset($visitados[$clave]) && isset($this->nodos[$clave])) {
            $visitados[$clave] = true;
            $actual = $this->nodos[$clave];
            $personaId = $actual['persona']['id'] ?? null;
            $esJefe = $personaId !== null && $personaId !== $colaboradorId;

            $pasos[] = [
                'tipo' => $actual['tipo'],
                'puesto' => $actual['puesto']['nombre'],
                'ambito' => $actual['region']['nombre'] ?? ($actual['de_sucursal'] ? $actual['sucursal']['nombre'] ?? null : null) ?? 'Corporativo',
                'persona_id' => $personaId,
                'persona' => $actual['persona']['nombre'] ?? null,
                'no_vigentes' => $actual['tipo'] === 'vacante' ? $this->ocupantesNoVigentes($actual) : [],
                'nota' => $clave === $resolucion['descartado'] ? 'No aplica: el personal de sucursal nunca reporta a Dirección Comercial' : null,
            ];

            if ($esJefe) {
                break;
            }

            $clave = $actual['padre'];
        }

        if ($resolucion['respaldo'] && $resolucion['jefe_id'] !== null) {
            $respaldo = $this->nodos['p'.$resolucion['jefe_id']] ?? null;
            $pasos[] = [
                'tipo' => 'respaldo',
                'puesto' => $respaldo['puesto']['nombre'] ?? '',
                'ambito' => $respaldo['region']['nombre'] ?? 'Regional',
                'persona_id' => $resolucion['jefe_id'],
                'persona' => $respaldo['persona']['nombre'] ?? null,
                'no_vigentes' => [],
                'nota' => 'Regional de respaldo: la región de su sucursal no tiene Gerente Regional ni cobertura (config organigrama.regional_respaldo)',
            ];
        }

        return [
            'vigente' => true,
            'pasos' => $pasos,
            'jefe_id' => $resolucion['jefe_id'],
            'respaldo' => $resolucion['respaldo'],
            'sin_jefe_asignable' => $resolucion['sin_jefe_asignable'],
        ];
    }

    /**
     * Quienes tienen el puesto de una plaza VACANTE en su mismo ámbito pero
     * no cuentan para el organigrama (estatus no vigente).
     *
     * @param  NodoOrganigrama  $nodo
     * @return list<string>
     */
    private function ocupantesNoVigentes(array $nodo): array
    {
        $consulta = Colaborador::query()
            ->where('puesto_id', $nodo['puesto']['id'])
            ->whereNotIn('estatus', EstadoUsuario::valoresVigentes())
            ->orderBy('name');

        if ($nodo['sucursal'] !== null) {
            $consulta->where('sucursal_principal_id', $nodo['sucursal']['id']);
        }

        $nombres = [];

        foreach ($consulta->get(['id', 'name', 'apellidos', 'estatus']) as $c) {
            $nombres[] = sprintf('%s (%s)', $c->nombreCompleto(), $c->estatus->etiqueta());
        }

        return $nombres;
    }

    /**
     * Todos los nodos de la estructura completa (sin alcance ni filtros):
     * para elegir el destino de «Mover bajo…».
     *
     * @return array<string, NodoOrganigrama>
     */
    public function nodosEstructura(): array
    {
        $this->construirEstructuraCompleta([]);

        return $this->nodos;
    }

    /**
     * Q1 / Q3 en una línea (lámina de Dirección Comercial): por región, quién
     * es su gerente regional (titular, quien cubre temporalmente o vacante)
     * y cuántos Gerentes de Sucursal RH clasificó de ese lado; aparte, los
     * que siguen «Por clasificar». Estructura completa, sin alcance (misma
     * filosofía que las láminas: datos organizacionales mínimos).
     *
     * @return array{regiones: list<array{id: int, nombre: string, regional: string|null, cubre: string|null, gerentes: int}>, por_clasificar: list<array{id: int, nombre: string, sucursal: string|null, sugerida: string|null}>}
     */
    public function resumenLados(): array
    {
        $nodos = $this->nodosEstructura();
        $regiones = [];

        foreach ($this->puestoDeRegion as $regionId => $puestoId) {
            $regiones[$regionId] = ['id' => $regionId, 'nombre' => $this->regionPorId($regionId)['nombre'] ?? '', 'regional' => null, 'cubre' => null, 'gerentes' => 0];
        }

        $porClasificar = [];

        foreach ($nodos as $nodo) {
            $regionId = $nodo['region']['id'] ?? null;

            if ($regionId !== null && isset($regiones[$regionId]) && ($this->puestoDeRegion[$regionId] ?? null) === $nodo['puesto']['id']) {
                if ($nodo['tipo'] === 'persona') {
                    $regiones[$regionId]['regional'] ??= $nodo['persona']['nombre'] ?? null;
                } elseif ($nodo['tipo'] === 'cobertura') {
                    $regiones[$regionId]['cubre'] = $nodo['persona']['nombre'] ?? null;
                }
            }

            $clasificacion = $nodo['clasificacion'] ?? null;

            if ($clasificacion === null || $nodo['persona'] === null) {
                continue;
            }

            if ($clasificacion['estado'] === 'clasificado' && isset($regiones[$clasificacion['region']['id'] ?? 0])) {
                $regiones[$clasificacion['region']['id'] ?? 0]['gerentes']++;
            } else {
                $porClasificar[] = [
                    'id' => $nodo['persona']['id'],
                    'nombre' => $nodo['persona']['nombre'],
                    'sucursal' => $nodo['sucursal']['nombre'] ?? null,
                    'sugerida' => $clasificacion['sugerida']['nombre'] ?? null,
                ];
            }
        }

        return ['regiones' => array_values($regiones), 'por_clasificar' => $porClasificar];
    }

    /**
     * Ámbito de un puesto en el organigrama: de SUCURSAL (rama propia por
     * sucursal), de REGIÓN (gerente regional) o CORPORATIVO.
     *
     * @return 'sucursal'|'region'|'corporativo'
     */
    public function ambitoDePuesto(int $puestoId): string
    {
        $this->asegurarCatalogos();
        $puesto = $this->puestos->get($puestoId);

        return match (true) {
            $puesto === null => 'corporativo',
            $this->esDeSucursal($puesto) => 'sucursal',
            $this->esDeRegion($puesto) => 'region',
            default => 'corporativo',
        };
    }

    /**
     * Puesto clasificable en Q1/Q3: reporta a «el gerente regional de su
     * región» (hoy, Gerente de Sucursal).
     */
    public function esPuestoClasificable(int $puestoId): bool
    {
        $this->asegurarCatalogos();
        $puesto = $this->puestos->get($puestoId);

        return $puesto !== null && $this->esClasificable($puesto);
    }

    /**
     * Región de la matriz de la sucursal (zona → región), o null.
     *
     * @return Referencia|null
     */
    public function regionDeSucursal(?int $sucursalId): ?array
    {
        $this->asegurarCatalogos();

        return $this->regionDe($sucursalId);
    }

    /**
     * @param  Simulacion  $simulacion
     * @return Collection<int, Colaborador>
     */
    private function construirEstructuraCompleta(array $simulacion): Collection
    {
        $this->prepararCatalogos();

        $colaboradores = Colaborador::query()
            ->whereIn('estatus', EstadoUsuario::valoresVigentes())
            ->whereNotNull('puesto_id')
            ->orderBy('name')
            ->orderBy('apellidos')
            ->get(['id', 'name', 'apellidos', 'numero_empleado', 'foto_path', 'puesto_id', 'sucursal_principal_id', 'jefe_id']);

        foreach ($colaboradores as $colaborador) {
            $cambio = $simulacion[$colaborador->id] ?? null;

            if ($cambio === null) {
                continue;
            }

            // Solo en memoria: nunca se guarda (vista previa).
            $colaborador->setAttribute('puesto_id', $cambio['puesto_id']);
            $colaborador->setAttribute('sucursal_principal_id', $cambio['sucursal_principal_id']);

            if (array_key_exists('region_id', $cambio)) {
                if ($cambio['region_id'] === null) {
                    unset($this->regionClasificada[$colaborador->id]);
                } else {
                    $this->regionClasificada[$colaborador->id] = $cambio['region_id'];
                }
            }
        }

        $sucursales = Sucursal::query()->where('activo', true)->pluck('id')->map(fn ($id) => (int) $id);

        $this->construirNodos($colaboradores, $sucursales);

        return $colaboradores;
    }

    /**
     * Clave del nodo de la primera persona real (titular o quien cubre)
     * subiendo desde `$clave`.
     */
    private function claveDePersonaArriba(?string $clave, int $excluirId): ?string
    {
        $visitados = [];

        while ($clave !== null && ! isset($visitados[$clave]) && isset($this->nodos[$clave])) {
            $visitados[$clave] = true;
            $nodo = $this->nodos[$clave];
            $personaId = $nodo['persona']['id'] ?? null;

            if ($personaId !== null && $personaId !== $excluirId) {
                return $clave;
            }

            $clave = $nodo['padre'];
        }

        return null;
    }

    /** Catálogos para las consultas sueltas (una sola carga por instancia). */
    private function asegurarCatalogos(): void
    {
        if (! isset($this->puestos)) {
            $this->prepararCatalogos();
        }
    }

    private function prepararCatalogos(): void
    {
        $this->nodos = [];
        $this->puestosTope = null;
        $this->candidatosRespaldo = null;
        $this->puestos = Puesto::query()
            ->get(['id', 'nombre', 'nivel_jerarquico', 'tipo_puesto', 'puesto_superior_id', 'requiere_ruta', 'activo'])
            ->keyBy('id');
        $this->cargarRegiones();
        $this->cargarClasificaciones();
    }

    /**
     * Clasificaciones Q1/Q3 hechas por RH. Una que apunte a algo que ya no
     * es región de la matriz no cuenta (la persona vuelve a «Por clasificar»).
     */
    private function cargarClasificaciones(): void
    {
        $this->regionClasificada = [];
        $this->auditoriaClasificacion = [];
        $filas = ClasificacionRegion::query()
            ->whereHas('region', fn ($query) => $query->where('tipo', TipoNodoComercial::Region->value))
            ->with('clasificadaPor:id,name')
            ->get(['id', 'colaborador_id', 'region_id', 'clasificada_por', 'updated_at']);

        foreach ($filas as $fila) {

            $this->regionClasificada[$fila->colaborador_id] = $fila->region_id;
            $this->auditoriaClasificacion[$fila->colaborador_id] = [
                'por' => $fila->clasificadaPor?->name,
                'en' => $fila->updated_at?->toIso8601String(),
            ];
        }
    }

    private function esClasificable(Puesto $puesto): bool
    {
        $superior = $puesto->puesto_superior_id !== null ? $this->puestos->get($puesto->puesto_superior_id) : null;

        return $superior !== null && $this->esDeRegion($superior);
    }

    /**
     * Región con la que se resuelve de quién cuelga esta persona: la que RH
     * clasificó (si su puesto es clasificable) o, si no, la de su sucursal
     * en la matriz comercial (provisional mientras esté «Por clasificar»).
     */
    private function regionParaColaborador(Colaborador $colaborador, ?Puesto $puesto): ?int
    {
        if ($puesto !== null && $this->esClasificable($puesto) && isset($this->regionClasificada[$colaborador->id])) {
            return $this->regionClasificada[$colaborador->id];
        }

        return $this->regionDe($colaborador->sucursal_principal_id)['id'] ?? null;
    }

    /**
     * @return Clasificacion|null
     */
    private function datosClasificacion(Colaborador $colaborador, Puesto $puesto): ?array
    {
        if (! $this->esClasificable($puesto)) {
            return null;
        }

        $clasificada = $this->regionClasificada[$colaborador->id] ?? null;

        return [
            'estado' => $clasificada !== null ? 'clasificado' : 'por_clasificar',
            'region' => $clasificada !== null ? $this->regionPorId($clasificada) : null,
            // Lo que dice la matriz comercial para su sucursal: solo una
            // sugerencia (RH confirma con un clic; nunca se clasifica solo).
            'sugerida' => $this->regionDe($colaborador->sucursal_principal_id),
            'clasificada_por' => $this->auditoriaClasificacion[$colaborador->id]['por'] ?? null,
            'clasificada_en' => $this->auditoriaClasificacion[$colaborador->id]['en'] ?? null,
        ];
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
                'clasificacion' => $this->datosClasificacion($colaborador, $puesto),
            ];
        }

        foreach ($colaboradores as $colaborador) {
            $clave = 'p'.$colaborador->id;

            if (! isset($this->nodos[$clave])) {
                continue;
            }

            $puesto = $this->puestos->get((int) $colaborador->puesto_id);
            $sucursalId = $colaborador->sucursal_principal_id;

            // Gerente de Sucursal clasificado en Q1/Q3: cuelga del regional
            // de ESE lado (no del de la región de su sucursal en la matriz).
            $this->nodos[$clave]['padre'] = $this->resolverPadre(
                $puesto?->puesto_superior_id,
                $sucursalId,
                $this->regionParaColaborador($colaborador, $puesto),
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
                // Auditable: quién la marcó y cuándo.
                'registrada_por' => $cobertura->registradaPor?->name,
                'registrada_en' => $cobertura->created_at?->toIso8601String(),
            ],
            'clasificacion' => null,
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

            $ambito = match (true) {
                $cobertura->region_id !== null => ['tipo' => 'region', 'sucursal' => null, 'region' => $cobertura->region_id],
                $cobertura->sucursal_id !== null => ['tipo' => 'sucursal', 'sucursal' => $cobertura->sucursal_id, 'region' => $this->regionDe($cobertura->sucursal_id)['id'] ?? null],
                default => ['tipo' => 'corporativo', 'sucursal' => null, 'region' => null],
            };

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

            // Puesto corporativo (sin sucursal ni región): p. ej. Asistente de
            // Dirección General cubierto mientras se contrata.
            if ($cobertura->sucursal_id === null) {
                $this->coberturas[$this->llave($puesto->id, ['tipo' => 'corporativo', 'sucursal' => null, 'region' => null])] = $cobertura;
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
            if ($this->nombreEnLista($actual->nombre, $raices)) {
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

        return $this->nombreEnLista($puesto->nombre, $deRegion);
    }

    /**
     * Nombre de puesto contra una lista de config sin depender de
     * mayúsculas, acentos ni espacios («Gerente de sucursal» = «Gerente de
     * Sucursal»), igual que las consultas por nombre en MySQL (collation
     * *_ci). Un puesto renombrado así en el catálogo ya no saca a toda la
     * rama de sucursal de su sucursal.
     *
     * @param  list<string>  $lista
     */
    private function nombreEnLista(string $nombre, array $lista): bool
    {
        $clave = Normalizador::clave($nombre);

        foreach ($lista as $candidato) {
            if (Normalizador::clave($candidato) === $clave) {
                return true;
            }
        }

        return false;
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
