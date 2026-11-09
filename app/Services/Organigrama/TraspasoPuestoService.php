<?php

namespace App\Services\Organigrama;

use App\Enums\EstadoUsuario;
use App\Enums\EstadoVacante;
use App\Enums\MotivoVacante;
use App\Enums\TipoAsignacionNodoComercial;
use App\Enums\TipoMovimientoLaboral;
use App\Models\AsignacionNodoComercial;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vacante;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Headcount\HeadcountService;
use App\Services\Headcount\PuestosPlantillaService;
use App\Services\MatrizComercial\MatrizComercialService;
use App\Services\MovimientosLaborales\MovimientoLaboralService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * «Cambiar de puesto (traspaso)» desde el organigrama: una o varias personas
 * pasan a CUALQUIER puesto activo (p. ej. Gestores de sucursal → Auditora de
 * Contraloría, en Corporativo). No es baja ni alta: no crea filas en `bajas`,
 * no registra movimientos «alta»/«baja» ni cambia la fecha de ingreso, así
 * que rotación, ingresos y egresos no se mueven (docs/MOVIMIENTOS_LABORALES.md).
 *
 * Reglas de destino y escritura: las de MovimientoOrganigramaService
 * (Corporativo para puestos corporativos, nunca para puestos de sucursal;
 * misma puerta que «Datos laborales» → movimiento laboral cambio_puesto /
 * promoción con motivo «Traspaso de puesto» + jefe derivado del organigrama).
 * La vacante del par de origen la abre ColaboradorPlantillaObserver; aquí
 * solo se le pone motivo «Reemplazo». Si deja de ser gestor (o cambia de
 * sucursal), sus rutas de cobro quedan libres en la matriz comercial
 * (MatrizComercialService::cerrarAsignacionesDe).
 *
 * @phpstan-type Destino array{puesto_id: int, sucursal_id: int|null, region_id: int|null, departamento_id: int|null}
 * @phpstan-type ImpactoHeadcount array{tono: string, texto: string}
 * @phpstan-type PersonaPrevia array{id: int, nombre: string, de: string, a: string, departamento_de: string|null, departamento_a: string|null, jefe_actual: string|null, jefe_nuevo: string|null, tipo_movimiento: string, sin_cambios: bool, rutas: list<string>}
 */
class TraspasoPuestoService
{
    public const MOTIVO = 'Traspaso de puesto';

    /** Personas por traspaso (cada una recalcula el jefe derivado). */
    public const MAXIMO = 50;

    public function __construct(
        private readonly MovimientoOrganigramaService $movimientos,
        private readonly OrganigramaPersonasService $organigrama,
        private readonly HeadcountService $headcount,
        private readonly PuestosPlantillaService $plantilla,
        private readonly MatrizComercialService $matriz,
        private readonly MovimientoLaboralService $movimientosLaborales,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    /**
     * Catálogos del diálogo: todos los puestos activos (con su ámbito y
     * área), las sucursales activas y las personas vigentes que quien
     * pregunta puede mover (dentro de su alcance).
     *
     * @return array{puestos: list<array{id: int, nombre: string, ambito: string, departamento: string|null}>, sucursales: list<array{id: int, nombre: string, es_corporativo: bool}>, colaboradores: list<array{id: int, nombre: string, numero_empleado: string|null, puesto_id: int|null, puesto: string|null, sucursal_id: int|null, sucursal: string|null, departamento: string|null}>, maximo: int}
     */
    public function opciones(User $actor): array
    {
        $departamentos = Departamento::query()->pluck('nombre', 'id');
        $puestos = Puesto::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'departamento_id']);
        $sucursales = Sucursal::query()->where('activo', true)->orderByDesc('es_corporativo')->orderBy('nombre')->get(['id', 'nombre', 'es_corporativo']);

        $consulta = Colaborador::query()
            ->whereIn('estatus', EstadoUsuario::valoresVigentes())
            ->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre'])
            ->orderBy('name')
            ->orderBy('apellidos');
        $this->alcance->limitarColaboradoresPorAlcance($consulta, $actor);

        // Nadie se mueve a sí mismo (ColaboradorPolicy), salvo super admin.
        if ($actor->colaborador_id !== null && ! $actor->hasRole('super_admin')) {
            $consulta->whereKeyNot($actor->colaborador_id);
        }

        $nombreDepto = fn (?int $id): ?string => $id !== null && is_string($departamentos[$id] ?? null) ? $departamentos[$id] : null;

        return [
            'puestos' => array_values($puestos->map(fn (Puesto $p): array => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'ambito' => $this->organigrama->ambitoDePuesto($p->id),
                'departamento' => $nombreDepto($p->departamento_id),
            ])->all()),
            'sucursales' => array_values($sucursales->map(fn (Sucursal $s): array => [
                'id' => $s->id,
                'nombre' => $s->nombre,
                'es_corporativo' => (bool) $s->es_corporativo,
            ])->all()),
            'colaboradores' => array_values($consulta->get(['id', 'name', 'apellidos', 'numero_empleado', 'puesto_id', 'sucursal_principal_id', 'departamento_id'])
                ->map(fn (Colaborador $c): array => [
                    'id' => $c->id,
                    'nombre' => $c->nombreCompleto(),
                    'numero_empleado' => $c->numero_empleado,
                    'puesto_id' => $c->puesto_id,
                    'puesto' => $c->puesto?->nombre,
                    'sucursal_id' => $c->sucursal_principal_id,
                    'sucursal' => $c->sucursalPrincipal?->nombre,
                    'departamento' => $nombreDepto($c->departamento_id),
                ])->all()),
            'maximo' => self::MAXIMO,
        ];
    }

    /**
     * Vista previa sin guardar nada: por persona, de → a (puesto, sucursal,
     * departamento), jefe actual → jefe nuevo (simulando a TODAS las
     * personas a la vez) y rutas que deja libres; en conjunto, el efecto en
     * headcount (vacantes que se abren en origen, plazas que ocupa o «fuera
     * de plantilla» en destino).
     *
     * @param  Collection<int, Colaborador>  $colaboradores
     * @param  array{puesto_id: int, sucursal_id?: int|null}  $datos
     * @return array{descripcion: string, personas: list<PersonaPrevia>, headcount: list<ImpactoHeadcount>, avisos: list<string>, sin_cambios: bool}
     */
    public function vistaPrevia(Collection $colaboradores, array $datos): array
    {
        $destinos = $this->destinos($colaboradores, $datos);
        $simulacion = [];

        foreach ($colaboradores as $colaborador) {
            $destino = $destinos[$colaborador->id];
            $simulacion[$colaborador->id] = [
                'puesto_id' => $destino['puesto_id'],
                'sucursal_principal_id' => $destino['sucursal_id'],
                'region_id' => $destino['region_id'],
            ];
        }

        $jefes = $this->organigrama->jefesDerivados($simulacion);
        $idsNombres = [];

        foreach ($colaboradores as $colaborador) {
            $idsNombres[] = $colaborador->jefe_id;
            $idsNombres[] = $jefes[$colaborador->id] ?? null;
        }

        $nombres = Colaborador::query()->whereIn('id', array_values(array_filter($idsNombres)))->get()->keyBy('id');
        $nombre = fn (?int $id): ?string => $id !== null ? $nombres->get($id)?->nombreCompleto() : null;
        $niveles = Puesto::query()->pluck('nivel_jerarquico', 'id');
        $departamentos = Departamento::query()->pluck('nombre', 'id');
        $nombreDepto = fn (?int $id): ?string => $id !== null && is_string($departamentos[$id] ?? null) ? $departamentos[$id] : null;

        $personas = [];
        $avisos = [];

        foreach ($colaboradores as $colaborador) {
            $destino = $destinos[$colaborador->id];
            $sinCambios = $this->movimientos->sinCambios($colaborador, $destino);
            $nivelAntes = $colaborador->puesto_id !== null ? $niveles[$colaborador->puesto_id] ?? null : null;
            $nivelDespues = $niveles[$destino['puesto_id']] ?? null;
            $esPromocion = $colaborador->puesto_id !== $destino['puesto_id'] && is_numeric($nivelAntes) && is_numeric($nivelDespues) && (int) $nivelDespues < (int) $nivelAntes;
            $tipo = match (true) {
                $sinCambios => 'Sin cambios',
                $colaborador->puesto_id === $destino['puesto_id'] => TipoMovimientoLaboral::CambioSucursal->etiqueta(),
                $esPromocion => TipoMovimientoLaboral::Promocion->etiqueta(),
                default => TipoMovimientoLaboral::CambioPuesto->etiqueta(),
            };

            if ($sinCambios) {
                $avisos[] = sprintf('%s ya está en esa plaza: se quedará igual.', $colaborador->nombreCompleto());
            }

            $personas[] = [
                'id' => $colaborador->id,
                'nombre' => $colaborador->nombreCompleto(),
                'de' => $this->lugar($colaborador->puesto_id, $colaborador->sucursal_principal_id),
                'a' => $this->lugar($destino['puesto_id'], $destino['sucursal_id']),
                'departamento_de' => $nombreDepto($colaborador->departamento_id),
                'departamento_a' => $nombreDepto($destino['departamento_id'] ?? $colaborador->departamento_id),
                'jefe_actual' => $nombre($colaborador->jefe_id),
                'jefe_nuevo' => $nombre($jefes[$colaborador->id] ?? null),
                'tipo_movimiento' => $tipo,
                'sin_cambios' => $sinCambios,
                'rutas' => $sinCambios ? [] : $this->rutasQueDeja($colaborador, $destino),
            ];
        }

        $moviles = $colaboradores->filter(fn (Colaborador $c): bool => ! $this->movimientos->sinCambios($c, $destinos[$c->id]));
        $primero = $colaboradores->first();
        $a = $primero !== null ? $this->lugar($destinos[$primero->id]['puesto_id'], $destinos[$primero->id]['sucursal_id']) : '';

        return [
            'descripcion' => $colaboradores->count() === 1 && $primero !== null
                ? sprintf('Vas a traspasar a %s de %s a %s.', $primero->nombreCompleto(), $this->lugar($primero->puesto_id, $primero->sucursal_principal_id), $a)
                : sprintf('Vas a traspasar a %d personas a %s.', $colaboradores->count(), $a),
            'personas' => $personas,
            'headcount' => $this->impactoHeadcount($moviles, $destinos),
            'avisos' => $avisos,
            'sin_cambios' => $moviles->isEmpty(),
        ];
    }

    /**
     * Aplica el traspaso de todas las personas en una sola transacción.
     *
     * @param  Collection<int, Colaborador>  $colaboradores
     * @param  array{puesto_id: int, sucursal_id?: int|null}  $datos
     * @return array{personas: int, rutas_liberadas: int, vacantes: int}
     */
    public function aplicar(Collection $colaboradores, array $datos, ?string $comentario, User $actor): array
    {
        $destinos = $this->destinos($colaboradores, $datos);
        $sinCambios = $colaboradores->filter(fn (Colaborador $c): bool => $this->movimientos->sinCambios($c, $destinos[$c->id]));

        if ($sinCambios->isNotEmpty()) {
            throw ValidationException::withMessages(['colaborador_ids' => sprintf('Ya están en esa plaza (quítalos de la lista): %s.', $sinCambios->map(fn (Colaborador $c): string => $c->nombreCompleto())->implode(', '))]);
        }

        $comentario = $comentario !== null && trim($comentario) !== '' ? trim($comentario) : null;
        $desdeMovimiento = $this->movimientosLaborales->ultimoId();
        $desdeVacante = (int) Vacante::withTrashed()->max('id');
        $paresOrigen = [];
        $bitacora = [];
        $rutasLiberadas = 0;

        DB::transaction(function () use ($colaboradores, $destinos, $actor, $comentario, $desdeMovimiento, &$paresOrigen, &$bitacora, &$rutasLiberadas): void {
            foreach ($colaboradores as $colaborador) {
                // Cada traspaso recalcula jefes: la siguiente persona parte
                // de su estado real (jefe_id incluido) para su snapshot.
                $colaborador->refresh();
                $destino = $destinos[$colaborador->id];
                $de = $this->lugar($colaborador->puesto_id, $colaborador->sucursal_principal_id);
                $rutas = $this->rutasQueDeja($colaborador, $destino);

                if ($colaborador->puesto_id !== null && $colaborador->sucursal_principal_id !== null) {
                    $paresOrigen[] = $this->par($colaborador->puesto_id, $colaborador->sucursal_principal_id);
                }

                if ($rutas !== []) {
                    $this->matriz->cerrarAsignacionesDe($colaborador);
                    $rutasLiberadas += count($rutas);
                }

                $this->movimientos->aplicarDestino($colaborador, $destino, $actor, self::MOTIVO, $this->movimientos->regionClasificada($colaborador));

                if ($comentario !== null) {
                    $this->movimientosLaborales->anotarObservaciones($colaborador, $desdeMovimiento, self::MOTIVO, $comentario);
                }

                $bitacora[] = [$colaborador, ['de' => $de, 'a' => $this->lugar($destino['puesto_id'], $destino['sucursal_id']), 'comentario' => $comentario, 'rutas_liberadas' => $rutas]];
            }
        });

        foreach ($bitacora as [$colaborador, $propiedades]) {
            activity('organigrama')
                ->performedOn($colaborador)
                ->causedBy($actor)
                ->withProperties($propiedades)
                ->log('traspaso_puesto');
        }

        return [
            'personas' => $colaboradores->count(),
            'rutas_liberadas' => $rutasLiberadas,
            'vacantes' => $this->marcarVacantesDeReemplazo($paresOrigen, $desdeVacante, $colaboradores->count()),
        ];
    }

    /**
     * @param  Collection<int, Colaborador>  $colaboradores
     * @param  array{puesto_id: int, sucursal_id?: int|null}  $datos
     * @return array<int, Destino>
     */
    private function destinos(Collection $colaboradores, array $datos): array
    {
        if ($colaboradores->isEmpty()) {
            throw ValidationException::withMessages(['colaborador_ids' => 'Elige al menos a una persona.']);
        }

        $noVigentes = $colaboradores->filter(fn (Colaborador $c): bool => ! in_array($c->estatus->value, EstadoUsuario::valoresVigentes(), true));

        if ($noVigentes->isNotEmpty()) {
            throw ValidationException::withMessages(['colaborador_ids' => sprintf('Solo se traspasa a personal vigente: %s ya no lo está.', $noVigentes->map(fn (Colaborador $c): string => $c->nombreCompleto())->implode(', '))]);
        }

        $destinos = [];

        foreach ($colaboradores as $colaborador) {
            // Sin región: el lado Q1/Q3 se retiró de la UI (docs/ORGANIGRAMA.md).
            $destinos[$colaborador->id] = $this->movimientos->destino($colaborador, [
                'puesto_id' => $datos['puesto_id'],
                'sucursal_id' => $datos['sucursal_id'] ?? null,
                'region_id' => null,
            ], true);
        }

        return $destinos;
    }

    /**
     * Rutas de cobro (matriz comercial) que la persona deja: todas en
     * cuanto cambia de puesto o de sucursal (la ruta es de un Gestor de esa
     * sucursal; el Gestor Volante no tiene ruta fija). Entrar a Gestor nunca
     * asigna una ruta: eso se define en la Matriz comercial.
     *
     * @param  Destino  $destino
     * @return list<string>
     */
    private function rutasQueDeja(Colaborador $colaborador, array $destino): array
    {
        $mismaPlaza = $colaborador->puesto_id === $destino['puesto_id']
            && $colaborador->sucursal_principal_id === $destino['sucursal_id'];

        if ($mismaPlaza) {
            return [];
        }

        return array_values(AsignacionNodoComercial::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('activo', true)
            ->with('nodo:id,nombre')
            ->get()
            ->map(fn (AsignacionNodoComercial $a): string => $a->tipo_asignacion === TipoAsignacionNodoComercial::Gestor
                ? sprintf('Dejará libre la ruta %s.', $a->nodo->nombre ?? '(sin nombre)')
                : sprintf('Dejará de apoyar la ruta %s (%s).', $a->nodo->nombre ?? '(sin nombre)', mb_strtolower($a->tipo_asignacion->etiqueta())))
            ->all());
    }

    /**
     * Efecto neto en headcount por plaza (sucursal × puesto de plantilla, con
     * su pareja de plazas compartidas: Gestor Volante ↔ Gestor), con las
     * cifras de HeadcountService::plantillaDePar (solo lectura): personas P,
     * autorizadas A y faltantes F = max(A − P, 0). Tras el traspaso,
     * F' = max(A − (P + Δ), 0).
     *
     * @param  Collection<int, Colaborador>  $moviles
     * @param  array<int, Destino>  $destinos
     * @return list<ImpactoHeadcount>
     */
    private function impactoHeadcount(Collection $moviles, array $destinos): array
    {
        $grupos = [];

        $sumar = function (int $puestoId, ?int $sucursalId, int $delta) use (&$grupos): void {
            if ($sucursalId === null) {
                $grupos['sin_sucursal'] ??= ['sucursal_id' => 0, 'puestos' => [$puestoId], 'delta' => 0, 'mueven' => []];
                $grupos['sin_sucursal']['delta'] += $delta;
                $grupos['sin_sucursal']['mueven'][$puestoId] = true;

                return;
            }

            [$sucursal, $canonico] = $this->par($puestoId, $sucursalId);
            $puestos = $this->plantilla->compartidos($canonico);
            sort($puestos);
            $clave = sprintf('%d:%s', $sucursal, implode(',', $puestos));
            $grupos[$clave] ??= ['sucursal_id' => $sucursal, 'puestos' => $puestos, 'delta' => 0, 'mueven' => []];
            $grupos[$clave]['delta'] += $delta;
            $grupos[$clave]['mueven'][$puestoId] = true;
        };

        foreach ($moviles as $colaborador) {
            if ($colaborador->puesto_id !== null && $colaborador->sucursal_principal_id !== null) {
                $sumar($colaborador->puesto_id, $colaborador->sucursal_principal_id, -1);
            }

            $sumar($destinos[$colaborador->id]['puesto_id'], $destinos[$colaborador->id]['sucursal_id'], 1);
        }

        $nombres = Puesto::withTrashed()->pluck('nombre', 'id');
        $sucursales = Sucursal::withTrashed()->pluck('nombre', 'id');
        $nombre = fn (int $id): string => is_string($nombres[$id] ?? null) ? $nombres[$id] : 'puesto';
        $impactos = [];

        foreach ($grupos as $grupo) {
            $delta = $grupo['delta'];
            $n = abs($delta);

            if ($n === 0) {
                continue;
            }

            $puesto = implode(' / ', array_map($nombre, array_keys($grupo['mueven'])));

            if ($grupo['sucursal_id'] === 0) {
                $impactos[] = ['tono' => 'fuera', 'texto' => sprintf('%s sin sucursal: %s fuera de plantilla.', $puesto, $n === 1 ? 'quedará' : sprintf('%d quedarán', $n))];

                continue;
            }

            $sucursal = is_string($sucursales[$grupo['sucursal_id']] ?? null) ? $sucursales[$grupo['sucursal_id']] : 'su sucursal';
            $autorizadas = 0;
            $personas = 0;
            $faltantes = 0;

            foreach ($grupo['puestos'] as $puestoId) {
                $plaza = $this->headcount->plantillaDePar($grupo['sucursal_id'], $puestoId);
                $autorizadas += $plaza['autorizada'];
                $personas += $plaza['ocupada'];
                $faltantes += $plaza['faltantes'];
            }

            $faltantesDespues = max($autorizadas - ($personas + $delta), 0);
            $otros = array_values(array_diff($grupo['puestos'], array_keys($grupo['mueven'])));
            $comparte = $otros !== [] ? sprintf(' (comparte plazas con %s)', implode(', ', array_map($nombre, $otros))) : '';

            if ($delta < 0) {
                $abiertas = $faltantesDespues - $faltantes;

                $impactos[] = match (true) {
                    $abiertas > 0 => ['tono' => 'vacante', 'texto' => sprintf('Se %s %d %s de %s en %s%s.', $abiertas === 1 ? 'abrirá' : 'abrirán', $abiertas, $abiertas === 1 ? 'vacante' : 'vacantes', $puesto, $sucursal, $comparte)],
                    $autorizadas === 0 => ['tono' => 'info', 'texto' => sprintf('%s en %s no tiene plazas autorizadas: su salida no abre vacante.', $puesto, $sucursal)],
                    default => ['tono' => 'info', 'texto' => sprintf('%s en %s tenía más personas que plazas autorizadas (%d de %d): su salida no abre vacante.', $puesto, $sucursal, $personas, $autorizadas)],
                };

                continue;
            }

            $ocupa = $faltantes - $faltantesDespues;
            $fuera = $n - $ocupa;

            if ($ocupa > 0) {
                $impactos[] = ['tono' => 'ocupa', 'texto' => sprintf('%s %d %s de %s en %s%s.', $ocupa === 1 ? 'Ocupará' : 'Ocuparán', $ocupa, $ocupa === 1 ? 'plaza autorizada' : 'plazas autorizadas', $puesto, $sucursal, $comparte)];
            }

            if ($fuera > 0) {
                $quien = $fuera === 1 ? 'Quedará fuera de plantilla' : sprintf('%d quedarán fuera de plantilla', $fuera);
                $impactos[] = ['tono' => 'fuera', 'texto' => $autorizadas === 0
                    ? sprintf('%s (no hay plaza autorizada de %s en %s).', $quien, $puesto, $sucursal)
                    : ($autorizadas === 1
                        ? sprintf('%s (la única plaza autorizada de %s en %s ya está ocupada).', $quien, $puesto, $sucursal)
                        : sprintf('%s (las %d plazas autorizadas de %s en %s ya están ocupadas).', $quien, $autorizadas, $puesto, $sucursal))];
            }
        }

        return $impactos;
    }

    /**
     * La vacante automática del origen la abre ColaboradorPlantillaObserver
     * (siempre con motivo «Crecimiento»): si nació de este traspaso, se marca
     * como «Reemplazo». Un fallo aquí nunca deshace el traspaso.
     *
     * @param  list<array{0: int, 1: int}>  $pares
     */
    private function marcarVacantesDeReemplazo(array $pares, int $desdeVacante, int $personas): int
    {
        if ($pares === []) {
            return 0;
        }

        try {
            $marcadas = 0;

            foreach ($pares as [$sucursalId, $puestoId]) {
                $marcadas += Vacante::query()
                    ->where('id', '>', $desdeVacante)
                    ->where('sucursal_id', $sucursalId)
                    // Plazas compartidas: la vacante puede abrirse en la pareja.
                    ->whereIn('puesto_id', $this->plantilla->compartidos($puestoId))
                    ->where('generada_automaticamente', true)
                    ->where('motivo', MotivoVacante::Crecimiento->value)
                    ->whereIn('estado', EstadoVacante::valoresAbiertos())
                    ->update([
                        'motivo' => MotivoVacante::Reemplazo->value,
                        'observaciones' => sprintf('Generada automáticamente: la plaza quedó libre por un traspaso de puesto (%d %s).', $personas, $personas === 1 ? 'persona' : 'personas'),
                    ]);
            }

            return $marcadas;
        } catch (Throwable $e) {
            Log::warning('Traspaso de puesto: no se pudo marcar la vacante de origen como reemplazo.', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Plaza (sucursal, puesto de plantilla) donde cuenta esa persona.
     *
     * @return array{0: int, 1: int}
     */
    private function par(int $puestoId, int $sucursalId): array
    {
        $canonico = $this->plantilla->canonico($puestoId);

        return [$this->plantilla->sucursalDePlaza($canonico, $sucursalId), $canonico];
    }

    private function lugar(?int $puestoId, ?int $sucursalId): string
    {
        $puesto = $puestoId !== null ? Puesto::query()->whereKey($puestoId)->value('nombre') : null;
        $sucursal = $sucursalId !== null ? Sucursal::query()->whereKey($sucursalId)->value('nombre') : null;

        return sprintf('%s · %s', is_string($puesto) ? $puesto : 'sin puesto', is_string($sucursal) ? $sucursal : 'sin sucursal');
    }
}
