<?php

namespace App\Services\MovimientosLaborales;

use App\Enums\EstadoUsuario;
use App\Enums\TipoMovimientoLaboral;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Headcount\HeadcountService;
use App\Services\Headcount\PlantillaAutorizadaService;
use App\Services\Headcount\PuestosPlantillaService;
use App\Services\MatrizComercial\MatrizComercialService;
use App\Services\Notificaciones\Aviso;
use App\Services\Notificaciones\NotificacionEventosService;
use App\Services\Organigrama\MovimientoOrganigramaService;
use App\Services\Organigrama\OrganigramaPersonasService;
use App\Services\Organigrama\TraspasoPuestoService;
use App\Support\FechaMexico;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * «Cambio de sucursal» (mismo puesto, o puesto y sucursal a la vez): Puestos
 * y salarios → «Trasladar personas» (¿Qué cambia? Sucursal / Ambos) y el
 * expediente → Datos laborales → «Cambio de sucursal».
 *
 * Reglas (docs/HEADCOUNT_Y_VACANTES.md, docs/MOVIMIENTOS_LABORALES.md):
 *  - PLAZA: en la sucursal destino debe haber plaza autorizada LIBRE del
 *    puesto (misma regla de ocupación que HeadcountService). Si falta, solo
 *    quien edita la plantilla (headcount.editar) puede autorizarla en el
 *    mismo paso («Autorizar plaza en destino», bitácora con fuente
 *    cambio_sucursal); si no, se bloquea con el motivo.
 *  - La plaza de origen se libera y la de destino se ocupa solas:
 *    ColaboradorPlantillaObserver resincroniza ambas vacantes al guardar.
 *  - Escritura por la MISMA puerta que «Datos laborales»
 *    (MovimientoOrganigramaService::aplicarDestino → actualizarLaborales):
 *    movimiento laboral «Cambio de sucursal» (y «Cambio de puesto» /
 *    «Promoción» si también cambia el puesto, «Cambio de jefe» si aplica),
 *    jefe directo recalculado del organigrama (nunca a mano).
 *  - El departamento sale del puesto, no de la sucursal: con el mismo
 *    puesto no cambia; si cambia el puesto, toma el del puesto destino.
 *  - Rutas de cobro (matriz comercial) de la sucursal que deja: se liberan.
 *  - Fecha efectiva: hoy o anterior (nunca antes de su ingreso); el cambio
 *    se aplica al confirmar y los movimientos quedan con esa fecha.
 *  - Alcance, directorio y avisos por sucursal se calculan en vivo desde
 *    sucursal_principal_id: no hay caché que refrescar.
 *  - Avisos (NotificacionEventosService): a la persona y a su nuevo jefe.
 *    Un fallo al avisar nunca deshace el cambio.
 *
 * @phpstan-type PersonaCambio array{id: int, nombre: string, numero_empleado: string|null, de: string, a: string, departamento_de: string|null, departamento_a: string|null, jefe_actual: string|null, jefe_nuevo: string|null, tipo_movimiento: string, sin_cambios: bool, rutas: list<string>}
 * @phpstan-type PlazaDestino array{sucursal_id: int, sucursal: string, puesto: string, autorizadas: int, ocupadas: int, libres: int, necesarias: int, faltan: int}
 * @phpstan-type OpcionSucursal array{id: int, nombre: string, es_corporativo: bool, autorizadas: int, ocupadas: int, libres: int, actual: bool}
 */
class CambioSucursalService
{
    public const MAXIMO = 50;

    public const EVENTO_PROPIO = 'cambio_sucursal';

    public const EVENTO_JEFE = 'cambio_sucursal_equipo';

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly MovimientoOrganigramaService $movimientos,
        private readonly OrganigramaPersonasService $organigrama,
        private readonly TraspasoPuestoService $traspaso,
        private readonly HeadcountService $headcount,
        private readonly PuestosPlantillaService $plantilla,
        private readonly PlantillaAutorizadaService $plantillaAutorizada,
        private readonly MatrizComercialService $matriz,
        private readonly MovimientoLaboralService $movimientosLaborales,
        private readonly NotificacionEventosService $eventos,
    ) {}

    /**
     * Personas a mover, validadas: existen, están vigentes, en el alcance de
     * Expedientes de quien opera y no es uno mismo.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Colaborador>
     */
    public function colaboradores(array $ids, User $actor): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            throw ValidationException::withMessages(['colaborador_ids' => 'Elige al menos a una persona.']);
        }

        if (count($ids) > self::MAXIMO) {
            throw ValidationException::withMessages(['colaborador_ids' => sprintf('Máximo %d personas por cambio.', self::MAXIMO)]);
        }

        $colaboradores = Colaborador::query()->whereKey($ids)->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre'])->get();
        $problemas = [];

        foreach ($ids as $id) {
            $colaborador = $colaboradores->firstWhere('id', $id);

            $problema = match (true) {
                ! $colaborador instanceof Colaborador => sprintf('#%d no existe', $id),
                $actor->colaborador_id === $colaborador->id => 'nadie cambia su propia sucursal',
                ! in_array($colaborador->estatus->value, EstadoUsuario::valoresVigentes(), true) => sprintf('%s no está vigente (%s)', $colaborador->nombreCompleto(), $colaborador->estatus->etiqueta()),
                ! $this->alcance->puedeVerExpediente($actor, $colaborador) => sprintf('%s está fuera de tu alcance', $colaborador->nombreCompleto()),
                $colaborador->puesto_id === null => sprintf('%s no tiene puesto', $colaborador->nombreCompleto()),
                default => null,
            };

            if ($problema !== null) {
                $problemas[] = $problema;
            }
        }

        if ($problemas !== []) {
            throw ValidationException::withMessages(['colaborador_ids' => sprintf('No se puede: %s.', implode('; ', $problemas))]);
        }

        return $colaboradores->values();
    }

    /**
     * Puesto con el que quedan: el destino elegido («Ambos») o el que ya
     * tienen todas las personas (cambio solo de sucursal).
     *
     * @param  Collection<int, Colaborador>  $colaboradores
     */
    public function puestoDestino(Collection $colaboradores, ?int $puestoDestinoId): Puesto
    {
        $id = $puestoDestinoId;

        if ($id === null) {
            $puestos = $colaboradores->pluck('puesto_id')->filter()->unique();

            if ($puestos->count() !== 1) {
                throw ValidationException::withMessages(['puesto_destino_id' => 'Las personas tienen puestos distintos: cámbialas por separado o elige también el puesto.']);
            }

            $id = (int) $puestos->first();
        }

        $puesto = Puesto::query()->whereKey($id)->where('activo', true)->first();

        if (! $puesto instanceof Puesto) {
            throw ValidationException::withMessages(['puesto_destino_id' => 'El puesto destino no existe o está inactivo.']);
        }

        return $puesto;
    }

    /**
     * Sucursales a las que puede ir el puesto, con sus plazas de ese puesto
     * (autorizadas, ocupadas y libres). Un puesto de sucursal nunca va al
     * Corporativo.
     *
     * @param  Collection<int, Colaborador>  $colaboradores
     * @return list<OpcionSucursal>
     */
    public function opciones(Collection $colaboradores, Puesto $puesto): array
    {
        $canonico = $this->plantilla->canonico($puesto->id);
        $deSucursal = $this->organigrama->ambitoDePuesto($puesto->id) === 'sucursal';
        $autorizadas = $this->headcount->plantillaAutorizadaPorSucursalPuesto();
        $ocupadas = $this->headcount->plantillaActualPorSucursalPuesto();
        $actuales = $colaboradores->pluck('sucursal_principal_id')->filter()->map(fn (mixed $id): int => (int) $id)->all();

        return array_values(Sucursal::query()
            ->where('activo', true)
            ->when($deSucursal, fn ($q) => $q->where('es_corporativo', false))
            ->orderByDesc('es_corporativo')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'es_corporativo'])
            ->map(function (Sucursal $sucursal) use ($canonico, $autorizadas, $ocupadas, $actuales): array {
                $clave = sprintf('%d:%d', $sucursal->id, $canonico);
                $plazas = (int) ($autorizadas[$clave] ?? 0);
                $personas = (int) ($ocupadas[$clave] ?? 0);

                return [
                    'id' => (int) $sucursal->id,
                    'nombre' => (string) $sucursal->nombre,
                    'es_corporativo' => (bool) $sucursal->es_corporativo,
                    'autorizadas' => $plazas,
                    'ocupadas' => min($personas, $plazas),
                    'libres' => max($plazas - $personas, 0),
                    'actual' => in_array((int) $sucursal->id, $actuales, true),
                ];
            })
            ->all());
    }

    /**
     * Resumen antes de confirmar, sin guardar nada: por persona origen →
     * destino y jefe actual → jefe nuevo (calculado del organigrama con
     * todas a la vez); la plaza que ocupan en destino y las que liberan.
     *
     * @param  Collection<int, Colaborador>  $colaboradores
     * @param  array{sucursal_id: int, puesto_destino_id?: int|null, fecha?: string|null, autorizar_plaza?: bool}  $datos
     * @return array{personas: list<PersonaCambio>, plaza_destino: PlazaDestino, plazas_origen: list<array{tono: string, texto: string}>, requiere_autorizar: bool, puede_autorizar: bool, bloqueo: string|null, avisos: list<string>, fecha: string, cambia_puesto: bool}
     */
    public function vistaPrevia(Collection $colaboradores, array $datos, User $actor): array
    {
        $puesto = $this->puestoDestino($colaboradores, $datos['puesto_destino_id'] ?? null);
        $destinos = $this->destinos($colaboradores, $puesto, (int) $datos['sucursal_id']);
        $fecha = $this->fecha($colaboradores, $datos['fecha'] ?? null);

        $simulacion = [];

        foreach ($colaboradores as $colaborador) {
            $destino = $destinos[$colaborador->id];
            $simulacion[$colaborador->id] = ['puesto_id' => $destino['puesto_id'], 'sucursal_principal_id' => $destino['sucursal_id'], 'region_id' => $destino['region_id']];
        }

        $jefes = $this->organigrama->jefesDerivados($simulacion);
        $idsNombres = array_values(array_filter([...$colaboradores->pluck('jefe_id')->all(), ...array_values($jefes)]));
        $nombres = Colaborador::query()->whereIn('id', $idsNombres)->get()->keyBy('id');
        $nombre = fn (?int $id): ?string => $id !== null ? $nombres->get($id)?->nombreCompleto() : null;
        $departamentos = Departamento::query()->pluck('nombre', 'id');
        $nombreDepto = fn (?int $id): ?string => $id !== null && is_string($departamentos[$id] ?? null) ? $departamentos[$id] : null;
        $niveles = Puesto::query()->pluck('nivel_jerarquico', 'id');

        $personas = [];
        $avisos = [];

        foreach ($colaboradores as $colaborador) {
            $destino = $destinos[$colaborador->id];
            $sinCambios = $this->movimientos->sinCambios($colaborador, $destino);
            $antes = $colaborador->puesto_id !== null ? $niveles[$colaborador->puesto_id] ?? null : null;
            $despues = $niveles[$destino['puesto_id']] ?? null;
            $tipo = match (true) {
                $sinCambios => 'Sin cambios',
                $colaborador->puesto_id === $destino['puesto_id'] => TipoMovimientoLaboral::CambioSucursal->etiqueta(),
                is_numeric($antes) && is_numeric($despues) && (int) $despues < (int) $antes => sprintf('%s y de sucursal', TipoMovimientoLaboral::Promocion->etiqueta()),
                default => sprintf('%s y de sucursal', TipoMovimientoLaboral::CambioPuesto->etiqueta()),
            };

            if ($sinCambios) {
                $avisos[] = sprintf('%s ya está en %s: quítala de la lista.', $colaborador->nombreCompleto(), $this->lugar($destino['puesto_id'], $destino['sucursal_id']));
            }

            $jefeNuevo = $jefes[$colaborador->id] ?? null;

            if (! $sinCambios && $jefeNuevo === null) {
                $avisos[] = sprintf('%s quedará sin jefe asignable en el organigrama (nadie ocupa el puesto superior en %s). RH lo verá en «Sin jefe asignable».', $colaborador->nombreCompleto(), $this->nombreSucursal($destino['sucursal_id']));
            }

            $personas[] = [
                'id' => (int) $colaborador->id,
                'nombre' => $colaborador->nombreCompleto(),
                'numero_empleado' => $colaborador->numero_empleado !== null ? (string) $colaborador->numero_empleado : null,
                'de' => $this->lugar($colaborador->puesto_id, $colaborador->sucursal_principal_id),
                'a' => $this->lugar($destino['puesto_id'], $destino['sucursal_id']),
                'departamento_de' => $nombreDepto($colaborador->departamento_id),
                'departamento_a' => $nombreDepto($destino['departamento_id'] ?? $colaborador->departamento_id),
                'jefe_actual' => $nombre($colaborador->jefe_id),
                'jefe_nuevo' => $nombre($jefeNuevo),
                'tipo_movimiento' => $tipo,
                'sin_cambios' => $sinCambios,
                'rutas' => $sinCambios ? [] : $this->traspaso->rutasQueDeja($colaborador, $destino),
            ];
        }

        $moviles = $colaboradores->filter(fn (Colaborador $c): bool => ! $this->movimientos->sinCambios($c, $destinos[$c->id]))->values();
        [$plazaDestino, $plazasOrigen] = $this->plazas($moviles, $puesto, (int) $datos['sucursal_id']);
        $puedeAutorizar = $actor->can('headcount.editar');
        $autorizar = (bool) ($datos['autorizar_plaza'] ?? false);
        $bloqueo = null;

        if ($plazaDestino['faltan'] > 0 && ! ($autorizar && $puedeAutorizar)) {
            $bloqueo = sprintf(
                'No hay %s de %s en %s (%d autorizada%s, %d ocupada%s). %s',
                $plazaDestino['faltan'] === 1 ? 'plaza autorizada libre' : sprintf('%d plazas autorizadas libres', $plazaDestino['faltan']),
                $plazaDestino['puesto'],
                $plazaDestino['sucursal'],
                $plazaDestino['autorizadas'],
                $plazaDestino['autorizadas'] === 1 ? '' : 's',
                $plazaDestino['ocupadas'],
                $plazaDestino['ocupadas'] === 1 ? '' : 's',
                $puedeAutorizar
                    ? 'Marca «Autorizar plaza en destino» o elige otra sucursal.'
                    : 'Pide a Gerencia de RH que autorice la plaza (Puestos y salarios → Plantilla autorizada) o elige otra sucursal.',
            );
        }

        if ($moviles->isEmpty()) {
            $bloqueo ??= 'Nadie cambia: todas las personas ya están en ese destino.';
        }

        return [
            'personas' => $personas,
            'plaza_destino' => $plazaDestino,
            'plazas_origen' => $plazasOrigen,
            'requiere_autorizar' => $plazaDestino['faltan'] > 0,
            'puede_autorizar' => $puedeAutorizar,
            'bloqueo' => $bloqueo,
            'avisos' => $avisos,
            'fecha' => $fecha,
            'cambia_puesto' => $colaboradores->contains(fn (Colaborador $c): bool => $c->puesto_id !== $puesto->id),
        ];
    }

    /**
     * Aplica el cambio de todas las personas en una sola transacción.
     *
     * @param  Collection<int, Colaborador>  $colaboradores
     * @param  array{sucursal_id: int, puesto_destino_id?: int|null, fecha?: string|null, autorizar_plaza?: bool, motivo?: string|null}  $datos
     * @return array{sucursal: string, puesto: string, fecha: string, movidos: list<array{id: int, nombre: string, de: string, a: string, jefe_nuevo: string|null}>, plazas_autorizadas: int, rutas_liberadas: int}
     */
    public function aplicar(Collection $colaboradores, array $datos, User $actor): array
    {
        $previa = $this->vistaPrevia($colaboradores, $datos, $actor);

        if ($previa['bloqueo'] !== null) {
            throw ValidationException::withMessages(['sucursal_id' => $previa['bloqueo']]);
        }

        $sinCambios = array_values(array_filter($previa['personas'], fn (array $p): bool => $p['sin_cambios']));

        if ($sinCambios !== []) {
            throw ValidationException::withMessages(['colaborador_ids' => sprintf('Ya están en ese destino (quítalas de la lista): %s.', implode(', ', array_column($sinCambios, 'nombre')))]);
        }

        $puesto = $this->puestoDestino($colaboradores, $datos['puesto_destino_id'] ?? null);
        $destinos = $this->destinos($colaboradores, $puesto, (int) $datos['sucursal_id']);
        $plaza = $previa['plaza_destino'];
        $fecha = $previa['fecha'];
        $sucursalDestino = $this->nombreSucursal((int) $datos['sucursal_id']);
        $motivoCapturado = is_string($datos['motivo'] ?? null) ? trim($datos['motivo']) : '';
        $motivo = $motivoCapturado !== '' ? $motivoCapturado : sprintf('Cambio de sucursal a %s', $sucursalDestino);
        $desde = $this->movimientosLaborales->ultimoId();
        $movidos = [];
        $rutasLiberadas = 0;

        DB::transaction(function () use ($colaboradores, $destinos, $plaza, $puesto, $actor, $motivo, $fecha, $desde, &$movidos, &$rutasLiberadas): void {
            if ($plaza['faltan'] > 0) {
                $this->autorizarPlaza($plaza, $puesto, $colaboradores->count(), $motivo, $actor);
            }

            foreach ($colaboradores as $colaborador) {
                // Cada cambio recalcula jefes: la siguiente persona parte de
                // su estado real (jefe_id incluido) para su snapshot.
                $colaborador->refresh();
                $destino = $destinos[$colaborador->id];
                $de = $this->lugar($colaborador->puesto_id, $colaborador->sucursal_principal_id);
                $rutas = $this->traspaso->rutasQueDeja($colaborador, $destino);

                if ($rutas !== []) {
                    $this->matriz->cerrarAsignacionesDe($colaborador);
                    $rutasLiberadas += count($rutas);
                }

                $this->movimientos->aplicarDestino($colaborador, $destino, $actor, $motivo, $this->movimientos->regionClasificada($colaborador));

                if ($fecha !== FechaMexico::hoy()) {
                    $this->movimientosLaborales->fecharDesde($colaborador, $desde, $fecha);
                }

                $colaborador->refresh();
                $movidos[] = [
                    'id' => (int) $colaborador->id,
                    'nombre' => $colaborador->nombreCompleto(),
                    'de' => $de,
                    'a' => $this->lugar($colaborador->puesto_id, $colaborador->sucursal_principal_id),
                    'jefe_nuevo' => $colaborador->jefe_id !== null ? Colaborador::query()->whereKey($colaborador->jefe_id)->first()?->nombreCompleto() : null,
                ];
            }
        });

        foreach ($movidos as $movido) {
            $colaborador = $colaboradores->firstWhere('id', $movido['id']);

            if (! $colaborador instanceof Colaborador) {
                continue;
            }

            activity('expedientes')
                ->performedOn($colaborador)
                ->causedBy($actor)
                ->withProperties(['de' => $movido['de'], 'a' => $movido['a'], 'fecha_efectiva' => $fecha, 'motivo' => $motivo, 'plaza_autorizada' => $plaza['faltan']])
                ->log('cambio_sucursal');

            $this->avisar($colaborador, $movido, $sucursalDestino, $fecha, $actor, $desde);
        }

        return [
            'sucursal' => $sucursalDestino,
            'puesto' => (string) $puesto->nombre,
            'fecha' => $fecha,
            'movidos' => $movidos,
            'plazas_autorizadas' => $plaza['faltan'],
            'rutas_liberadas' => $rutasLiberadas,
        ];
    }

    /**
     * @param  Collection<int, Colaborador>  $colaboradores
     * @return array<int, array{puesto_id: int, sucursal_id: int|null, region_id: int|null, departamento_id: int|null}>
     */
    private function destinos(Collection $colaboradores, Puesto $puesto, int $sucursalId): array
    {
        $sucursal = Sucursal::query()->whereKey($sucursalId)->where('activo', true)->first(['id']);

        if (! $sucursal instanceof Sucursal) {
            throw ValidationException::withMessages(['sucursal_id' => 'Elige una sucursal activa.']);
        }

        $destinos = [];

        foreach ($colaboradores as $colaborador) {
            // Mismas reglas de destino que el organigrama (un puesto de
            // sucursal nunca va al Corporativo; se conserva su lado Q1/Q3).
            $destino = $this->movimientos->destino($colaborador, ['puesto_id' => $puesto->id, 'sucursal_id' => $sucursalId]);

            // Mismo puesto: el departamento no depende de la sucursal.
            if ($colaborador->puesto_id === $puesto->id) {
                $destino['departamento_id'] = null;
            }

            $destinos[$colaborador->id] = $destino;
        }

        return $destinos;
    }

    /**
     * Plaza que ocupan en destino y plazas que liberan en origen, con las
     * cifras de HeadcountService::plantillaDePar() (plazas compartidas
     * Gestor ↔ Gestor volante sumadas, igual que el traspaso).
     *
     * @param  Collection<int, Colaborador>  $moviles
     * @return array{0: PlazaDestino, 1: list<array{tono: string, texto: string}>}
     */
    private function plazas(Collection $moviles, Puesto $puesto, int $sucursalId): array
    {
        $canonico = $this->plantilla->canonico($puesto->id);
        $grupoDestino = $this->plantilla->compartidos($canonico);
        $sucursalPlaza = $this->plantilla->sucursalDePlaza($canonico, $sucursalId);
        [$autorizadas, $ocupadas] = $this->cifras($sucursalPlaza, $grupoDestino);

        $necesarias = 0;
        $origenes = [];

        foreach ($moviles as $colaborador) {
            $origen = $this->par($colaborador);

            if ($origen !== null && $origen[0] === $sucursalPlaza && in_array($origen[1], $grupoDestino, true)) {
                continue;
            }

            $necesarias++;

            if ($origen !== null) {
                $clave = sprintf('%d:%d', $origen[0], $origen[1]);
                $origenes[$clave] ??= ['sucursal_id' => $origen[0], 'puesto_id' => $origen[1], 'salen' => 0];
                $origenes[$clave]['salen']++;
            }
        }

        $libres = max($autorizadas - $ocupadas, 0);
        $plazaDestino = [
            'sucursal_id' => $sucursalPlaza,
            'sucursal' => $this->nombreSucursal($sucursalPlaza),
            'puesto' => (string) $puesto->nombre,
            'autorizadas' => $autorizadas,
            'ocupadas' => min($ocupadas, $autorizadas),
            'libres' => $libres,
            'necesarias' => $necesarias,
            'faltan' => max($necesarias - $libres, 0),
        ];

        $textos = [];

        if ($necesarias === 0 && $moviles->isNotEmpty()) {
            $textos[] = ['tono' => 'info', 'texto' => sprintf('Siguen ocupando la misma plaza de %s en %s: la plantilla no cambia.', $puesto->nombre, $plazaDestino['sucursal'])];
        } elseif ($necesarias > 0) {
            $ocupa = min($necesarias, $libres);

            if ($ocupa > 0) {
                $textos[] = ['tono' => 'ocupa', 'texto' => sprintf('%s %d %s de %s en %s (quedarán %d de %d ocupadas).', $ocupa === 1 ? 'Ocupará' : 'Ocuparán', $ocupa, $ocupa === 1 ? 'plaza libre' : 'plazas libres', $puesto->nombre, $plazaDestino['sucursal'], min($ocupadas + $ocupa, $autorizadas), $autorizadas)];
            }

            if ($plazaDestino['faltan'] > 0) {
                $textos[] = ['tono' => 'fuera', 'texto' => sprintf('Falta%s %d %s en %s.', $plazaDestino['faltan'] === 1 ? '' : 'n', $plazaDestino['faltan'], $plazaDestino['faltan'] === 1 ? 'plaza autorizada' : 'plazas autorizadas', $plazaDestino['sucursal'])];
            }
        }

        $nombresPuesto = Puesto::withTrashed()->pluck('nombre', 'id');

        foreach ($origenes as $origen) {
            [$aut, $per] = $this->cifras($origen['sucursal_id'], $this->plantilla->compartidos($origen['puesto_id']));
            $liberadas = max($aut - ($per - $origen['salen']), 0) - max($aut - $per, 0);
            $nombrePuesto = is_string($nombresPuesto[$origen['puesto_id']] ?? null) ? $nombresPuesto[$origen['puesto_id']] : 'puesto';
            $lugar = $this->nombreSucursal($origen['sucursal_id']);

            $textos[] = match (true) {
                $liberadas > 0 => ['tono' => 'vacante', 'texto' => sprintf('Se %s %d %s de %s en %s: se abre su vacante automática.', $liberadas === 1 ? 'libera' : 'liberan', $liberadas, $liberadas === 1 ? 'plaza' : 'plazas', $nombrePuesto, $lugar)],
                $aut === 0 => ['tono' => 'info', 'texto' => sprintf('%s en %s no tiene plaza autorizada (estaba fuera de plantilla): no se abre vacante.', $nombrePuesto, $lugar)],
                default => ['tono' => 'info', 'texto' => sprintf('%s en %s tenía más personas que plazas (%d de %d): su salida no abre vacante.', $nombrePuesto, $lugar, $per, $aut)],
            };
        }

        return [$plazaDestino, $textos];
    }

    /**
     * @param  list<int>  $puestos
     * @return array{0: int, 1: int} [autorizadas, personas]
     */
    private function cifras(int $sucursalId, array $puestos): array
    {
        $autorizadas = 0;
        $personas = 0;

        foreach ($puestos as $puestoId) {
            $par = $this->headcount->plantillaDePar($sucursalId, $puestoId);
            $autorizadas += $par['autorizada'];
            $personas += $par['ocupada'];
        }

        return [$autorizadas, $personas];
    }

    /**
     * Plaza (sucursal, puesto de plantilla) donde cuenta hoy esa persona.
     *
     * @return array{0: int, 1: int}|null
     */
    private function par(Colaborador $colaborador): ?array
    {
        if ($colaborador->puesto_id === null || $colaborador->sucursal_principal_id === null) {
            return null;
        }

        $canonico = $this->plantilla->canonico($colaborador->puesto_id);

        return [$this->plantilla->sucursalDePlaza($canonico, $colaborador->sucursal_principal_id), $canonico];
    }

    /**
     * «Autorizar plaza en destino»: suma las que faltan a la plantilla
     * autorizada del par, con bitácora (fuente cambio_sucursal) y
     * resincronización de su vacante. Solo con headcount.editar.
     *
     * @param  PlazaDestino  $plaza
     */
    private function autorizarPlaza(array $plaza, Puesto $puesto, int $personas, string $motivo, User $actor): void
    {
        if (! $actor->can('headcount.editar')) {
            throw ValidationException::withMessages(['autorizar_plaza' => 'Solo Gerencia de RH autoriza plazas.']);
        }

        $sucursal = Sucursal::query()->whereKey($plaza['sucursal_id'])->firstOrFail();
        $canonico = Puesto::query()->whereKey($this->plantilla->canonico($puesto->id))->firstOrFail();
        $actual = $this->headcount->plantillaDePar($sucursal->id, $canonico->id)['autorizada'];

        $this->plantillaAutorizada->actualizar(
            $sucursal,
            $canonico,
            $actual + $plaza['faltan'],
            sprintf('Autorizada en el cambio de sucursal de %d %s: %s', $personas, $personas === 1 ? 'persona' : 'personas', $motivo),
            $actor,
            'cambio_sucursal',
        );
    }

    /**
     * Fecha efectiva: hoy si no se indica; nunca futura ni anterior al
     * ingreso de alguna de las personas.
     *
     * @param  Collection<int, Colaborador>  $colaboradores
     */
    private function fecha(Collection $colaboradores, ?string $fecha): string
    {
        $hoy = FechaMexico::hoy();

        if ($fecha === null || trim($fecha) === '') {
            return $hoy;
        }

        try {
            $valor = Carbon::parse($fecha)->toDateString();
        } catch (Throwable) {
            throw ValidationException::withMessages(['fecha' => 'La fecha efectiva no es válida.']);
        }

        if ($valor > $hoy) {
            throw ValidationException::withMessages(['fecha' => 'La fecha efectiva no puede ser futura: el cambio se aplica al confirmar.']);
        }

        foreach ($colaboradores as $colaborador) {
            $ingreso = $colaborador->fecha_ingreso?->toDateString();

            if ($ingreso !== null && $valor < $ingreso) {
                throw ValidationException::withMessages(['fecha' => sprintf('La fecha efectiva es anterior al ingreso de %s (%s).', $colaborador->nombreCompleto(), $colaborador->fecha_ingreso->format('d/m/Y'))]);
            }
        }

        return $valor;
    }

    /**
     * A la persona (su nueva sucursal y jefe) y a su nuevo jefe directo
     * (se integra a su equipo). Nunca deshace el cambio.
     *
     * @param  array{id: int, nombre: string, de: string, a: string, jefe_nuevo: string|null}  $movido
     */
    private function avisar(Colaborador $colaborador, array $movido, string $sucursal, string $fecha, User $actor, int $desde): void
    {
        try {
            $colaborador->refresh();
            $fechaLegible = Carbon::parse($fecha)->format('d/m/Y');
            $ocurrencia = sprintf('mov-%d', $desde);

            $this->eventos->avisarResultado($colaborador->user, new Aviso(
                self::EVENTO_PROPIO,
                'movimiento_laboral',
                'Cambio de sucursal',
                sprintf('Desde el %s tu sucursal es %s.%s', $fechaLegible, $sucursal, $movido['jefe_nuevo'] !== null ? sprintf(' Tu jefe directo es %s.', $movido['jefe_nuevo']) : ''),
                $colaborador,
                'cambio_sucursal',
                'media',
                [],
                $ocurrencia,
            ));

            $jefe = $colaborador->jefe_id !== null ? Colaborador::query()->whereKey($colaborador->jefe_id)->first()?->user : null;

            if ($jefe instanceof User && $jefe->id !== $actor->id) {
                $this->eventos->avisarResultado($jefe, new Aviso(
                    self::EVENTO_JEFE,
                    'movimiento_laboral',
                    'Se integra a tu equipo',
                    sprintf('%s se integra a %s y te reporta desde el %s.', $movido['nombre'], $sucursal, $fechaLegible),
                    $colaborador,
                    'ver_expediente',
                    'media',
                    [],
                    $ocurrencia,
                    directo: true,
                ));
            }
        } catch (Throwable $e) {
            Log::warning('CambioSucursalService: no se pudo avisar el cambio de sucursal.', ['colaborador_id' => $colaborador->id, 'error' => $e->getMessage()]);
        }
    }

    private function lugar(?int $puestoId, ?int $sucursalId): string
    {
        $puesto = $puestoId !== null ? Puesto::withTrashed()->whereKey($puestoId)->value('nombre') : null;

        return sprintf('%s · %s', is_string($puesto) ? $puesto : 'sin puesto', $sucursalId !== null ? $this->nombreSucursal($sucursalId) : 'sin sucursal');
    }

    private function nombreSucursal(?int $sucursalId): string
    {
        $nombre = $sucursalId !== null ? Sucursal::withTrashed()->whereKey($sucursalId)->value('nombre') : null;

        return is_string($nombre) ? $nombre : 'sin sucursal';
    }
}
