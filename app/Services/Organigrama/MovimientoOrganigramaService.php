<?php

namespace App\Services\Organigrama;

use App\Models\ClasificacionRegion;
use App\Models\Colaborador;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Expedientes\DatosExpedienteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Acciones del organigrama sobre una persona: SUBIR (al puesto superior),
 * BAJAR (a un puesto que cuelga del suyo) o MOVER BAJO otro jefe/plaza.
 *
 * Nunca escribe el jefe directo: cambia la PLAZA (puesto, sucursal y, para el
 * Gerente de Sucursal, su lado Q1/Q3) por la misma puerta que «Datos
 * laborales» del expediente (DatosExpedienteService::actualizarLaborales →
 * snapshot → update → JefeDirectoService::sincronizar → movimientos
 * laborales). El jefe nuevo sale del organigrama, como siempre.
 *
 * @phpstan-type Destino array{puesto_id: int, sucursal_id: int|null, region_id: int|null, departamento_id: int|null}
 * @phpstan-type OpcionPuesto array{id: int, nombre: string, ambito: string, clasificable: bool}
 */
class MovimientoOrganigramaService
{
    public const ACCIONES = ['subir', 'bajar', 'mover'];

    public function __construct(
        private readonly OrganigramaPersonasService $organigrama,
        private readonly ClasificacionRegionService $clasificaciones,
        private readonly DatosExpedienteService $datosLaborales,
    ) {}

    /**
     * Qué puede hacerse con esta persona desde el organigrama.
     *
     * @return array{subir: list<OpcionPuesto>, bajar: list<OpcionPuesto>, mover_bajo: list<array{clave: string, etiqueta: string, puesto_id: int, sucursal_id: int|null, region_id: int|null}>, sucursales: list<array{id: int, nombre: string}>, regiones: list<array{id: int, nombre: string, codigo: string}>, actual: array{puesto_id: int|null, sucursal_id: int|null, region_id: int|null}}
     */
    public function opciones(Colaborador $colaborador): array
    {
        $puestos = Puesto::query()->where('activo', true)->orderBy('nivel_jerarquico')->orderBy('nombre')
            ->get(['id', 'nombre', 'puesto_superior_id', 'nivel_jerarquico'])->keyBy('id');
        $actual = $colaborador->puesto_id !== null ? $puestos->get($colaborador->puesto_id) : null;
        $regionales = $this->puestosRegionales();

        $subir = [];
        $bajar = [];

        if ($actual !== null) {
            $superiores = $actual->puesto_superior_id !== null ? [$actual->puesto_superior_id] : [];

            // Subir de Gerente de Sucursal = a cualquiera de los regionales.
            if (array_intersect($superiores, $regionales) !== []) {
                $superiores = $regionales;
            }

            foreach ($superiores as $id) {
                if ($puestos->has($id)) {
                    $subir[] = $this->opcionPuesto($puestos->get($id));
                }
            }

            $esRegional = in_array($actual->id, $regionales, true);

            foreach ($puestos as $puesto) {
                $cuelga = $puesto->puesto_superior_id === $actual->id
                    || ($esRegional && $puesto->puesto_superior_id !== null && in_array($puesto->puesto_superior_id, $regionales, true));

                if ($cuelga && $puesto->id !== $actual->id) {
                    $bajar[] = $this->opcionPuesto($puesto);
                }
            }
        }

        return [
            'subir' => $subir,
            'bajar' => $bajar,
            'mover_bajo' => $actual !== null ? $this->destinosMoverBajo($colaborador, $actual, $regionales) : [],
            // Para puestos de sucursal (el Corporativo no tiene ramas de sucursal).
            'sucursales' => array_values(Sucursal::query()->where('activo', true)->where('es_corporativo', false)->orderBy('nombre')
                ->get(['id', 'nombre'])->map(fn (Sucursal $s): array => ['id' => $s->id, 'nombre' => $s->nombre])->all()),
            'regiones' => $this->clasificaciones->regiones(),
            'actual' => [
                'puesto_id' => $colaborador->puesto_id,
                'sucursal_id' => $colaborador->sucursal_principal_id,
                'region_id' => $this->regionClasificada($colaborador),
            ],
        ];
    }

    /**
     * «Vas a mover a X de Y a Z»: qué cambia y quién quedará como su jefe
     * (simulado, sin guardar nada).
     *
     * @param  array{puesto_id: int, sucursal_id?: int|null, region_id?: int|null}  $datos
     * @return array{descripcion: string, de: string, a: string, jefe_actual: string|null, jefe_nuevo: string|null, avisos: list<string>, sin_cambios: bool}
     */
    public function vistaPrevia(Colaborador $colaborador, array $datos): array
    {
        $destino = $this->normalizar($colaborador, $datos);
        $simulacion = [$colaborador->id => [
            'puesto_id' => $destino['puesto_id'],
            'sucursal_principal_id' => $destino['sucursal_id'],
            'region_id' => $destino['region_id'],
        ]];

        $jefeNuevoId = $this->organigrama->jefesDerivados($simulacion)[$colaborador->id] ?? null;
        $nombres = Colaborador::query()->whereIn('id', array_filter([$jefeNuevoId, $colaborador->jefe_id]))->get()->keyBy('id');

        $de = $this->describir($colaborador->puesto_id, $colaborador->sucursal_principal_id, $this->regionClasificada($colaborador));
        $a = $this->describir($destino['puesto_id'], $destino['sucursal_id'], $destino['region_id']);
        $sinCambios = $this->sinCambios($colaborador, $destino);

        $avisos = [];

        if (! $sinCambios && ($colaborador->puesto_id !== $destino['puesto_id'] || $colaborador->sucursal_principal_id !== $destino['sucursal_id'])) {
            $avisos[] = 'Su plaza actual queda vacante en headcount y la de destino se ocupa (las vacantes se ajustan solas).';
        }

        if ($this->organigrama->esPuestoClasificable($destino['puesto_id']) && $destino['region_id'] === null) {
            $avisos[] = 'Quedará «Por clasificar» en Q1/Q3: mientras tanto su jefe sale de la región de su sucursal en la matriz comercial.';
        }

        $titulares = Colaborador::query()
            ->whereIn('estatus', ['activo', 'en_incorporacion'])
            ->where('puesto_id', $destino['puesto_id'])
            ->when($this->organigrama->ambitoDePuesto($destino['puesto_id']) === 'sucursal', fn ($q) => $q->where('sucursal_principal_id', $destino['sucursal_id']))
            ->whereKeyNot($colaborador->id)
            ->count();

        if ($titulares > 0 && ! $sinCambios) {
            $avisos[] = sprintf('Esa plaza ya tiene %d %s: revisa el headcount autorizado.', $titulares, $titulares === 1 ? 'titular' : 'titulares');
        }

        return [
            'descripcion' => sprintf('Vas a mover a %s de %s a %s.', $colaborador->nombreCompleto(), $de, $a),
            'de' => $de,
            'a' => $a,
            'jefe_actual' => $colaborador->jefe_id !== null ? $nombres->get($colaborador->jefe_id)?->nombreCompleto() : null,
            'jefe_nuevo' => $jefeNuevoId !== null ? $nombres->get($jefeNuevoId)?->nombreCompleto() : null,
            'avisos' => $avisos,
            'sin_cambios' => $sinCambios,
        ];
    }

    /**
     * Aplica el movimiento: plaza (y lado Q1/Q3) → movimiento laboral →
     * jefe directo recalculado (el de la persona y el de quienes le reportan).
     *
     * @param  array{puesto_id: int, sucursal_id?: int|null, region_id?: int|null}  $datos
     */
    public function aplicar(Colaborador $colaborador, array $datos, string $accion, User $actor): void
    {
        $destino = $this->normalizar($colaborador, $datos);

        if ($this->sinCambios($colaborador, $destino)) {
            throw ValidationException::withMessages(['puesto_id' => 'Esa persona ya está en esa plaza: no hay nada que mover.']);
        }

        $regionAntes = $this->regionClasificada($colaborador);
        $de = $this->describir($colaborador->puesto_id, $colaborador->sucursal_principal_id, $regionAntes);
        $a = $this->describir($destino['puesto_id'], $destino['sucursal_id'], $destino['region_id']);
        $motivo = sprintf('Organigrama: %s de %s a %s', match ($accion) {
            'subir' => 'sube',
            'bajar' => 'baja',
            default => 'se mueve',
        }, $de, $a);

        DB::transaction(function () use ($colaborador, $destino, $actor, $motivo, $regionAntes): void {
            // Lado Q1/Q3: solo existe para puestos clasificables; si deja de
            // serlo, la clasificación anterior se retira (no queda colgando).
            if ($destino['region_id'] !== $regionAntes) {
                if ($destino['region_id'] === null) {
                    ClasificacionRegion::query()->where('colaborador_id', $colaborador->id)->delete();
                } else {
                    ClasificacionRegion::query()->updateOrCreate(
                        ['colaborador_id' => $colaborador->id],
                        ['region_id' => $destino['region_id'], 'clasificada_por' => $actor->id],
                    );
                }
            }

            $cambios = [
                'puesto_id' => $destino['puesto_id'],
                'sucursal_principal_id' => $destino['sucursal_id'],
                'motivo' => $motivo,
            ];

            if ($destino['departamento_id'] !== null) {
                $cambios['departamento_id'] = $destino['departamento_id'];
            }

            // Misma puerta que «Datos laborales»: movimiento + jefe derivado.
            $this->datosLaborales->actualizarLaborales($colaborador, $cambios, $actor);
        });

        activity('organigrama')
            ->performedOn($colaborador)
            ->causedBy($actor)
            ->withProperties(['accion' => $accion, 'de' => $de, 'a' => $a])
            ->log('persona_movida');
    }

    /**
     * @param  array{puesto_id: int, sucursal_id?: int|null, region_id?: int|null}  $datos
     * @return Destino
     */
    private function normalizar(Colaborador $colaborador, array $datos): array
    {
        $puesto = Puesto::query()->whereKey($datos['puesto_id'])->where('activo', true)->first(['id', 'nombre', 'departamento_id']);

        if ($puesto === null) {
            throw ValidationException::withMessages(['puesto_id' => 'Elige un puesto activo del catálogo.']);
        }

        $ambito = $this->organigrama->ambitoDePuesto($puesto->id);
        $sucursalId = $datos['sucursal_id'] ?? null;
        $sucursalId = $sucursalId ?? $colaborador->sucursal_principal_id;

        if ($ambito === 'sucursal') {
            $sucursal = $sucursalId !== null ? Sucursal::query()->whereKey($sucursalId)->first(['id', 'es_corporativo', 'activo']) : null;

            if ($sucursal === null || ! $sucursal->activo || $sucursal->es_corporativo) {
                throw ValidationException::withMessages(['sucursal_id' => sprintf('«%s» es un puesto de sucursal: elige la sucursal.', $puesto->nombre)]);
            }
        }

        $regionId = null;

        if ($this->organigrama->esPuestoClasificable($puesto->id)) {
            $regionId = array_key_exists('region_id', $datos) ? $datos['region_id'] : $this->regionClasificada($colaborador);

            if ($regionId !== null && ! NodoComercial::query()->whereKey($regionId)->where('tipo', 'region')->exists()) {
                throw ValidationException::withMessages(['region_id' => 'Elige Q1 o Q3 (una región de la matriz comercial).']);
            }
        }

        return [
            'puesto_id' => $puesto->id,
            'sucursal_id' => $sucursalId,
            'region_id' => $regionId,
            'departamento_id' => $puesto->departamento_id,
        ];
    }

    /**
     * @param  Destino  $destino
     */
    private function sinCambios(Colaborador $colaborador, array $destino): bool
    {
        return $colaborador->puesto_id === $destino['puesto_id']
            && $colaborador->sucursal_principal_id === $destino['sucursal_id']
            && $this->regionClasificada($colaborador) === $destino['region_id'];
    }

    /**
     * Plazas bajo las que puede quedar la persona SIN cambiar de puesto:
     * cualquier nodo (persona, vacante o cobertura) del puesto superior del
     * suyo — p. ej. un subgerente bajo el gerente de otra sucursal (cambia
     * de sucursal) o un Gerente de Sucursal bajo el regional de Q3 (cambia
     * de lado).
     *
     * @param  list<int>  $regionales
     * @return list<array{clave: string, etiqueta: string, puesto_id: int, sucursal_id: int|null, region_id: int|null}>
     */
    private function destinosMoverBajo(Colaborador $colaborador, Puesto $actual, array $regionales): array
    {
        if ($actual->puesto_superior_id === null) {
            return [];
        }

        $superiores = in_array($actual->puesto_superior_id, $regionales, true) ? $regionales : [$actual->puesto_superior_id];
        $deSucursal = $this->organigrama->ambitoDePuesto($actual->id) === 'sucursal';
        $clasificable = $this->organigrama->esPuestoClasificable($actual->id);
        $nodos = $this->organigrama->nodosEstructura();
        $regionActual = $this->regionClasificada($colaborador);
        $destinos = [];
        $vistos = [];
        // El Corporativo no tiene ramas de sucursal: nunca es destino.
        $corporativas = Sucursal::query()->where('es_corporativo', true)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        foreach ($nodos as $clave => $nodo) {
            if (! in_array($nodo['puesto']['id'], $superiores, true) || ($nodo['tipo'] === 'persona' && ($nodo['persona']['id'] ?? null) === $colaborador->id)) {
                continue;
            }

            $sucursalId = $deSucursal && $nodo['de_sucursal'] ? ($nodo['sucursal']['id'] ?? null) : $colaborador->sucursal_principal_id;

            if ($deSucursal && in_array($sucursalId, $corporativas, true)) {
                continue;
            }
            $regionId = $clasificable ? ($nodo['region']['id'] ?? $regionActual) : null;

            if ($sucursalId === $colaborador->sucursal_principal_id && $regionId === $regionActual) {
                continue;
            }

            $llave = sprintf('%s|%s', $sucursalId ?? '-', $regionId ?? '-');

            if (isset($vistos[$llave])) {
                continue;
            }

            $vistos[$llave] = true;
            $lugar = $nodo['sucursal']['nombre'] ?? $nodo['region']['nombre'] ?? null;
            $quien = match ($nodo['tipo']) {
                'vacante' => 'Vacante',
                'cobertura' => sprintf('Cubre temporalmente: %s', $nodo['persona']['nombre'] ?? ''),
                default => $nodo['persona']['nombre'] ?? '',
            };

            $destinos[] = [
                'clave' => $clave,
                'etiqueta' => trim(sprintf('%s%s — %s', $nodo['puesto']['nombre'], $lugar !== null ? ' · '.$lugar : '', $quien)),
                'puesto_id' => $actual->id,
                'sucursal_id' => $sucursalId,
                'region_id' => $regionId,
            ];
        }

        usort($destinos, fn (array $x, array $y): int => strcmp($x['etiqueta'], $y['etiqueta']));

        return $destinos;
    }

    /**
     * @return list<int>
     */
    private function puestosRegionales(): array
    {
        return array_values(NodoComercial::query()->where('tipo', 'region')->whereNotNull('puesto_id')->pluck('puesto_id')
            ->map(fn ($id): int => (int) $id)->unique()->all());
    }

    private function regionClasificada(Colaborador $colaborador): ?int
    {
        $id = ClasificacionRegion::query()->where('colaborador_id', $colaborador->id)->value('region_id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @return OpcionPuesto
     */
    private function opcionPuesto(Puesto $puesto): array
    {
        return [
            'id' => $puesto->id,
            'nombre' => $puesto->nombre,
            'ambito' => $this->organigrama->ambitoDePuesto($puesto->id),
            'clasificable' => $this->organigrama->esPuestoClasificable($puesto->id),
        ];
    }

    private function describir(?int $puestoId, ?int $sucursalId, ?int $regionId): string
    {
        $puesto = $puestoId !== null ? Puesto::query()->whereKey($puestoId)->value('nombre') : null;
        $partes = [is_string($puesto) ? $puesto : 'sin puesto'];

        if ($puestoId !== null && $this->organigrama->ambitoDePuesto($puestoId) === 'sucursal') {
            $sucursal = $sucursalId !== null ? Sucursal::query()->whereKey($sucursalId)->value('nombre') : null;
            $partes[] = is_string($sucursal) ? $sucursal : 'sin sucursal';
        }

        if ($regionId !== null) {
            $region = NodoComercial::query()->whereKey($regionId)->value('nombre');
            $partes[] = is_string($region) ? $region : 'su región';
        } elseif ($puestoId !== null && $this->organigrama->esPuestoClasificable($puestoId)) {
            $partes[] = 'Por clasificar (Q1/Q3)';
        }

        return implode(' · ', $partes);
    }
}
