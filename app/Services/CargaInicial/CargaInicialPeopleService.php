<?php

namespace App\Services\CargaInicial;

use App\Enums\EstadoCargaRotacion;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\HeadcountTarget;
use App\Models\HeadcountTargetHistorial;
use App\Models\MigracionExpedientes;
use App\Models\Puesto;
use App\Models\RotacionCarga;
use App\Models\RotacionPeriodo;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Compensacion\CompensacionService;
use App\Services\Expedientes\MigracionInicial\EjecutorMigracion;
use App\Services\Expedientes\MigracionInicial\InventarioNasHistorico;
use App\Services\Expedientes\MigracionInicial\Normalizador;
use App\Services\Expedientes\MigracionInicial\PlanificadorMigracion;
use App\Services\Expedientes\MigracionInicial\ResolutorCatalogoMigracion;
use App\Services\Headcount\PuestosPlantillaService;
use App\Services\Vacantes\VacanteAutoGenerationService;
use App\Support\FechaMexico;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Configuración → Importar datos → «Carga inicial PEOPLE»: UN solo libro
 * (MR_LANA_PEOPLE_CARGA_INICIAL.xlsx) inicializa colaboradores, catálogos de
 * puestos/departamentos, salarios, vacaciones, headcount autorizado,
 * vacantes, rotación histórica y el vínculo con los expedientes del NAS.
 *
 * Reutiliza el motor de la migración inicial (PlanificadorMigracion /
 * EjecutorMigracion) para colaboradores, cuentas y NAS; aquí solo vive lo
 * propio de la carga maestra:
 *
 *   analizar() → NADA cambia en tablas de negocio: guarda el archivo, arma
 *                el plan (con los catálogos que se crearían como
 *                provisionales en memoria) y el preview por sección.
 *   aplicar()  → con lock, por etapas y en este orden: estructura,
 *                empresa/sucursales, catálogos, colaboradores (+ NAS y
 *                cuentas), salarios/vacaciones, headcount, vacantes,
 *                rotación. Idempotente: volver a aplicar el mismo archivo
 *                termina casi todo en «sin cambios» y nunca duplica.
 *
 * Reglas duras: CLAVE_NOI es la llave (nunca el nombre); los EXCLUIDO de la
 * hoja PENDIENTES nunca se importan; esta carga nunca da de baja a nadie ni
 * crea personas que no estén en COLABORADORES (las carpetas huérfanas del NAS
 * quedan «pendientes de vincular»); solo se importa plantilla AUTORIZADA
 * (la actual y las vacantes se calculan).
 */
class CargaInicialPeopleService
{
    public const TIPO = 'carga_inicial';

    public const FUENTE = 'carga_inicial';

    /** Mismo lock que la migración inicial: nunca corren las dos a la vez. */
    private const LOCK = 'expedientes:migracion-inicial';

    private const TOTAL_ETAPAS = 10;

    /** Operaciones del plan cuya persona sí existe/existirá en PEOPLE. */
    private const OPERACIONES_APLICABLES = ['crear', 'actualizar', 'sin_cambios'];

    public function __construct(
        private readonly LectorCargaMaestra $lector,
        private readonly PlanificadorMigracion $planificador,
        private readonly EjecutorMigracion $ejecutor,
        private readonly InventarioNasHistorico $inventario,
        private readonly PuestosPlantillaService $puestosPlantilla,
        private readonly VacanteAutoGenerationService $vacantes,
        private readonly CompensacionService $compensacion,
        private readonly AuditoriaService $auditoria,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Análisis (dry run) */
    /* ------------------------------------------------------------------ */

    public function analizar(string $ruta, string $nombreOriginal, ?User $actor): MigracionExpedientes
    {
        // Primero la estructura: un archivo sin sus hojas/columnas no se guarda.
        $datos = $this->lector->leer($ruta);

        $hash = hash_file('sha256', $ruta) ?: '';
        $copia = sprintf('cargas-iniciales/%s.xlsx', $hash);

        if (! Storage::disk('local')->exists($copia)) {
            Storage::disk('local')->put($copia, (string) file_get_contents($ruta));
        }

        $catalogo = $this->planCatalogo($datos['catalogo']);
        $resolutor = $this->resolutor();
        $resolutor->conProvisionales($catalogo['provisionales']['departamentos'], $catalogo['provisionales']['puestos']);

        try {
            $plan = $this->ajustarPlan($this->planificador->planificar($datos['colaboradores']), $datos['pendientes']);
            $headcount = $this->resolverHeadcount($datos['headcount']);
        } finally {
            $resolutor->sinProvisionales();
        }

        $rotacion = $this->resolverRotacion($datos['rotacion']);
        unset($catalogo['provisionales']);
        $plan['maestra'] = [
            'catalogo' => $catalogo,
            'preview' => $this->preview($plan, $catalogo, $datos, $headcount, $rotacion),
        ];

        $migracion = MigracionExpedientes::query()->create([
            'tipo' => self::TIPO,
            'user_id' => $actor?->id,
            'archivo_nombre' => $nombreOriginal,
            'archivo_path' => $copia,
            'archivo_hash' => $hash,
            'estado' => 'analizado',
            'totales' => $plan['totales'],
            'plan' => $this->codificar($plan),
            'decisiones' => ['filas' => [], 'carpetas' => []],
        ]);

        $this->auditoria->registrar('carga_inicial_analizada', $migracion, $actor, ['archivo' => $nombreOriginal, 'hash' => $hash, 'totales' => $plan['totales']]);

        return $migracion;
    }

    /**
     * Qué pasa con cada fila de CATALOGO_PUESTOS: departamento/puesto
     * reconocido (exacto o alias explícito), a crear, o puesto «de contexto»
     * (se resuelve por sucursal/región: Coordinadora Administrativa…).
     * Además arma los modelos PROVISIONALES (sin guardar) para el preview.
     *
     * @param  list<array<string, mixed>>  $filas
     * @return array{departamentos_reconocidos: list<string>, departamentos_crear: list<string>, puestos_reconocidos: list<string>, puestos_crear: list<array{puesto: string, departamento: string|null}>, puestos_contexto: list<string>, aliases: list<array{origen: string, destino: string}>, conflictos: list<string>, provisionales: array{departamentos: list<Departamento>, puestos: list<Puesto>}}
     */
    private function planCatalogo(array $filas): array
    {
        $resolutor = $this->resolutor()->recargar();
        $aliasPuestos = $this->clavesAlias('alias_puestos');
        $aliasDepartamentos = $this->clavesAlias('alias_departamentos');
        $r = ['departamentos_reconocidos' => [], 'departamentos_crear' => [], 'puestos_reconocidos' => [], 'puestos_crear' => [], 'puestos_contexto' => [], 'aliases' => [], 'conflictos' => []];
        /** @var array<string, Departamento> $depsProvisionales */
        $depsProvisionales = [];
        /** @var array<string, array{puesto: string, departamento: string|null}> $puestosCrear */
        $puestosCrear = [];
        $vistos = [];

        foreach ($filas as $f) {
            $nombrePuesto = $f['puesto'] ?? null;
            $nombreDep = $f['departamento'] ?? null;
            $nombrePuesto = is_string($nombrePuesto) ? $nombrePuesto : null;
            $nombreDep = is_string($nombreDep) ? $nombreDep : null;

            $departamento = null;

            if ($nombreDep !== null) {
                $departamento = $resolutor->departamento($nombreDep);
                $claveDep = Normalizador::clave($nombreDep);

                if ($departamento !== null) {
                    $r['departamentos_reconocidos'][$departamento->nombre] = $departamento->nombre;

                    if (isset($aliasDepartamentos[$claveDep]) && $claveDep !== Normalizador::clave($departamento->nombre)) {
                        $r['aliases'][sprintf('D:%s', $claveDep)] = ['origen' => $nombreDep, 'destino' => $departamento->nombre];
                    }
                } elseif (isset($aliasDepartamentos[$claveDep])) {
                    $r['conflictos'][] = sprintf('Fila %d: el departamento «%s» tiene alias a «%s», que no existe en el catálogo.', $f['fila'], $nombreDep, $aliasDepartamentos[$claveDep]);
                } elseif (! isset($depsProvisionales[$claveDep])) {
                    $depsProvisionales[$claveDep] = new Departamento(['nombre' => $nombreDep, 'activo' => true]);
                    $r['departamentos_crear'][] = $nombreDep;
                }
            }

            if ($nombrePuesto === null) {
                $r['conflictos'][] = sprintf('Fila %d de CATALOGO_PUESTOS sin puesto: se ignora.', $f['fila']);

                continue;
            }

            $clavePuesto = Normalizador::clave($nombrePuesto);

            if ($resolutor->esDeContexto($nombrePuesto)) {
                $r['puestos_contexto'][$clavePuesto] = $nombrePuesto;

                continue;
            }

            $resuelto = $resolutor->puesto($nombrePuesto, $departamento, null);

            if ($resuelto['puesto'] !== null) {
                $r['puestos_reconocidos'][$resuelto['puesto']->nombre] = $resuelto['puesto']->nombre;
                $alias = $resolutor->aliasPuesto($nombrePuesto);

                if ($alias !== null) {
                    $r['aliases'][sprintf('P:%s', $clavePuesto)] = ['origen' => $nombrePuesto, 'destino' => $alias];
                }

                continue;
            }

            if (isset($aliasPuestos[$clavePuesto])) {
                $r['conflictos'][] = sprintf('Fila %d: el puesto «%s» tiene alias a «%s», que no existe en el catálogo.', $f['fila'], $nombrePuesto, $aliasPuestos[$clavePuesto]);

                continue;
            }

            if (isset($puestosCrear[$clavePuesto])) {
                if (Normalizador::clave($puestosCrear[$clavePuesto]['departamento']) !== Normalizador::clave($nombreDep)) {
                    $r['conflictos'][] = sprintf('El puesto nuevo «%s» viene con dos departamentos («%s» y «%s»): se crea con el primero.', $nombrePuesto, $puestosCrear[$clavePuesto]['departamento'] ?? 'sin departamento', $nombreDep ?? 'sin departamento');
                }

                continue;
            }

            $puestosCrear[$clavePuesto] = ['puesto' => $nombrePuesto, 'departamento' => $departamento->nombre ?? $nombreDep];
            $vistos[$clavePuesto] = new Puesto(['nombre' => $nombrePuesto, 'departamento_id' => $departamento?->id, 'activo' => true]);
        }

        return [
            'departamentos_reconocidos' => array_values($r['departamentos_reconocidos']),
            'departamentos_crear' => $r['departamentos_crear'],
            'puestos_reconocidos' => array_values($r['puestos_reconocidos']),
            'puestos_crear' => array_values($puestosCrear),
            'puestos_contexto' => array_values($r['puestos_contexto']),
            'aliases' => array_values($r['aliases']),
            'conflictos' => $r['conflictos'],
            'provisionales' => ['departamentos' => array_values($depsProvisionales), 'puestos' => array_values($vistos)],
        ];
    }

    /**
     * Reglas propias de la carga maestra sobre el plan del planificador:
     *  - un EXCLUIDO de PENDIENTES nunca se importa (aunque venga en COLABORADORES);
     *  - una carpeta huérfana del NAS nunca crea una persona «histórica»:
     *    queda pendiente de vincular (esta carga no inventa gente ni bajas).
     *
     * @param  array<string, mixed>  $plan
     * @param  list<array<string, mixed>>  $pendientes
     * @return array<string, mixed>
     */
    private function ajustarPlan(array $plan, array $pendientes): array
    {
        $excluidas = [];

        foreach ($pendientes as $p) {
            if (($p['tipo'] ?? null) === 'EXCLUIDO' && is_string($p['clave'] ?? null)) {
                $excluidas[$p['clave']] = true;
            }
        }

        $filas = array_values(array_filter((array) ($plan['filas'] ?? []), 'is_array'));

        foreach ($filas as $i => $f) {
            if (isset($excluidas[(string) ($f['clave'] ?? '')]) && ($f['operacion'] ?? null) !== 'omitir') {
                $filas[$i]['operacion'] = 'omitir';
                $filas[$i]['motivos'][] = sprintf('CLAVE_NOI %s marcada como EXCLUIDO en la hoja PENDIENTES: no se importa.', $f['clave']);
            }
        }

        $huerfanas = array_values(array_filter((array) ($plan['carpetas_sin_persona'] ?? []), 'is_array'));

        foreach ($huerfanas as $i => $c) {
            if (($c['accion'] ?? null) === 'historico') {
                $huerfanas[$i]['accion'] = 'pendiente';
            }
        }

        $plan['filas'] = $filas;
        $plan['carpetas_sin_persona'] = $huerfanas;
        $plan['totales'] = $this->planificador->totales($plan);

        return $plan;
    }

    /**
     * HEADCOUNT → registros por (sucursal, puesto de plantilla). Un puesto
     * equivalente (Gestor Volante → Gestor) se SUMA a su puesto de plantilla,
     * igual que la captura manual. Sucursal o puesto no reconocido, o la
     * misma sucursal+puesto repetida en el archivo → conflicto (no se importa).
     *
     * @param  list<array<string, mixed>>  $filas
     * @return array{registros: list<array{sucursal_id: int, sucursal: string, empresa_id: int|null, puesto_id: int|null, puesto: string, departamento_id: int|null, plantilla: int, fecha_corte: string|null, filas: list<int>}>, filas_validas: int, total: int, fecha_corte: string|null, conflictos: list<string>}
     */
    private function resolverHeadcount(array $filas): array
    {
        $resolutor = $this->resolutor();
        $this->puestosPlantilla->olvidar();
        $registros = [];
        $conflictos = [];
        $vistos = [];
        $validas = 0;
        $fechas = [];

        foreach ($filas as $f) {
            $fila = (int) $f['fila'];
            $nombreSucursal = is_string($f['sucursal'] ?? null) ? $f['sucursal'] : null;
            $nombrePuesto = is_string($f['puesto'] ?? null) ? $f['puesto'] : null;
            $plantilla = is_int($f['plantilla'] ?? null) ? $f['plantilla'] : null;
            $sucursal = $this->inventario->resolverSucursal($nombreSucursal);

            if ($sucursal === null) {
                $conflictos[] = sprintf('HEADCOUNT fila %d: sucursal «%s» no reconocida.', $fila, $nombreSucursal ?? '');

                continue;
            }

            $resuelto = $resolutor->puesto($nombrePuesto, null, $sucursal);
            $puesto = $resuelto['puesto'];

            if ($puesto === null) {
                $conflictos[] = sprintf('HEADCOUNT fila %d: %s', $fila, $resuelto['motivo'] ?? sprintf('puesto «%s» no reconocido.', $nombrePuesto));

                continue;
            }

            if ($plantilla === null || $plantilla < 0) {
                $conflictos[] = sprintf('HEADCOUNT fila %d: PLANTILLA_AUTORIZADA vacía o inválida.', $fila);

                continue;
            }

            $literal = sprintf('%d|%s', $sucursal->id, Normalizador::clave($puesto->nombre));

            if (isset($vistos[$literal])) {
                $conflictos[] = sprintf('HEADCOUNT fila %d: %s / %s ya venía en la fila %d (no se suma ni se adivina cuál vale).', $fila, $sucursal->nombre, $puesto->nombre, $vistos[$literal]);

                continue;
            }

            $vistos[$literal] = $fila;
            // Un puesto provisional (se creará al aplicar) aún no tiene id.
            $canonico = $puesto->exists ? $this->puestoPorId($this->puestosPlantilla->canonico($puesto->id)) ?? $puesto : $puesto;
            $clave = sprintf('%d|%s', $sucursal->id, $canonico->exists ? (string) $canonico->id : Normalizador::clave($canonico->nombre));
            $fecha = is_string($f['fecha_corte'] ?? null) ? $f['fecha_corte'] : null;

            if ($fecha !== null) {
                $fechas[$fecha] = true;
            }

            $registros[$clave] ??= [
                'sucursal_id' => $sucursal->id,
                'sucursal' => $sucursal->nombre,
                'empresa_id' => $sucursal->empresa_id,
                'puesto_id' => $canonico->exists ? $canonico->id : null,
                'puesto' => $canonico->nombre,
                'departamento_id' => $canonico->departamento_id,
                'plantilla' => 0,
                'fecha_corte' => $fecha,
                'filas' => [],
            ];
            $registros[$clave]['plantilla'] += $plantilla;
            $registros[$clave]['filas'][] = $fila;
            $validas++;
        }

        $registros = array_values($registros);

        return [
            'registros' => $registros,
            'filas_validas' => $validas,
            'total' => array_sum(array_column($registros, 'plantilla')),
            'fecha_corte' => $fechas !== [] ? (string) max(array_keys($fechas)) : null,
            'conflictos' => $conflictos,
        ];
    }

    /**
     * ROTACION_2026 → periodos por (sucursal, año, mes). Los valores del
     * archivo se conservan (nunca se recalcula el índice); la plantilla
     * inicial es la PLANTILLA del mes anterior de la misma sucursal o, en el
     * primer mes, PLANTILLA − ALTAS + BAJAS.
     *
     * @param  list<array<string, mixed>>  $filas
     * @return array{periodos: list<array{sucursal_id: int, sucursal: string, sucursal_excel: string, anio: int, mes: int, plantilla_inicial: int, altas: int, bajas: int, plantilla_final: int, indice: float|null, indice_excel: float|null, fila: int}>, sucursales: int, conflictos: list<string>}
     */
    private function resolverRotacion(array $filas): array
    {
        $periodos = [];
        $conflictos = [];

        foreach ($filas as $f) {
            $fila = (int) $f['fila'];
            $nombre = is_string($f['sucursal'] ?? null) ? $f['sucursal'] : null;
            $anio = is_int($f['anio'] ?? null) ? $f['anio'] : null;
            $mes = is_int($f['mes'] ?? null) ? $f['mes'] : null;
            $plantilla = is_int($f['plantilla'] ?? null) ? $f['plantilla'] : null;
            $sucursal = $this->inventario->resolverSucursal($nombre);

            if ($sucursal === null) {
                $conflictos[] = sprintf('ROTACION fila %d: sucursal «%s» no reconocida.', $fila, $nombre ?? '');

                continue;
            }

            if ($anio === null || $anio < 2000 || $mes === null) {
                $conflictos[] = sprintf('ROTACION fila %d: año o mes no válido («%s» / «%s»).', $fila, $anio ?? '', $f['mes_original'] ?? '');

                continue;
            }

            if ($plantilla === null) {
                $conflictos[] = sprintf('ROTACION fila %d: PLANTILLA vacía.', $fila);

                continue;
            }

            $clave = sprintf('%d-%d-%d', $sucursal->id, $anio, $mes);

            if (isset($periodos[$clave])) {
                $conflictos[] = sprintf('ROTACION fila %d: %s %d/%d repetido (ya venía en la fila %d); se usa el primero.', $fila, $sucursal->nombre, $mes, $anio, $periodos[$clave]['fila']);

                continue;
            }

            $altas = is_int($f['altas'] ?? null) ? $f['altas'] : 0;
            $bajas = is_int($f['bajas'] ?? null) ? $f['bajas'] : 0;
            $indice = is_float($f['rotacion'] ?? null) ? $f['rotacion'] : null;

            // Si el archivo trajera fracción (0.5) en vez de porcentaje (50),
            // solo se escala cuando cuadra exactamente con bajas/plantilla.
            if ($indice !== null && $indice > 0 && $indice <= 1 && $plantilla > 0 && abs($indice - $bajas / $plantilla) < 0.005) {
                $indice = round($indice * 100, 2);
            }

            $periodos[$clave] = [
                'sucursal_id' => $sucursal->id,
                'sucursal' => $sucursal->nombre,
                'sucursal_excel' => (string) $nombre,
                'anio' => $anio,
                'mes' => $mes,
                'plantilla_inicial' => 0,
                'altas' => $altas,
                'bajas' => $bajas,
                'plantilla_final' => $plantilla,
                'indice' => $indice,
                'indice_excel' => $indice,
                'fila' => $fila,
            ];
        }

        foreach ($periodos as $clave => $p) {
            $anterior = $p['mes'] === 1
                ? sprintf('%d-%d-12', $p['sucursal_id'], $p['anio'] - 1)
                : sprintf('%d-%d-%d', $p['sucursal_id'], $p['anio'], $p['mes'] - 1);
            $periodos[$clave]['plantilla_inicial'] = isset($periodos[$anterior])
                ? $periodos[$anterior]['plantilla_final']
                : max($p['plantilla_final'] - $p['altas'] + $p['bajas'], 0);
        }

        $periodos = array_values($periodos);

        return ['periodos' => $periodos, 'sucursales' => count(array_unique(array_column($periodos, 'sucursal_id'))), 'conflictos' => $conflictos];
    }

    /**
     * Preview por sección (contrato de la pantalla).
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $catalogo
     * @param  array<string, mixed>  $datos
     * @param  array<string, mixed>  $headcount
     * @param  array<string, mixed>  $rotacion
     * @return array<string, mixed>
     */
    private function preview(array $plan, array $catalogo, array $datos, array $headcount, array $rotacion): array
    {
        $filas = array_values(array_filter((array) ($plan['filas'] ?? []), 'is_array'));
        $totales = (array) ($plan['totales'] ?? []);
        [$porLlave, $conteoClaves] = $this->filasPorLlave($filas);

        // Salarios: solo por CLAVE_NOI (+ sucursal si la clave se repite) y
        // solo de personas que sí se aplican.
        $vinculados = 0;
        $cambios = 0;
        $pendientesSalario = [];
        $conSalario = [];
        $colaboradoresIds = array_values(array_filter(array_map(fn (array $f) => $f['colaborador_id'] ?? null, $filas), 'is_int'));
        $salariosActuales = Colaborador::withTrashed()->whereIn('id', $colaboradoresIds)->get(['id', 'salario_diario', 'sueldo_mensual'])->keyBy('id');

        foreach ((array) ($datos['salarios'] ?? []) as $s) {
            $clave = is_string($s['clave'] ?? null) ? $s['clave'] : null;
            $llave = $this->llaveSalario($s, $conteoClaves);
            $fila = $llave !== null ? ($porLlave[$llave] ?? null) : null;

            if ($fila === null || ! in_array($fila['operacion'] ?? null, self::OPERACIONES_APLICABLES, true)) {
                $pendientesSalario[] = ['clave' => $clave, 'nombre' => $s['nombre'] ?? null, 'motivo' => match (true) {
                    $llave !== null && array_key_exists($llave, $porLlave) && $fila === null => 'La CLAVE_NOI se repite en la misma sucursal: no se vincula sin adivinar.',
                    $fila === null => 'La CLAVE_NOI (y su sucursal, si la clave se repite) no está en COLABORADORES: no se vincula (nunca por nombre).',
                    default => sprintf('La persona no se aplica en esta carga (%s).', $fila['operacion'] ?? 'sin operación'),
                }];

                continue;
            }

            $conSalario[(string) $llave] = true;
            $vinculados++;
            $actual = isset($fila['colaborador_id']) && $salariosActuales->has($fila['colaborador_id']) ? $this->compensacion->diarioDe($salariosActuales->get($fila['colaborador_id'])) : null;

            if ($actual === null || abs($actual - (float) ($s['salario_diario'] ?? 0)) > 0.01) {
                $cambios++;
            }
        }

        foreach ($filas as $f) {
            if (($f['clave'] ?? null) !== null && ! isset($conSalario[$this->llaveFila($f, $conteoClaves)]) && ($f['operacion'] ?? null) !== 'omitir') {
                $pendientesSalario[] = ['clave' => (string) $f['clave'], 'nombre' => $f['nombre_completo'] ?? null, 'motivo' => 'Sin fila en SALARIOS_VACACIONES: no se inventa salario ni vacaciones.'];
            }
        }

        $advertencias = [];
        $origen = (array) ($plan['origen'] ?? []);

        if (! ($origen['existe'] ?? false)) {
            $advertencias[] = sprintf('No se encontró la carpeta de expedientes del NAS (%s): los colaboradores se cargan sin vincular su expediente histórico.', $origen['ruta'] ?? '');
        }

        $enRevisionNas = count(array_filter($filas, fn (array $f) => ($f['nas']['tipo'] ?? null) === 'revision' && in_array($f['operacion'] ?? null, self::OPERACIONES_APLICABLES, true)));

        if ($enRevisionNas > 0) {
            $advertencias[] = sprintf('%d colaborador(es) tienen una carpeta del NAS parecida pero no segura: se cargan sin vincularla; RH la confirma después.', $enRevisionNas);
        }

        foreach ((array) ($plan['sucursales_no_autorizadas_nas'] ?? []) as $s) {
            $advertencias[] = sprintf('Carpeta de sucursal no autorizada en el NAS: %s (no se usa).', is_scalar($s) ? (string) $s : (string) json_encode($s, JSON_UNESCAPED_UNICODE));
        }

        foreach ((array) ($datos['pendientes'] ?? []) as $p) {
            if (! in_array($p['tipo'] ?? null, LectorCargaMaestra::TIPOS_PENDIENTE, true)) {
                $advertencias[] = sprintf('PENDIENTES fila %d: tipo «%s» no reconocido (solo informativo).', $p['fila'], $p['tipo'] ?? '');
            }
        }

        return [
            'colaboradores' => [
                'total' => (int) ($totales['filas'] ?? 0),
                'crear' => (int) ($totales['crear'] ?? 0),
                'actualizar' => (int) ($totales['actualizar'] ?? 0),
                'sin_cambios' => (int) ($totales['sin_cambios'] ?? 0),
                'pendientes' => (int) ($totales['en_revision'] ?? 0),
                'conflictos' => (int) ($totales['conflictos'] ?? 0),
                'omitidos' => (int) ($totales['omitidos'] ?? 0),
            ],
            'catalogos' => [
                'departamentos_reconocidos' => $catalogo['departamentos_reconocidos'] ?? [],
                'departamentos_crear' => $catalogo['departamentos_crear'] ?? [],
                'puestos_reconocidos' => $catalogo['puestos_reconocidos'] ?? [],
                'puestos_crear' => $catalogo['puestos_crear'] ?? [],
                'puestos_contexto' => $catalogo['puestos_contexto'] ?? [],
                'aliases' => $catalogo['aliases'] ?? [],
                'conflictos' => $catalogo['conflictos'] ?? [],
            ],
            'salarios' => ['vinculados' => $vinculados, 'cambios' => $cambios, 'pendientes' => $pendientesSalario],
            'headcount' => ['registros' => (int) $headcount['filas_validas'], 'total_autorizado' => (int) $headcount['total'], 'fecha_corte' => $headcount['fecha_corte'], 'conflictos' => $headcount['conflictos']],
            'rotacion' => ['periodos' => count((array) $rotacion['periodos']), 'sucursales' => (int) $rotacion['sucursales'], 'conflictos' => $rotacion['conflictos']],
            'nas' => [
                'origen_existe' => (bool) ($origen['existe'] ?? false),
                'ruta' => (string) ($origen['ruta'] ?? ''),
                'carpetas' => (int) ($totales['carpetas_nas'] ?? 0),
                'match_exacto' => (int) ($totales['match_exacto'] ?? 0),
                'match_alto' => (int) ($totales['match_alto'] ?? 0),
                'revision_manual' => (int) ($totales['revision_manual'] ?? 0),
                'sin_match' => (int) ($totales['sin_match'] ?? 0),
                'historicos' => (int) ($totales['historicos'] ?? 0),
                'pendientes_vincular' => (int) ($totales['pendientes_vincular'] ?? 0),
            ],
            'pendientes_rh' => array_values(array_map(fn (array $f) => [
                'fila' => (int) $f['fila'],
                'clave' => $f['clave'] ?? null,
                'nombre' => $f['nombre_completo'] ?? null,
                'motivo' => implode(' ', (array) ($f['motivos'] ?? [])),
            ], array_filter($filas, fn (array $f) => ($f['operacion'] ?? null) === 'en_revision'))),
            'conflictos_filas' => array_values(array_map(fn (array $f) => [
                'fila' => (int) $f['fila'],
                'clave' => $f['clave'] ?? null,
                'nombre' => $f['nombre_completo'] ?? null,
                'motivos' => array_values((array) ($f['motivos'] ?? [])),
            ], array_filter($filas, fn (array $f) => ($f['operacion'] ?? null) === 'conflicto'))),
            'informativos' => array_map(fn (array $p) => [
                'tipo' => (string) ($p['tipo'] ?? ''),
                'clave' => $p['clave'] ?? null,
                'nombre' => $p['nombre'] ?? $p['sucursal'] ?? null,
                'detalle' => implode(' — ', array_filter([$p['campo'] ?? null, $p['motivo'] ?? null, $p['accion'] ?? null])) ?: null,
            ], (array) ($datos['pendientes'] ?? [])),
            'advertencias' => $advertencias,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Aplicación */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed> el Resultado del contrato
     */
    public function aplicar(MigracionExpedientes $migracion, User $actor): array
    {
        if ($migracion->tipo !== self::TIPO) {
            throw ValidationException::withMessages(['migracion' => 'Esta corrida no es una carga inicial PEOPLE.']);
        }

        if ($migracion->estado === 'aplicando') {
            throw ValidationException::withMessages(['migracion' => 'Esta carga se está ejecutando.']);
        }

        $lock = Cache::lock(self::LOCK, 7200);

        if (! $lock->get()) {
            throw ValidationException::withMessages(['migracion' => 'Ya hay una carga o migración ejecutándose. Espera a que termine.']);
        }

        try {
            @set_time_limit(0);

            return $this->ejecutar($migracion, $actor);
        } catch (Throwable $e) {
            $migracion->update(['estado' => 'fallido', 'error' => $e->getMessage(), 'terminada_en' => now()]);

            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function ejecutar(MigracionExpedientes $migracion, User $actor): array
    {
        $migracion->update(['estado' => 'aplicando', 'iniciada_en' => now(), 'error' => null, 'modo' => EjecutorMigracion::MODO_COPIAR]);
        $errores = [];

        // 1. Estructura: se vuelve a leer el archivo guardado (el mismo que se analizó).
        $this->etapa($migracion, 1, 'Validando estructura del archivo');
        $ruta = $migracion->archivo_path !== null ? Storage::disk('local')->path($migracion->archivo_path) : '';

        if ($ruta === '' || ! is_file($ruta)) {
            throw new RuntimeException('No se encontró el archivo analizado: vuelve a subirlo.');
        }

        if ((hash_file('sha256', $ruta) ?: '') !== $migracion->archivo_hash) {
            throw new RuntimeException('El archivo guardado no coincide con el analizado: vuelve a subirlo.');
        }

        $datos = $this->lector->leer($ruta);
        $planAnterior = $migracion->planArray();

        // 2. Empresa y sucursales: sin la empresa no se aplica nada.
        $this->etapa($migracion, 2, 'Validando empresa y sucursales');
        $empresa = (string) config('expedientes.migracion_inicial.empresa', 'Mr. Lana');

        if ($this->resolutor()->recargar()->empresa($empresa) === null) {
            throw new RuntimeException(sprintf('La empresa «%s» no existe en el catálogo: créala antes de aplicar.', $empresa));
        }

        // 3. Catálogos faltantes (CATALOGO_PUESTOS).
        $this->etapa($migracion, 3, 'Creando catálogos faltantes');
        $catalogos = $this->crearCatalogos($datos['catalogo'], $migracion, $actor);

        // 4. Colaboradores + cuentas + NAS (motor de la migración inicial),
        //    re-planeado con los catálogos ya reales.
        $this->etapa($migracion, 4, 'Colaboradores y expedientes');
        $plan = $this->ajustarPlan($this->planificador->planificar($datos['colaboradores']), $datos['pendientes']);
        $decisionesFilas = [];
        $revisionNas = 0;

        foreach ((array) $plan['filas'] as $f) {
            // Carpeta del NAS dudosa: la persona se carga igual, sin
            // vincular esa carpeta (RH la confirma después).
            if (is_array($f) && ($f['nas']['tipo'] ?? null) === 'revision' && in_array($f['operacion'] ?? null, self::OPERACIONES_APLICABLES, true)) {
                $decisionesFilas[(string) $f['fila']] = ['ruta' => null];
                $revisionNas++;
            }
        }

        $headcount = $this->resolverHeadcount($datos['headcount']);
        $rotacion = $this->resolverRotacion($datos['rotacion']);
        $catalogoAnalizado = (array) ($planAnterior['maestra']['catalogo'] ?? []);
        $plan['maestra'] = [
            'catalogo' => $catalogoAnalizado,
            'preview' => $this->preview($plan, $catalogoAnalizado, $datos, $headcount, $rotacion),
        ];
        $migracion->update(['plan' => $this->codificar($plan), 'totales' => $plan['totales'], 'decisiones' => ['filas' => $decisionesFilas, 'carpetas' => []]]);
        $conteo = $this->ejecutor->ejecutar($migracion, $actor, EjecutorMigracion::MODO_COPIAR);
        $migracion->refresh();
        $migracion->update(['estado' => 'aplicando', 'progreso_total' => self::TOTAL_ETAPAS, 'terminada_en' => null]);

        // 5. Salarios y vacaciones (solo por CLAVE_NOI).
        $this->etapa($migracion, 5, 'Salarios y vacaciones');
        $erroresSalarios = [];
        [$salarios, $vacaciones] = $this->seguro($errores, 'Salarios y vacaciones', function () use ($datos, $plan, $migracion, $actor, &$erroresSalarios): array {
            return $this->aplicarSalarios($datos['salarios'], $plan, $migracion, $actor, $erroresSalarios);
        }, [
            ['aplicados' => 0, 'sin_cambios' => 0, 'pendientes' => 0], ['aplicados' => 0, 'sin_cambios' => 0],
        ]);
        $errores = [...$errores, ...$erroresSalarios];

        // 6. Headcount autorizado (nunca plantilla actual ni vacantes).
        $this->etapa($migracion, 6, 'Headcount autorizado');
        $resHeadcount = $this->seguro($errores, 'Headcount', fn () => $this->aplicarHeadcount($headcount, $migracion, $actor), ['creados' => 0, 'actualizados' => 0, 'sin_cambios' => 0]);

        foreach ($headcount['conflictos'] as $c) {
            $errores[] = $c;
        }

        // 7. Vacantes automáticas según la nueva plantilla.
        $this->etapa($migracion, 7, 'Sincronizando vacantes');
        $resVacantes = $this->seguro($errores, 'Vacantes', function (): array {
            $r = $this->vacantes->sincronizarTodo();

            return ['abiertas' => $r['abiertas'], 'cerradas' => $r['cerradas'], 'actualizadas' => $r['actualizadas']];
        }, ['abiertas' => 0, 'cerradas' => 0, 'actualizadas' => 0]);

        // 8. Rotación histórica (valores del archivo, sin recalcular).
        $this->etapa($migracion, 8, 'Rotación histórica');
        $resRotacion = $this->seguro($errores, 'Rotación', fn () => $this->aplicarRotacion($rotacion, $datos['hoja_rotacion'], $migracion, $actor), ['nuevos' => 0, 'reemplazados' => 0, 'sin_cambios' => 0]);

        foreach ($rotacion['conflictos'] as $c) {
            $errores[] = $c;
        }

        // 9. Expedientes NAS: ya se registraron/vincularon en la etapa 4.
        $this->etapa($migracion, 9, 'Expedientes del NAS');

        // 10. Resultado y auditoría.
        $this->etapa($migracion, 10, 'Terminado');
        $resultado = [
            'colaboradores' => [
                'creados' => $conteo['creados'] ?? 0,
                'actualizados' => $conteo['actualizados'] ?? 0,
                'sin_cambios' => $conteo['sin_cambios'] ?? 0,
                'en_revision' => $conteo['revision_estructural'] ?? 0,
                'conflictos' => $conteo['conflictos'] ?? 0,
                'errores' => $conteo['errores'] ?? 0,
            ],
            'catalogos' => $catalogos,
            'salarios' => $salarios,
            'vacaciones' => $vacaciones,
            'headcount' => $resHeadcount,
            'vacantes' => $resVacantes,
            'rotacion' => $resRotacion,
            'nas' => [
                'pdfs_registrados' => ($conteo['pdfs_registrados'] ?? 0) + ($conteo['pdfs_copiados'] ?? 0),
                'pendientes_vincular' => $conteo['pendientes_vincular'] ?? 0,
                'revision_pendiente' => $revisionNas + ($conteo['revision_pendiente'] ?? 0),
            ],
            'cuentas_creadas' => $conteo['cuentas_creadas'] ?? 0,
            'errores' => array_values(array_unique($errores)),
            'ejecutor' => $conteo,
        ];

        $conErrores = $errores !== [] || ($conteo['errores'] ?? 0) > 0;
        $migracion->update([
            'estado' => $conErrores ? 'completado_con_errores' : 'completado',
            'resultado' => $resultado,
            'progreso' => self::TOTAL_ETAPAS,
            'terminada_en' => now(),
        ]);
        $this->auditoria->registrar('carga_inicial_aplicada', $migracion, $actor, ['archivo' => $migracion->archivo_nombre, 'resultado' => array_diff_key($resultado, ['ejecutor' => true])]);

        return $resultado;
    }

    /**
     * Departamentos y puestos que faltan según CATALOGO_PUESTOS. Idempotente
     * por nombre normalizado; sin jerarquía inventada (solo nombre,
     * departamento y descripción de origen).
     *
     * @param  list<array<string, mixed>>  $filas
     * @return array{departamentos_creados: int, puestos_creados: int}
     */
    private function crearCatalogos(array $filas, MigracionExpedientes $migracion, User $actor): array
    {
        $plan = $this->planCatalogo($filas);
        $descripcion = sprintf('Importado de %s (CATALOGO_PUESTOS)', $migracion->archivo_nombre);
        $creados = ['departamentos_creados' => 0, 'puestos_creados' => 0];

        DB::transaction(function () use ($plan, $descripcion, $migracion, $actor, &$creados): void {
            foreach ($plan['departamentos_crear'] as $nombre) {
                $existente = Departamento::query()->get(['id', 'nombre'])->first(fn (Departamento $d) => Normalizador::clave($d->nombre) === Normalizador::clave($nombre));

                if ($existente !== null) {
                    continue;
                }

                $departamento = Departamento::query()->create(['nombre' => $nombre, 'descripcion' => $descripcion, 'activo' => true]);
                $creados['departamentos_creados']++;
                $this->auditoria->registrar('carga_inicial_departamento_creado', $departamento, $actor, ['nombre' => $nombre, 'fuente' => $migracion->archivo_nombre]);
            }

            $this->resolutor()->recargar();

            foreach ($plan['puestos_crear'] as $p) {
                $existente = Puesto::query()->get(['id', 'nombre'])->first(fn (Puesto $x) => Normalizador::clave($x->nombre) === Normalizador::clave($p['puesto']));

                if ($existente !== null) {
                    continue;
                }

                $departamento = $p['departamento'] !== null ? $this->resolutor()->departamento($p['departamento']) : null;
                $puesto = Puesto::query()->create([
                    'nombre' => $p['puesto'],
                    'departamento_id' => $departamento?->id,
                    'descripcion' => $descripcion,
                    'activo' => true,
                ]);
                $creados['puestos_creados']++;
                $this->auditoria->registrar('carga_inicial_puesto_creado', $puesto, $actor, ['nombre' => $p['puesto'], 'departamento' => $departamento?->nombre, 'fuente' => $migracion->archivo_nombre]);
            }
        });

        $this->resolutor()->recargar();
        $this->puestosPlantilla->olvidar();

        return $creados;
    }

    /**
     * @param  list<array<string, mixed>>  $salarios
     * @param  array<string, mixed>  $plan
     * @param  list<string>  $errores
     * @return array{0: array{aplicados: int, sin_cambios: int, pendientes: int}, 1: array{aplicados: int, sin_cambios: int}}
     */
    private function aplicarSalarios(array $salarios, array $plan, MigracionExpedientes $migracion, User $actor, array &$errores): array
    {
        [$porLlave, $conteoClaves] = $this->filasPorLlave(array_values(array_filter((array) $plan['filas'], 'is_array')));
        $sal = ['aplicados' => 0, 'sin_cambios' => 0, 'pendientes' => 0];
        $vac = ['aplicados' => 0, 'sin_cambios' => 0];
        $hoy = FechaMexico::hoy();

        foreach ($salarios as $s) {
            $clave = is_string($s['clave'] ?? null) ? $s['clave'] : null;
            $llave = $this->llaveSalario($s, $conteoClaves);
            $fila = $llave !== null ? ($porLlave[$llave] ?? null) : null;

            if ($clave === null || $fila === null || ! in_array($fila['operacion'] ?? null, self::OPERACIONES_APLICABLES, true)) {
                $sal['pendientes']++;

                continue;
            }

            $consulta = Colaborador::withTrashed()->where('clave_legacy', $clave);

            // Clave repetida en el archivo: CLAVE_NOI + sucursal (nunca el nombre).
            if (($conteoClaves[$clave] ?? 0) > 1) {
                $consulta->where('sucursal_principal_id', $fila['sucursal_id'] ?? 0);
            }

            $encontrados = $consulta->get();

            if ($encontrados->count() !== 1) {
                $sal['pendientes']++;
                $errores[] = sprintf('SALARIOS fila %d: la CLAVE_NOI %s coincide con %d colaboradores; no se aplica.', $s['fila'], $clave, $encontrados->count());

                continue;
            }

            /** @var Colaborador $colaborador */
            $colaborador = $encontrados->first();

            try {
                $diario = is_float($s['salario_diario'] ?? null) ? $s['salario_diario'] : null;

                if ($diario !== null && $diario > 0 && abs($this->compensacion->diarioDe($colaborador) - $diario) > 0.01) {
                    $this->compensacion->actualizarSalario($colaborador, $diario, null, $actor, [
                        'fuente' => self::FUENTE,
                        'fecha_efectiva' => $hoy,
                        'motivo' => sprintf('Carga inicial PEOPLE (%s), CLAVE_NOI %s.', $migracion->archivo_nombre, $clave),
                    ]);
                    $sal['aplicados']++;
                } else {
                    $sal['sin_cambios']++;
                }

                $colaborador->refresh();
                $fechaArchivo = is_string($s['fecha_ingreso'] ?? null) ? $s['fecha_ingreso'] : null;

                // La fecha de ingreso solo se completa si no había.
                if ($colaborador->fecha_ingreso === null && $fechaArchivo !== null) {
                    $colaborador->forceFill(['fecha_ingreso' => $fechaArchivo])->save();
                    $colaborador->refresh();
                }

                $vac[$this->ajustarVacaciones($colaborador, is_float($s['dias_tomados'] ?? null) ? $s['dias_tomados'] : null, $hoy, $migracion, $actor) ? 'aplicados' : 'sin_cambios']++;
            } catch (Throwable $e) {
                $errores[] = sprintf('SALARIOS fila %d (CLAVE_NOI %s): %s', $s['fila'], $clave, $e->getMessage());
                Log::warning('Carga inicial: error en salario/vacaciones.', ['clave' => $clave, 'error' => $e->getMessage()]);
            }
        }

        return [$sal, $vac];
    }

    /**
     * Misma regla que ImportadorTrabajadoresService::calcularCambios(): con
     * ≥ 1 año cumplido, los días tomados antes de PEOPLE se registran como
     * ajuste acumulado negativo. El ledger nunca se edita: si el valor del
     * archivo cambió entre corridas se agrega la DIFERENCIA (idempotente).
     */
    private function ajustarVacaciones(Colaborador $colaborador, ?float $diasTomados, string $hoy, MigracionExpedientes $migracion, User $actor): bool
    {
        $fechaIngreso = $colaborador->fecha_ingreso;

        if ($fechaIngreso === null || (int) Carbon::parse($fechaIngreso->toDateString())->diffInYears(Carbon::parse($hoy)) < 1) {
            return false;
        }

        $objetivo = $diasTomados !== null && $diasTomados > 0 ? -$diasTomados : 0.0;
        $actual = (float) $colaborador->ajustesVacaciones()->where('fuente', self::FUENTE)->sum('dias');
        $diferencia = round($objetivo - $actual, 2);

        if (abs($diferencia) < 0.005) {
            return false;
        }

        $colaborador->ajustesVacaciones()->create([
            'tipo' => 'ajuste_acumulado',
            'dias' => $diferencia,
            'fecha_corte' => $hoy,
            'fuente' => self::FUENTE,
            'motivo' => $actual === 0.0
                ? sprintf('Días tomados antes de PEOPLE según carga inicial (%s).', $migracion->archivo_nombre)
                : sprintf('Corrección de días tomados antes de PEOPLE según carga inicial (%s).', $migracion->archivo_nombre),
            'actor_id' => $actor->id,
        ]);

        return true;
    }

    /**
     * @param  array{registros: list<array<string, mixed>>, conflictos: list<string>}  $headcount
     * @return array{creados: int, actualizados: int, sin_cambios: int}
     */
    private function aplicarHeadcount(array $headcount, MigracionExpedientes $migracion, User $actor): array
    {
        $r = ['creados' => 0, 'actualizados' => 0, 'sin_cambios' => 0];
        $motivo = sprintf('Carga inicial PEOPLE (%s)', $migracion->archivo_nombre);

        foreach ($headcount['registros'] as $reg) {
            $puestoId = is_int($reg['puesto_id'] ?? null) ? $reg['puesto_id'] : null;
            $sucursalId = (int) $reg['sucursal_id'];
            $plantilla = (int) $reg['plantilla'];

            if ($puestoId === null) {
                continue;
            }

            $accion = DB::transaction(function () use ($reg, $puestoId, $sucursalId, $plantilla, $motivo, $actor): string {
                $existente = HeadcountTarget::query()->where('sucursal_id', $sucursalId)->where('puesto_id', $puestoId)->lockForUpdate()->first();
                $equivalentes = HeadcountTarget::query()
                    ->where('sucursal_id', $sucursalId)
                    ->whereIn('puesto_id', $this->puestosPlantilla->equivalentes($puestoId))
                    ->where('puesto_id', '!=', $puestoId)
                    ->where('plantilla_autorizada', '!=', 0)
                    ->lockForUpdate()
                    ->get();
                $anterior = $existente?->plantilla_autorizada;

                if ($existente !== null && $anterior === $plantilla && $equivalentes->isEmpty()) {
                    return 'sin_cambios';
                }

                $target = HeadcountTarget::query()->updateOrCreate(
                    ['sucursal_id' => $sucursalId, 'puesto_id' => $puestoId],
                    [
                        'empresa_id' => $reg['empresa_id'],
                        'departamento_id' => $reg['departamento_id'],
                        'plantilla_autorizada' => $plantilla,
                        'fuente' => self::FUENTE,
                        'fecha_corte' => $reg['fecha_corte'] ?? FechaMexico::hoy(),
                        'updated_by_id' => $actor->id,
                        'created_by_id' => $existente->created_by_id ?? $actor->id,
                    ],
                );

                // Las plazas del puesto equivalente quedan integradas aquí.
                foreach ($equivalentes as $equivalente) {
                    HeadcountTargetHistorial::query()->create([
                        'headcount_target_id' => $equivalente->id,
                        'sucursal_id' => $sucursalId,
                        'puesto_id' => $equivalente->puesto_id,
                        'valor_anterior' => $equivalente->plantilla_autorizada,
                        'valor_nuevo' => 0,
                        'fuente' => self::FUENTE,
                        'motivo' => sprintf('%s: integrado a su puesto de plantilla.', $motivo),
                        'user_id' => $actor->id,
                    ]);
                    $equivalente->update(['plantilla_autorizada' => 0, 'updated_by_id' => $actor->id]);
                }

                if ($anterior !== $plantilla) {
                    HeadcountTargetHistorial::query()->create([
                        'headcount_target_id' => $target->id,
                        'sucursal_id' => $sucursalId,
                        'puesto_id' => $puestoId,
                        'valor_anterior' => $anterior,
                        'valor_nuevo' => $plantilla,
                        'fuente' => self::FUENTE,
                        'motivo' => $motivo,
                        'user_id' => $actor->id,
                    ]);
                }

                return $existente === null ? 'creados' : 'actualizados';
            });

            $r[$accion]++;
        }

        return $r;
    }

    /**
     * @param  array{periodos: list<array<string, mixed>>, conflictos: list<string>}  $rotacion
     * @return array{nuevos: int, reemplazados: int, sin_cambios: int}
     */
    private function aplicarRotacion(array $rotacion, string $hoja, MigracionExpedientes $migracion, User $actor): array
    {
        $r = ['nuevos' => 0, 'reemplazados' => 0, 'sin_cambios' => 0];
        $campos = ['plantilla_inicial', 'altas', 'bajas', 'plantilla_final', 'indice', 'indice_excel'];
        $comparable = fn (array $v): array => array_map(fn ($x) => $x === null ? null : round((float) $x, 2), $v);

        DB::transaction(function () use ($rotacion, $hoja, $migracion, $actor, $campos, $comparable, &$r): void {
            $carga = null;

            foreach ($rotacion['periodos'] as $p) {
                $existente = RotacionPeriodo::query()->where('sucursal_id', $p['sucursal_id'])->where('anio', $p['anio'])->where('mes', $p['mes'])->lockForUpdate()->first();
                $nuevos = [];

                foreach ($campos as $campo) {
                    $nuevos[$campo] = $p[$campo] ?? null;
                }

                if ($existente !== null) {
                    $actuales = [];

                    foreach ($campos as $campo) {
                        $actuales[$campo] = $existente->getAttribute($campo);
                    }

                    if ($comparable($actuales) === $comparable($nuevos)) {
                        $r['sin_cambios']++;

                        continue;
                    }
                }

                // Una sola carga de rotación por corrida, y solo si algo cambia.
                $carga ??= RotacionCarga::query()->create([
                    'nombre_archivo' => mb_substr($migracion->archivo_nombre, 0, 255),
                    'archivo_ruta' => (string) $migracion->archivo_path,
                    'archivo_hash' => $migracion->archivo_hash,
                    'estado' => EstadoCargaRotacion::Aplicada,
                    'subido_por' => $actor->id,
                    'aplicado_por' => $actor->id,
                    'aplicado_en' => now(),
                    'periodos_detectados' => count($rotacion['periodos']),
                    'hallazgos' => count($rotacion['conflictos']),
                    'resumen' => ['origen' => self::TIPO, 'migracion_id' => $migracion->id, 'hoja' => $hoja],
                    'detalle' => ['periodos' => $rotacion['periodos'], 'hallazgos' => array_map(fn (string $m) => ['nivel' => 'error', 'hoja' => $hoja, 'celda' => null, 'sucursal' => null, 'mensaje' => $m], $rotacion['conflictos'])],
                ]);

                RotacionPeriodo::query()->updateOrCreate(
                    ['sucursal_id' => $p['sucursal_id'], 'anio' => $p['anio'], 'mes' => $p['mes']],
                    [...$nuevos, 'sucursal_excel' => mb_substr((string) $p['sucursal_excel'], 0, 255), 'hoja_excel' => mb_substr($hoja, 0, 255), 'carga_id' => $carga->id],
                );
                $r[$existente === null ? 'nuevos' : 'reemplazados']++;
            }

            $carga?->forceFill(['periodos_nuevos' => $r['nuevos'], 'periodos_reemplazados' => $r['reemplazados'], 'periodos_sin_cambios' => $r['sin_cambios']])->save();

            if ($carga !== null) {
                $this->auditoria->registrar('rotacion.carga_aplicada', $carga, $actor, ['archivo' => $carga->nombre_archivo, 'origen' => self::TIPO, ...$r]);
            }
        });

        return $r;
    }

    /* ------------------------------------------------------------------ */
    /*  Para el controlador (contrato Corrida) */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    public function corrida(MigracionExpedientes $m, bool $conPreview): array
    {
        $preview = null;
        $previewError = null;

        if ($conPreview) {
            try {
                $plan = $m->planArray();
                $preview = is_array($plan['maestra']['preview'] ?? null) ? $plan['maestra']['preview'] : null;
                $previewError = $preview === null ? 'El análisis guardado no tiene vista previa: vuelve a analizar el archivo.' : null;
            } catch (RuntimeException $e) {
                $previewError = $e->getMessage();
            }
        }

        $resultado = $m->resultado;

        return [
            'id' => $m->id,
            'archivo' => $m->archivo_nombre,
            'estado' => $m->estado,
            'etapa' => $m->etapa,
            'progreso' => $m->progreso,
            'progreso_total' => $m->progreso_total,
            'error' => $m->error,
            'usuario' => $m->usuario?->nombreCompleto(),
            'creada_en' => $m->created_at?->toIso8601String(),
            'terminada_en' => $m->terminada_en?->toIso8601String(),
            'preview' => $preview,
            'preview_error' => $previewError,
            'resultado' => is_array($resultado) && isset($resultado['colaboradores']) ? array_diff_key($resultado, ['ejecutor' => true]) : null,
        ];
    }

    /**
     * @return list<array{id: int, archivo: string, estado: string, usuario: string|null, creada_en: string|null}>
     */
    public function historial(int $limite = 10): array
    {
        return array_values(MigracionExpedientes::query()
            ->where('tipo', self::TIPO)
            ->with('usuario:id,name,apellidos')
            ->latest('id')
            ->limit($limite)
            ->get()
            ->map(fn (MigracionExpedientes $m) => [
                'id' => $m->id,
                'archivo' => $m->archivo_nombre,
                'estado' => $m->estado,
                'usuario' => $m->usuario?->nombreCompleto(),
                'creada_en' => $m->created_at?->toIso8601String(),
            ])
            ->all());
    }

    /* ------------------------------------------------------------------ */
    /*  Utilidades */
    /* ------------------------------------------------------------------ */

    private function resolutor(): ResolutorCatalogoMigracion
    {
        return $this->planificador->resolutor();
    }

    /**
     * Filas del plan por su llave de vínculo con SALARIOS_VACACIONES:
     * CLAVE_NOI, o CLAVE_NOI|sucursal_id si la clave se repite en
     * COLABORADORES. Una llave que aun así se repite queda en null (ambigua).
     *
     * @param  list<array<string, mixed>>  $filas
     * @return array{0: array<string, array<string, mixed>|null>, 1: array<string, int>}
     */
    private function filasPorLlave(array $filas): array
    {
        $conteo = [];

        foreach ($filas as $f) {
            if (($f['clave'] ?? null) !== null) {
                $conteo[(string) $f['clave']] = ($conteo[(string) $f['clave']] ?? 0) + 1;
            }
        }

        $porLlave = [];

        foreach ($filas as $f) {
            if (($f['clave'] ?? null) === null) {
                continue;
            }

            $llave = $this->llaveFila($f, $conteo);
            $porLlave[$llave] = array_key_exists($llave, $porLlave) ? null : $f;
        }

        return [$porLlave, $conteo];
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  array<string, int>  $conteo
     */
    private function llaveFila(array $f, array $conteo): string
    {
        $clave = (string) ($f['clave'] ?? '');

        return ($conteo[$clave] ?? 0) > 1 ? sprintf('%s|%d', $clave, (int) ($f['sucursal_id'] ?? 0)) : $clave;
    }

    /**
     * @param  array<string, mixed>  $s
     * @param  array<string, int>  $conteo
     */
    private function llaveSalario(array $s, array $conteo): ?string
    {
        $clave = is_string($s['clave'] ?? null) ? $s['clave'] : null;

        if ($clave === null) {
            return null;
        }

        if (($conteo[$clave] ?? 0) <= 1) {
            return $clave;
        }

        $sucursal = $this->inventario->resolverSucursal(is_string($s['sucursal'] ?? null) ? $s['sucursal'] : null);

        return sprintf('%s|%d', $clave, $sucursal->id ?? 0);
    }

    /** @var array<int, Puesto|null> */
    private array $puestosPorId = [];

    private function puestoPorId(int $id): ?Puesto
    {
        return $this->puestosPorId[$id] ??= Puesto::query()->where('id', $id)->first(['id', 'nombre', 'departamento_id']);
    }

    /**
     * Alias explícitos de config (origen normalizado => destino).
     *
     * @return array<string, string>
     */
    private function clavesAlias(string $config): array
    {
        $alias = [];

        foreach ((array) config('expedientes.migracion_inicial.'.$config, []) as $origen => $destino) {
            $alias[Normalizador::clave((string) $origen)] = (string) $destino;
        }

        return $alias;
    }

    private function etapa(MigracionExpedientes $migracion, int $paso, string $nombre): void
    {
        $migracion->update([
            'etapa' => sprintf('Paso %d de %d · %s', $paso, self::TOTAL_ETAPAS, $nombre),
            'progreso' => $paso - 1,
            'progreso_total' => self::TOTAL_ETAPAS,
        ]);
    }

    /**
     * Una etapa que falla no detiene las siguientes: queda en errores.
     *
     * @template T
     *
     * @param  list<string>  $errores
     * @param  callable(): T  $etapa
     * @param  T  $vacio
     * @return T
     */
    private function seguro(array &$errores, string $nombre, callable $etapa, mixed $vacio): mixed
    {
        try {
            return $etapa();
        } catch (Throwable $e) {
            $errores[] = sprintf('%s: %s', $nombre, $e->getMessage());
            Log::warning('Carga inicial PEOPLE: falló una etapa.', ['etapa' => $nombre, 'error' => $e->getMessage()]);

            return $vacio;
        }
    }

    /**
     * Igual que la migración inicial: bytes no UTF-8 del NAS se sustituyen
     * y nunca se guarda un plan que no vuelva a decodificarse.
     *
     * @param  array<string, mixed>  $plan
     */
    private function codificar(array $plan): string
    {
        $json = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

        if (! is_array(json_decode($json, true, 512, JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('El plan de la carga inicial no se pudo serializar a JSON válido.');
        }

        return $json;
    }
}
