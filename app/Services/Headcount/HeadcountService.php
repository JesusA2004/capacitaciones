<?php

namespace App\Services\Headcount;

use App\Enums\EstadoUsuario;
use App\Enums\EstatusBaja;
use App\Models\Baja;
use App\Models\CoberturaPuesto;
use App\Models\Colaborador;
use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Support\FechaMexico;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Plantilla autorizada (HeadcountTarget, importada/editada a mano) vs
 * plantilla actual (SIEMPRE calculada en vivo contando colaboradores
 * activos — nunca importada ni capturada). Ver docs/HEADCOUNT_Y_VACANTES.md.
 *
 * "Vacantes" en este servicio es la cifra derivada (autorizada - actual,
 * nunca negativa), no las filas de la tabla `vacantes` — esas se
 * sincronizan a partir de esta cifra en VacanteAutoGenerationService.
 *
 * Todo se agrupa por PUESTO DE PLANTILLA (PuestosPlantillaService). Quién
 * ocupa una plaza (plantillaActualPorSucursalPuesto(), única fuente):
 *  - colaboradores vigentes (activo / en incorporación) titulares, SIN baja
 *    registrada cuyo último día ya llegó (consultaOcupantes(): «cuando
 *    alguien es baja, es baja», aunque su finiquito siga pendiente; si la baja
 *    se cancela vuelve a contar);
 *  - coberturas vigentes «Ocupa ambos puestos» (ocupa_plaza = true): la
 *    segunda plaza cuenta como ocupada; «Cubre mientras se contrata» no;
 *  - plazas compartidas (Gestor volante ↔ Gestor): cada quien ocupa primero
 *    una plaza de su puesto y, si no hay libre, una libre del otro.
 *
 * @phpstan-type SegmentoSinCobertura array{autorizadas: int, ocupadas: int, vacantes: int, excedentes: int, fuera_de_plantilla: int, sin_ubicacion: int, activos: int, en_incorporacion: int, vigentes: int, segundas_plazas: int, bajas_en_tramite: int, bajas_programadas: int}
 * @phpstan-type SegmentoPoblacion array{autorizadas: int, ocupadas: int, vacantes: int, excedentes: int, fuera_de_plantilla: int, sin_ubicacion: int, activos: int, en_incorporacion: int, vigentes: int, segundas_plazas: int, bajas_en_tramite: int, bajas_programadas: int, cobertura: float}
 * @phpstan-type FilaPoblacion array{sucursal_id: int|null, sucursal: string, es_corporativo: bool, autorizadas: int, ocupadas: int, vacantes: int, excedentes: int, fuera_de_plantilla: int, sin_ubicacion: int, activos: int, en_incorporacion: int, vigentes: int, segundas_plazas: int, bajas_en_tramite: int, bajas_programadas: int, cobertura: float}
 */
class HeadcountService
{
    /** Fila de las personas sin sucursal (solo con alcance de toda la empresa). */
    public const SIN_SUCURSAL = 0;

    public const ETIQUETA_SIN_SUCURSAL = 'Sin sucursal asignada';

    /** Todas las cifras de un segmento en cero (misma forma en filas, segmentos y total). */
    private const SEGMENTO_VACIO = ['autorizadas' => 0, 'ocupadas' => 0, 'vacantes' => 0, 'excedentes' => 0, 'fuera_de_plantilla' => 0, 'sin_ubicacion' => 0, 'activos' => 0, 'en_incorporacion' => 0, 'vigentes' => 0, 'segundas_plazas' => 0, 'bajas_en_tramite' => 0, 'bajas_programadas' => 0];

    public function __construct(private readonly PuestosPlantillaService $puestos) {}

    /**
     * Colaboradores que OCUPAN plaza: vigentes (activo / en incorporación)
     * sin una baja registrada (En trámite) cuyo último día ya llegó. La baja
     * es inmediata (docs/BAJAS_Y_CAUSALES.md): el estatus laboral cambia a
     * inactivo hasta que se entrega el finiquito, pero para la plantilla la
     * persona ya es baja desde su último día. Una baja «No procede» (cancelada)
     * ya no la excluye: vuelve a contar sola. Única regla de quién ocupa plaza.
     *
     * @return Builder<Colaborador>
     */
    public function consultaOcupantes(): Builder
    {
        return Colaborador::query()
            ->whereIn('estatus', EstadoUsuario::valoresVigentes())
            ->whereNotIn('id', $this->bajasAplicadas(true)->select('colaborador_id'));
    }

    /**
     * Bajas registradas sin cerrar de personas aún vigentes en su estatus:
     * con $yaEfectivas, las de último día ya llegado (no cuentan en la
     * plantilla); sin él, las de último día futuro (siguen contando).
     *
     * @return Builder<Baja>
     */
    public function bajasAplicadas(bool $yaEfectivas): Builder
    {
        return Baja::query()
            ->whereIn('estatus', EstatusBaja::valoresAplicadasSinCerrar())
            ->whereNotNull('colaborador_id')
            ->whereNotNull('fecha_baja')
            ->whereDate('fecha_baja', $yaEfectivas ? '<=' : '>', FechaMexico::hoy());
    }

    /**
     * Plantilla actual por (sucursal_id, puesto_id): quién ocupa cada plaza
     * (titulares vigentes + coberturas «Ocupa ambos puestos», repartidos entre
     * plazas compartidas). Única fuente de "actual" en todo el módulo.
     *
     * @param  Collection<int, int>|null  $sucursalesIds  null = todas (ya acotadas por el caller vía alcance)
     * @return Collection<string, int> clave "sucursal_id:puesto_id"
     */
    public function plantillaActualPorSucursalPuesto(?Collection $sucursalesIds = null): Collection
    {
        /** @var array<string, int> $personas */
        $personas = [];
        $enAlcance = fn (string $clave): bool => $sucursalesIds === null || $sucursalesIds->contains((int) explode(':', $clave)[0]);

        // Sin filtrar por sucursal en la consulta: el titular de un puesto de
        // empresa cuenta donde vive su plaza, no donde está registrado.
        $titulares = $this->consultaOcupantes()
            ->whereNotNull('puesto_id')
            ->whereNotNull('sucursal_principal_id')
            ->selectRaw('sucursal_principal_id, puesto_id, count(*) as total')
            ->groupBy('sucursal_principal_id', 'puesto_id')
            ->toBase()
            ->get();

        foreach ($titulares as $fila) {
            $puestoId = $this->puestos->canonico((int) $fila->puesto_id);
            $clave = sprintf('%d:%d', $this->puestos->sucursalDePlaza($puestoId, (int) $fila->sucursal_principal_id), $puestoId);
            $personas[$clave] = ($personas[$clave] ?? 0) + (int) $fila->total;
        }

        foreach ($this->segundasPlazasPorSucursalPuesto() as $clave => $total) {
            $personas[$clave] = ($personas[$clave] ?? 0) + $total;
        }

        $personas = array_filter($personas, fn (int $total, string $clave): bool => $enAlcance($clave), ARRAY_FILTER_USE_BOTH);

        return collect($this->repartirPlazasCompartidas($personas, $sucursalesIds));
    }

    /**
     * Coberturas vigentes «Ocupa ambos puestos (sin vacante)» (ocupa_plaza =
     * true, de un colaborador vigente) por la plaza que ocupan. Una
     * cobertura «Cubre mientras se contrata» nunca cuenta.
     *
     * @return array<string, int> clave "sucursal_id:puesto_id"
     */
    public function segundasPlazasPorSucursalPuesto(): array
    {
        $resultado = [];

        $coberturas = CoberturaPuesto::query()
            ->where('activa', true)
            ->where('ocupa_plaza', true)
            ->whereIn('colaborador_id', $this->consultaOcupantes()->select('id'))
            ->get(['puesto_id', 'sucursal_id']);

        foreach ($coberturas as $cobertura) {
            $puestoId = $this->puestos->canonico($cobertura->puesto_id);
            $sucursalId = $this->puestos->sucursalDeCobertura($puestoId, $cobertura->sucursal_id);

            if ($sucursalId === null) {
                continue;
            }

            $clave = sprintf('%d:%d', $sucursalId, $puestoId);
            $resultado[$clave] = ($resultado[$clave] ?? 0) + 1;
        }

        return $resultado;
    }

    /**
     * Plazas compartidas (config headcount.plazas_compartidas, Gestor volante
     * ↔ Gestor): cada persona ocupa primero una plaza de su puesto; quien
     * sobra ocupa una plaza LIBRE del otro puesto de la pareja en la misma
     * sucursal. Lo que sigue sobrando se queda en su puesto (excedente o
     * fuera de plantilla). Así separar las plazas nunca convierte algo
     * ocupado en vacante: el total ocupado de la pareja es el mismo que si
     * fueran una sola plaza.
     *
     * @param  array<string, int>  $personas
     * @param  Collection<int, int>|null  $sucursalesIds
     * @return array<string, int>
     */
    private function repartirPlazasCompartidas(array $personas, ?Collection $sucursalesIds): array
    {
        $parejas = $this->puestos->parejasCompartidas();

        if ($parejas === [] || $personas === []) {
            return $personas;
        }

        $autorizadas = $this->plantillaAutorizadaPorSucursalPuesto($sucursalesIds);

        foreach ($parejas as [$a, $b]) {
            $sucursales = [];

            foreach (array_keys($personas) as $clave) {
                [$sucursalId, $puestoId] = array_map('intval', explode(':', $clave));

                if ($puestoId === $a || $puestoId === $b) {
                    $sucursales[$sucursalId] = true;
                }
            }

            foreach (array_keys($sucursales) as $sucursalId) {
                $claveA = sprintf('%d:%d', $sucursalId, $a);
                $claveB = sprintf('%d:%d', $sucursalId, $b);
                $personasA = $personas[$claveA] ?? 0;
                $personasB = $personas[$claveB] ?? 0;
                $plazasA = (int) ($autorizadas[$claveA] ?? 0);
                $plazasB = (int) ($autorizadas[$claveB] ?? 0);

                $deAaB = min(max($personasA - $plazasA, 0), max($plazasB - $personasB, 0));
                $deBaA = min(max($personasB - $plazasB, 0), max($plazasA - $personasA, 0));

                $nuevoA = $personasA - $deAaB + $deBaA;
                $nuevoB = $personasB - $deBaA + $deAaB;

                foreach ([$claveA => $nuevoA, $claveB => $nuevoB] as $clave => $total) {
                    if ($total > 0) {
                        $personas[$clave] = $total;
                    } else {
                        unset($personas[$clave]);
                    }
                }
            }
        }

        return $personas;
    }

    /**
     * Igual que arriba pero acumulado solo por sucursal (para KPIs de
     * "plantilla actual" que no distinguen puesto).
     *
     * @param  Collection<int, int>|null  $sucursalesIds
     * @return Collection<int, int> clave sucursal_id
     */
    public function plantillaActualPorSucursal(?Collection $sucursalesIds = null): Collection
    {
        return $this->consultaOcupantes()
            ->whereNotNull('sucursal_principal_id')
            ->when($sucursalesIds !== null, fn ($q) => $q->whereIn('sucursal_principal_id', $sucursalesIds))
            ->selectRaw('sucursal_principal_id, count(*) as total')
            ->groupBy('sucursal_principal_id')
            ->pluck('total', 'sucursal_principal_id')
            ->map(fn ($v) => (int) $v);
    }

    /**
     * Resumen por sucursal: autorizada, ocupada (plazas autorizadas con
     * alguien), vacantes y cumplimiento %. Son las mismas filas de
     * poblacionPorSucursal() (misma fórmula, misma atribución), sin la fila
     * «Sin sucursal asignada». Una sucursal con gente pero sin plantilla
     * autorizada sigue en la lista (con 0 autorizada) para que se note.
     *
     * @param  Collection<int, int>|null  $sucursalesIds  null = todas
     * @return Collection<int, array{sucursal_id: int, sucursal: string, plantilla_autorizada: int, plantilla_actual: int, vacantes: int, cumplimiento: float}>
     */
    public function resumenPorSucursal(?Collection $sucursalesIds = null): Collection
    {
        return collect($this->poblacionPorSucursal($sucursalesIds))
            ->filter(fn (array $fila): bool => $fila['sucursal_id'] !== null && ($fila['autorizadas'] > 0 || $fila['vigentes'] > 0 || $fila['segundas_plazas'] > 0))
            ->map(fn (array $fila): array => [
                'sucursal_id' => (int) $fila['sucursal_id'],
                'sucursal' => $fila['sucursal'],
                'plantilla_autorizada' => $fila['autorizadas'],
                'plantilla_actual' => $fila['ocupadas'],
                'vacantes' => $fila['vacantes'],
                'cumplimiento' => $fila['cobertura'],
            ])
            ->sortBy('sucursal')
            ->values();
    }

    /**
     * Drilldown por puesto dentro de una sucursal: autorizado, actual,
     * faltante.
     *
     * @return Collection<int, array{puesto_id: int, puesto: string, plantilla_autorizada: int, plantilla_actual: int, faltante: int}>
     */
    public function resumenPorPuesto(int $sucursalId): Collection
    {
        $targets = HeadcountTarget::query()
            ->whereHas('puesto')
            ->where('sucursal_id', $sucursalId)
            ->get(['puesto_id', 'plantilla_autorizada'])
            ->groupBy(fn (HeadcountTarget $t) => $this->puestos->canonico($t->puesto_id))
            ->map(fn (Collection $lista) => (int) $lista->sum('plantilla_autorizada'));

        $actualPorPuesto = $this->plantillaActualPorSucursalPuesto(collect([$sucursalId]))
            ->mapWithKeys(fn (int $total, string $clave) => [(int) explode(':', $clave)[1] => $total]);

        $puestoIdsUnion = $targets->keys()->merge($actualPorPuesto->keys())->unique()->values();

        $nombresPuesto = Puesto::query()->whereIn('id', $puestoIdsUnion)->pluck('nombre', 'id');

        return $puestoIdsUnion->map(function (int|string $clave) use ($targets, $actualPorPuesto, $nombresPuesto) {
            $puestoId = (int) $clave;
            $autorizada = (int) ($targets[$puestoId] ?? 0);
            $actual = (int) ($actualPorPuesto[$puestoId] ?? 0);

            return [
                'puesto_id' => $puestoId,
                'puesto' => (string) ($nombresPuesto[$puestoId] ?? '—'),
                'plantilla_autorizada' => $autorizada,
                'plantilla_actual' => $actual,
                'faltante' => max($autorizada - $actual, 0),
            ];
        })->sortBy('puesto')->values();
    }

    /**
     * Totales de headcount de un alcance — la cifra que muestran el Inicio
     * operativo, el tablero de RH y (por sucursal) Organización → Sucursales.
     * Es poblacion()['total'] con los nombres de siempre:
     *  - plantilla_autorizada: plazas autorizadas (capacidad);
     *  - plantilla_actual: plazas autorizadas OCUPADAS (por par
     *    sucursal-puesto, nunca más que lo autorizado);
     *  - vacantes: autorizada − ocupada;
     *  - personas_activas: personas vigentes que cuentan en la plantilla
     *    (consultaOcupantes()) — una baja nunca cuenta, aunque su expediente
     *    siga en el NAS o su finiquito siga pendiente;
     *  - fuera_de_plantilla: personas vigentes que NO ocupan una plaza
     *    autorizada (fuera de plantilla + excedentes + sin sucursal/puesto).
     *
     * @param  Collection<int, int>|null  $sucursalesIds
     * @return array{plantilla_autorizada: int, plantilla_actual: int, vacantes: int, cumplimiento: float, sucursales_bajo_cobertura: int, personas_activas: int, fuera_de_plantilla: int}
     */
    public function totalesGenerales(?Collection $sucursalesIds = null): array
    {
        $filas = $this->poblacionPorSucursal($sucursalesIds);
        $total = $this->sumarFilas($filas)['total'];

        return [
            'plantilla_autorizada' => $total['autorizadas'],
            'plantilla_actual' => $total['ocupadas'],
            'vacantes' => $total['vacantes'],
            'cumplimiento' => $total['cobertura'],
            'sucursales_bajo_cobertura' => count(array_filter($filas, fn (array $fila): bool => $fila['sucursal_id'] !== null && $fila['vacantes'] > 0)),
            'personas_activas' => $total['vigentes'],
            'fuera_de_plantilla' => $total['fuera_de_plantilla'] + $total['excedentes'] + $total['sin_ubicacion'],
        ];
    }

    /**
     * FUENTE ÚNICA de las cifras de plantilla (Tablero, Directorio, Reportes y
     * conciliación de plazas). Definiciones en docs/HEADCOUNT_Y_VACANTES.md:
     *  - autorizadas: plazas de headcount_targets;
     *  - ocupadas: por par (sucursal, puesto), min(personas que ocupan, autorizadas);
     *  - vacantes: por par, max(autorizadas − personas que ocupan, 0);
     *  - excedentes: personas por encima de las plazas de un par que sí tiene plazas;
     *  - fuera_de_plantilla: personas en un par sin ninguna plaza autorizada;
     *  - sin_ubicacion: personas sin sucursal o sin puesto (datos por capturar);
     *  - activos: estatus activo; en_incorporacion: contratados aún sin activar
     *    (ocupan plaza); vigentes = activos + en_incorporacion;
     *  - bajas_en_tramite: bajas registradas con último día ya llegado y sin
     *    cerrar (finiquito pendiente): YA NO cuentan en ninguna otra cifra;
     *  - bajas_programadas: bajas registradas con último día futuro: aún
     *    laboran y siguen contando en vigentes.
     *
     * Siempre se cumple, en cada sucursal, en corporativo/sucursales y en el
     * total: autorizadas = ocupadas + vacantes; vigentes + segundas_plazas =
     * ocupadas + excedentes + fuera_de_plantilla + sin_ubicacion. Y el total es
     * la suma exacta de las filas de poblacionPorSucursal() (Corporativo +
     * cada sucursal + «Sin sucursal asignada», que cuenta en «sucursales»).
     *
     * @param  Collection<int, int>|null  $sucursalesIds  null = toda la empresa
     * @return array{total: SegmentoPoblacion, corporativo: SegmentoPoblacion, sucursales: SegmentoPoblacion}
     */
    public function poblacion(?Collection $sucursalesIds = null): array
    {
        return $this->sumarFilas($this->poblacionPorSucursal($sucursalesIds));
    }

    /**
     * Misma fórmula de poblacion() con plazas autorizadas dadas: la vista previa
     * de la importación simula el resultado sin escribir nada.
     *
     * @param  Collection<string, int>  $autorizadas  clave "sucursal_id:puesto_id"
     * @param  Collection<int, int>|null  $sucursalesIds
     * @return array{total: SegmentoPoblacion, corporativo: SegmentoPoblacion, sucursales: SegmentoPoblacion}
     */
    public function conciliar(Collection $autorizadas, ?Collection $sucursalesIds = null): array
    {
        return $this->sumarFilas($this->filasConciliacion($autorizadas, $sucursalesIds));
    }

    /**
     * Una fila por ubicación (Corporativo, cada sucursal y, con alcance de
     * toda la empresa, «Sin sucursal asignada» con sucursal_id null) con
     * TODAS las cifras de poblacion(). Cada persona cuenta en la sucursal de
     * su plaza (un puesto de empresa registrado en una sucursal cuenta donde
     * vive su plaza, igual que la ocupación), así cada fila cuadra sola y la
     * suma de filas es el total. Orden: sucursales por nombre, Corporativo y
     * «Sin sucursal asignada» al final.
     *
     * @param  Collection<int, int>|null  $sucursalesIds  null = toda la empresa
     * @return list<FilaPoblacion>
     */
    public function poblacionPorSucursal(?Collection $sucursalesIds = null): array
    {
        return $this->filasConciliacion($this->plantillaAutorizadaPorSucursalPuesto($sucursalesIds), $sucursalesIds);
    }

    /**
     * Sucursal en la que cuenta una persona: la de su plaza (con puesto y
     * sucursal), su sucursal (sin puesto) o SIN_SUCURSAL.
     */
    public function sucursalDeConteo(?int $puestoId, ?int $sucursalId): int
    {
        if ($sucursalId === null) {
            return self::SIN_SUCURSAL;
        }

        return $puestoId === null ? $sucursalId : $this->puestos->sucursalDePlaza($this->puestos->canonico($puestoId), $sucursalId);
    }

    /**
     * @param  Collection<string, int>  $autorizadas
     * @param  Collection<int, int>|null  $sucursalesIds
     * @return list<FilaPoblacion>
     */
    private function filasConciliacion(Collection $autorizadas, ?Collection $sucursalesIds): array
    {
        $vacio = self::SEGMENTO_VACIO;
        $enAlcance = fn (int $sucursalId): bool => $sucursalesIds === null || ($sucursalId !== self::SIN_SUCURSAL && $sucursalesIds->contains($sucursalId));

        /** @var array<int, SegmentoSinCobertura> $filas */
        $filas = [];

        // Una persona que «ocupa ambos puestos» cuenta una vez como vigente y
        // dos veces en plazas: se reporta aparte para que la suma cuadre.
        foreach ($this->segundasPlazasPorSucursalPuesto() as $clave => $total) {
            $sucursalId = (int) explode(':', $clave)[0];

            if ($enAlcance($sucursalId)) {
                $filas[$sucursalId] ??= $vacio;
                $filas[$sucursalId]['segundas_plazas'] += $total;
            }
        }

        $personas = $this->plantillaActualPorSucursalPuesto($sucursalesIds);

        foreach ($autorizadas->keys()->merge($personas->keys())->unique() as $clave) {
            $sucursalId = (int) explode(':', (string) $clave)[0];
            $plazas = (int) ($autorizadas[$clave] ?? 0);
            $ocupan = (int) ($personas[$clave] ?? 0);

            $filas[$sucursalId] ??= $vacio;
            $filas[$sucursalId]['autorizadas'] += $plazas;
            $filas[$sucursalId]['ocupadas'] += min($ocupan, $plazas);
            $filas[$sucursalId]['vacantes'] += max($plazas - $ocupan, 0);
            $filas[$sucursalId][$plazas > 0 ? 'excedentes' : 'fuera_de_plantilla'] += max($ocupan - $plazas, 0);
        }

        $colaboradores = $this->consultaOcupantes()->toBase()->get(['estatus', 'sucursal_principal_id', 'puesto_id']);

        foreach ($colaboradores as $colaborador) {
            $puestoId = $colaborador->puesto_id !== null ? (int) $colaborador->puesto_id : null;
            $registrada = $colaborador->sucursal_principal_id !== null ? (int) $colaborador->sucursal_principal_id : null;
            $sucursalId = $this->sucursalDeConteo($puestoId, $registrada);

            // Su sucursal de registro sigue en la lista (en ceros si su plaza
            // vive en otra): así se nota que ahí hay gente sin plazas propias.
            if ($registrada !== null && $enAlcance($registrada)) {
                $filas[$registrada] ??= $vacio;
            }

            if (! $enAlcance($sucursalId)) {
                continue;
            }

            $filas[$sucursalId] ??= $vacio;
            $filas[$sucursalId]['vigentes']++;
            $filas[$sucursalId][$colaborador->estatus === EstadoUsuario::Activo->value ? 'activos' : 'en_incorporacion']++;

            if ($registrada === null || $puestoId === null) {
                $filas[$sucursalId]['sin_ubicacion']++;
            }
        }

        foreach (['bajas_en_tramite' => true, 'bajas_programadas' => false] as $campo => $yaEfectivas) {
            foreach ($this->colaboradoresConBaja($yaEfectivas) as $colaborador) {
                $sucursalId = $this->sucursalDeConteo($colaborador->puesto_id, $colaborador->sucursal_principal_id);

                if ($enAlcance($sucursalId)) {
                    $filas[$sucursalId] ??= $vacio;
                    $filas[$sucursalId][$campo]++;
                }
            }
        }

        $sucursales = Sucursal::query()->whereIn('id', array_keys($filas))->get(['id', 'nombre', 'es_corporativo'])->keyBy('id');
        $resultado = [];

        foreach ($filas as $sucursalId => $fila) {
            $sucursal = $sucursales->get($sucursalId);
            $resultado[] = [
                'sucursal_id' => $sucursalId === self::SIN_SUCURSAL ? null : $sucursalId,
                'sucursal' => $sucursalId === self::SIN_SUCURSAL ? self::ETIQUETA_SIN_SUCURSAL : ($sucursal !== null ? (string) $sucursal->nombre : sprintf('Sucursal #%d', $sucursalId)),
                'es_corporativo' => $sucursal !== null && (bool) $sucursal->es_corporativo,
                ...$this->conCobertura($fila),
            ];
        }

        usort($resultado, fn (array $a, array $b): int => [$a['sucursal_id'] === null, $a['es_corporativo'], $a['sucursal']] <=> [$b['sucursal_id'] === null, $b['es_corporativo'], $b['sucursal']]);

        return $resultado;
    }

    /**
     * Colaboradores aún vigentes en su estatus con una baja registrada sin
     * cerrar: con $yaEfectivas, su último día ya llegó (no cuentan); sin él,
     * su último día es futuro (siguen contando).
     *
     * @return Collection<int, Colaborador>
     */
    public function colaboradoresConBaja(bool $yaEfectivas): Collection
    {
        return Colaborador::query()
            ->whereIn('estatus', EstadoUsuario::valoresVigentes())
            ->whereIn('id', $this->bajasAplicadas($yaEfectivas)->select('colaborador_id'))
            ->get();
    }

    /**
     * Suma de filas: corporativo = filas corporativas; sucursales = el resto
     * (incluida «Sin sucursal asignada»); total = corporativo + sucursales.
     *
     * @param  list<FilaPoblacion>  $filas
     * @return array{total: SegmentoPoblacion, corporativo: SegmentoPoblacion, sucursales: SegmentoPoblacion}
     */
    public function sumarFilas(array $filas): array
    {
        $segmentos = ['corporativo' => self::SEGMENTO_VACIO, 'sucursales' => self::SEGMENTO_VACIO];

        foreach ($filas as $fila) {
            $segmento = $fila['es_corporativo'] ? 'corporativo' : 'sucursales';

            foreach (array_keys(self::SEGMENTO_VACIO) as $campo) {
                $segmentos[$segmento][$campo] += $fila[$campo];
            }
        }

        $total = self::SEGMENTO_VACIO;
        foreach (array_keys(self::SEGMENTO_VACIO) as $campo) {
            $total[$campo] = $segmentos['corporativo'][$campo] + $segmentos['sucursales'][$campo];
        }

        return [
            'total' => $this->conCobertura($total),
            'corporativo' => $this->conCobertura($segmentos['corporativo']),
            'sucursales' => $this->conCobertura($segmentos['sucursales']),
        ];
    }

    /**
     * Cobertura = ocupadas / autorizadas × 100; sin plazas autorizadas es 0, nunca división entre cero.
     *
     * @param  SegmentoSinCobertura  $segmento
     * @return SegmentoPoblacion
     */
    private function conCobertura(array $segmento): array
    {
        return [
            ...$segmento,
            'cobertura' => $segmento['autorizadas'] > 0 ? round($segmento['ocupadas'] / $segmento['autorizadas'] * 100, 1) : 0.0,
        ];
    }

    /**
     * Vacante derivada puntual para una (sucursal, puesto): cuántas plazas
     * autorizadas siguen sin cubrir ahora mismo. Usado por
     * VacanteAutoGenerationService para decidir si abrir/cerrar una vacante
     * automática.
     */
    /**
     * Plantilla autorizada vs. plantilla activa por (empresa, sucursal,
     * puesto). La autorizada sale de headcount_targets (capturada/importada);
     * la activa SIEMPRE se calcula de colaboradores vigentes, nunca se
     * captura. Vacantes y excedentes se calculan por par (sucursal, puesto)
     * y luego se suman: un excedente en un puesto no "tapa" la vacante de
     * otro.
     *
     * @param  Collection<int, int>|null  $sucursalesIds
     * @return array{filas: list<array<string, mixed>>, totales: array{autorizados: int, activos: int, vacantes: int, excedentes: int, cobertura: float}}
     */
    public function coberturaDetallada(?Collection $sucursalesIds = null, ?int $empresaId = null): array
    {
        $targets = HeadcountTarget::query()
            ->whereHas('puesto')
            ->when($sucursalesIds !== null, fn ($q) => $q->whereIn('sucursal_id', $sucursalesIds))
            ->when($empresaId !== null, fn ($q) => $q->whereHas('sucursal', fn ($s) => $s->where('empresa_id', $empresaId)))
            ->get(['sucursal_id', 'puesto_id', 'plantilla_autorizada'])
            ->groupBy(fn (HeadcountTarget $t) => sprintf('%d:%d', $t->sucursal_id, $this->puestos->canonico($t->puesto_id)))
            ->map(fn (Collection $lista) => (int) $lista->sum('plantilla_autorizada'));

        $activos = $this->plantillaActualPorSucursalPuesto($sucursalesIds);
        $claves = $targets->keys()->merge($activos->keys())->unique()->values();

        $sucursales = Sucursal::query()->with('empresa:id,nombre')->whereIn('id', $claves->map(fn (int|string $c) => (int) explode(':', (string) $c)[0])->unique())->get(['id', 'nombre', 'empresa_id'])->keyBy('id');
        $puestos = Puesto::query()->whereIn('id', $claves->map(fn (int|string $c) => (int) explode(':', (string) $c)[1])->unique())->pluck('nombre', 'id');

        $filas = [];

        foreach ($claves as $clave) {
            [$sucursalId, $puestoId] = array_map('intval', explode(':', (string) $clave));
            $sucursal = $sucursales->get($sucursalId);

            if ($empresaId !== null && $sucursal?->empresa_id !== $empresaId) {
                continue;
            }

            $autorizados = (int) ($targets->get($clave) ?? 0);
            $activosPar = (int) ($activos->get($clave) ?? 0);

            $filas[] = [
                'empresa_id' => $sucursal?->empresa_id,
                'empresa' => $sucursal?->empresa?->nombre,
                'sucursal_id' => $sucursalId,
                'sucursal' => $sucursal?->nombre,
                'puesto_id' => $puestoId,
                'puesto' => $puestos->get($puestoId),
                'autorizados' => $autorizados,
                'activos' => $activosPar,
                'vacantes' => max($autorizados - $activosPar, 0),
                'excedentes' => max($activosPar - $autorizados, 0),
                'cobertura' => $autorizados > 0 ? round(min($activosPar, $autorizados) / $autorizados * 100, 1) : 0.0,
            ];
        }

        usort($filas, fn (array $a, array $b) => [$a['empresa'], $a['sucursal'], $a['puesto']] <=> [$b['empresa'], $b['sucursal'], $b['puesto']]);

        $autorizados = array_sum(array_column($filas, 'autorizados'));
        $vacantes = array_sum(array_column($filas, 'vacantes'));

        return [
            'filas' => $filas,
            'totales' => [
                'autorizados' => $autorizados,
                'activos' => array_sum(array_column($filas, 'activos')),
                'vacantes' => $vacantes,
                'excedentes' => array_sum(array_column($filas, 'excedentes')),
                'cobertura' => $autorizados > 0 ? round(($autorizados - $vacantes) / $autorizados * 100, 1) : 0.0,
            ],
        ];
    }

    public function vacantesDerivadas(int $sucursalId, int $puestoId): int
    {
        return $this->plantillaDePar($sucursalId, $puestoId)['faltantes'];
    }

    /**
     * ÚNICA regla de una plaza: autorizada (headcount_targets), ocupada
     * (plantillaActualPorSucursalPuesto(): titulares vigentes, coberturas
     * «Ocupa ambos puestos» y plazas compartidas Gestor volante ↔ Gestor) y
     * faltantes = max(autorizada − ocupada, 0). Una cobertura «Cubre mientras
     * se contrata» nunca cuenta, sea de sucursal o de empresa. Un puesto
     * eliminado del catálogo (SoftDeletes) ya no tiene plazas: nunca abre ni
     * sostiene una vacante.
     *
     * @return array{autorizada: int, ocupada: int, faltantes: int}
     */
    public function plantillaDePar(int $sucursalId, int $puestoId): array
    {
        if (! $this->puestos->esCanonico($puestoId)) {
            return ['autorizada' => 0, 'ocupada' => 0, 'faltantes' => 0];
        }

        $equivalentes = $this->puestos->equivalentes($puestoId);

        $autorizada = (int) HeadcountTarget::query()
            ->whereHas('puesto')
            ->where('sucursal_id', $sucursalId)
            ->whereIn('puesto_id', $equivalentes)
            ->sum('plantilla_autorizada');

        $ocupada = (int) ($this->plantillaActualPorSucursalPuesto(collect([$sucursalId]))[sprintf('%d:%d', $sucursalId, $puestoId)] ?? 0);

        return ['autorizada' => $autorizada, 'ocupada' => $ocupada, 'faltantes' => max($autorizada - $ocupada, 0)];
    }

    /**
     * Plantilla autorizada por (sucursal_id, puesto de plantilla), misma
     * agrupación que plantillaActualPorSucursalPuesto().
     *
     * @param  Collection<int, int>|null  $sucursalesIds
     * @return Collection<string, int> clave "sucursal_id:puesto_id"
     */
    public function plantillaAutorizadaPorSucursalPuesto(?Collection $sucursalesIds = null): Collection
    {
        return HeadcountTarget::query()
            ->whereHas('puesto')
            ->when($sucursalesIds !== null, fn ($q) => $q->whereIn('sucursal_id', $sucursalesIds))
            ->get(['sucursal_id', 'puesto_id', 'plantilla_autorizada'])
            ->groupBy(fn (HeadcountTarget $t) => sprintf('%d:%d', $t->sucursal_id, $this->puestos->canonico($t->puesto_id)))
            ->map(fn (Collection $lista) => (int) $lista->sum('plantilla_autorizada'));
    }

    /**
     * Todos los pares (sucursal_id, puesto_id) con un HeadcountTarget
     * vigente — universo que VacanteAutoGenerationService::sincronizarTodo()
     * recorre.
     *
     * @return Collection<int, array{sucursal_id: int, puesto_id: int}>
     */
    public function paresConTarget(): Collection
    {
        return HeadcountTarget::query()
            ->select('sucursal_id', 'puesto_id')
            ->get()
            ->map(fn (HeadcountTarget $t) => ['sucursal_id' => $t->sucursal_id, 'puesto_id' => $this->puestos->canonico($t->puesto_id)])
            ->unique(fn (array $par) => sprintf('%d:%d', $par['sucursal_id'], $par['puesto_id']))
            ->values();
    }
}
