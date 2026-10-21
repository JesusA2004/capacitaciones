<?php

namespace App\Services\Organigrama;

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\Puesto;
use App\Models\ResponsableAprobacion;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Solicitudes\AprobacionOrganizacionalService;
use App\Services\Solicitudes\TareasSolicitudService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Administración de RESPONSABLES DE APROBACIÓN explícitos (Organización →
 * Responsables de aprobación). El jefe organizacional NO se toca aquí: sale
 * del organigrama (JefeDirectoService). Toda asignación queda auditada en
 * activity_log (log «organigrama»: antes/después, quién, cuándo, motivo) y
 * las solicitudes abiertas de las personas afectadas se reencaminan al
 * nuevo responsable (solo se avisa a quien puede actuar).
 */
class ResponsableAprobacionService
{
    public function __construct(
        private readonly AprobacionOrganizacionalService $motor,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly OrganigramaPersonasService $organigrama,
    ) {}

    /**
     * Pantalla: reglas explícitas vigentes, personas (con su jefe
     * organizacional y su responsable de aprobación efectivo) y alertas de
     * estructura que detienen aprobaciones.
     *
     * @return array{reglas: list<array<string, mixed>>, personas: list<array<string, mixed>>, alertas: list<array{tipo: string, colaborador_id: int, nombre: string, mensaje: string}>, puestos: list<array{value: string, label: string}>, candidatos: list<array{value: string, label: string}>}
     */
    public function pantalla(User $usuario): array
    {
        $personas = [];
        $alertas = [];
        $consulta = Colaborador::query()
            ->where('estatus', EstadoUsuario::Activo->value)
            ->whereNotNull('puesto_id')
            ->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre'])
            ->orderBy('name')
            ->orderBy('apellidos');

        foreach ($this->alcance->limitarColaboradoresPorAlcance($consulta, $usuario)->get() as $persona) {
            $resumen = $this->motor->resumenResponsables($persona);
            $personas[] = [
                'id' => $persona->id,
                'nombre' => $persona->nombreCompleto(),
                'puesto' => $persona->puesto?->nombre,
                'sucursal' => $persona->sucursalPrincipal?->nombre,
                ...$resumen,
            ];

            if ($resumen['bloqueado']) {
                $alertas[] = ['tipo' => $resumen['origen'], 'colaborador_id' => $persona->id, 'nombre' => $persona->nombreCompleto(), 'mensaje' => (string) $resumen['mensaje']];
            }
        }

        return [
            'reglas' => $this->reglas(),
            'personas' => $personas,
            'alertas' => $alertas,
            'puestos' => array_values(Puesto::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre'])
                ->map(fn (Puesto $p) => ['value' => sprintf('%d', $p->id), 'label' => $p->nombre])->all()),
            'candidatos' => $this->organigrama->candidatosCobertura($usuario),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function reglas(): array
    {
        return array_values(ResponsableAprobacion::query()
            ->with(['colaborador.puesto:id,nombre', 'puesto:id,nombre', 'responsable.puesto:id,nombre', 'asignadoPor:id,name,apellidos'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (ResponsableAprobacion $r) => [
                'id' => $r->id,
                'ambito' => $r->colaborador_id !== null ? 'persona' : 'puesto',
                'colaborador' => $r->colaborador !== null ? ['id' => $r->colaborador->id, 'nombre' => $r->colaborador->nombreCompleto(), 'puesto' => $r->colaborador->puesto?->nombre] : null,
                'puesto' => $r->puesto !== null ? ['id' => $r->puesto->id, 'nombre' => $r->puesto->nombre] : null,
                'responsable' => $r->responsable !== null ? ['id' => $r->responsable->id, 'nombre' => $r->responsable->nombreCompleto(), 'puesto' => $r->responsable->puesto?->nombre, 'activo' => $r->responsable->estatus === EstadoUsuario::Activo] : null,
                'motivo' => $r->motivo,
                'asignado_por' => $r->asignadoPor?->nombreCompleto(),
                'actualizado_en' => $r->updated_at?->toIso8601String(),
            ])
            ->all());
    }

    /**
     * Organigrama: a cada tarjeta de persona con responsable de aprobación
     * EXPLÍCITO (≠ su reporte organizacional) se le agrega `aprobador`, para
     * distinguir visualmente «reporta a» de «aprueba sus solicitudes».
     *
     * @param  list<array<string, mixed>>  $nodos
     * @return list<array<string, mixed>>
     */
    public function anotarOrganigrama(array $nodos): array
    {
        $reglas = ResponsableAprobacion::query()->with('responsable:id,name,apellidos,estatus')->get();

        if ($reglas->isEmpty()) {
            return $nodos;
        }

        $porPersona = $reglas->whereNotNull('colaborador_id')->keyBy('colaborador_id');
        $porPuesto = $reglas->whereNotNull('puesto_id')->keyBy('puesto_id');

        foreach ($nodos as $i => $nodo) {
            $persona = is_array($nodo['persona'] ?? null) ? $nodo['persona'] : null;
            $puestoId = is_array($nodo['puesto'] ?? null) ? (int) ($nodo['puesto']['id'] ?? 0) : 0;

            if ($persona === null || ($nodo['tipo'] ?? null) !== 'persona') {
                continue;
            }

            $personaId = (int) ($persona['id'] ?? 0);
            $regla = $porPersona->get($personaId) ?? $porPuesto->get($puestoId);
            $responsable = $regla?->responsable;

            if ($regla === null || $responsable === null || $responsable->id === $personaId || $responsable->estatus !== EstadoUsuario::Activo) {
                continue;
            }

            $nodos[$i]['aprobador'] = [
                'id' => $responsable->id,
                'nombre' => $responsable->nombreCompleto(),
                'origen' => $regla->colaborador_id !== null ? 'persona' : 'puesto',
            ];
        }

        return $nodos;
    }

    /**
     * Asigna (o reemplaza) el responsable de aprobación de una persona o de
     * un puesto.
     *
     * @param  array{colaborador_id?: int|null, puesto_id?: int|null, responsable_colaborador_id: int, motivo: string}  $datos
     */
    public function asignar(array $datos, User $actor): ResponsableAprobacion
    {
        $colaboradorId = isset($datos['colaborador_id']) ? (int) $datos['colaborador_id'] : null;
        $puestoId = isset($datos['puesto_id']) ? (int) $datos['puesto_id'] : null;

        if (($colaboradorId === null) === ($puestoId === null)) {
            throw ValidationException::withMessages(['colaborador_id' => 'Elige a una persona o un puesto (solo uno).']);
        }

        $responsable = Colaborador::query()->where('id', (int) $datos['responsable_colaborador_id'])->first();

        if ($responsable === null || $responsable->estatus !== EstadoUsuario::Activo || $responsable->user === null || $responsable->user->acceso_bloqueado_en !== null) {
            throw ValidationException::withMessages(['responsable_colaborador_id' => 'El responsable debe ser una persona activa con cuenta de acceso.']);
        }

        $persona = $colaboradorId !== null ? Colaborador::query()->where('id', $colaboradorId)->first() : null;

        if ($colaboradorId !== null) {
            if ($persona === null) {
                throw ValidationException::withMessages(['colaborador_id' => 'La persona no existe.']);
            }

            if (! $this->alcance->alcanzaColaborador($actor, $persona)) {
                throw ValidationException::withMessages(['colaborador_id' => 'Esa persona está fuera de tu alcance.']);
            }

            if ($persona->id === $responsable->id) {
                throw ValidationException::withMessages(['responsable_colaborador_id' => 'Nadie aprueba sus propias solicitudes.']);
            }

            // Pares que se aprobarían entre sí (A aprueba a B y B a A).
            $inversa = ResponsableAprobacion::query()->where('colaborador_id', $responsable->id)->where('responsable_colaborador_id', $persona->id)->exists();

            if ($inversa) {
                throw ValidationException::withMessages(['responsable_colaborador_id' => sprintf('%s ya tiene a %s como responsable: no pueden aprobarse entre sí.', $responsable->nombreCompleto(), $persona->nombreCompleto())]);
            }
        } elseif ($responsable->puesto_id === $puestoId) {
            throw ValidationException::withMessages(['responsable_colaborador_id' => 'El responsable no puede ocupar el mismo puesto que aprueba (se aprobaría a sí mismo).']);
        }

        $clave = $colaboradorId !== null ? ['colaborador_id' => $colaboradorId] : ['puesto_id' => $puestoId];
        $anterior = ResponsableAprobacion::query()->where($clave)->with('responsable')->first();
        $motivo = trim($datos['motivo']);

        $regla = DB::transaction(fn (): ResponsableAprobacion => ResponsableAprobacion::query()->updateOrCreate($clave, [
            'responsable_colaborador_id' => $responsable->id,
            'motivo' => $motivo,
            'asignado_por' => $actor->id,
        ]));

        $this->auditar($persona, $puestoId, $actor, $anterior === null ? 'responsable_aprobacion_asignado' : 'responsable_aprobacion_cambiado', [
            'antes' => $anterior?->responsable !== null ? ['responsable_id' => $anterior->responsable->id, 'responsable' => $anterior->responsable->nombreCompleto()] : null,
            'despues' => ['responsable_id' => $responsable->id, 'responsable' => $responsable->nombreCompleto()],
            'motivo' => $motivo,
        ]);

        $this->reencaminar($colaboradorId, $puestoId);

        return $regla;
    }

    public function quitar(ResponsableAprobacion $regla, User $actor, ?string $motivo = null): void
    {
        $regla->loadMissing(['responsable', 'colaborador']);

        if ($regla->colaborador !== null && ! $this->alcance->alcanzaColaborador($actor, $regla->colaborador)) {
            throw ValidationException::withMessages(['regla' => 'Esa persona está fuera de tu alcance.']);
        }

        $colaboradorId = $regla->colaborador_id;
        $puestoId = $regla->puesto_id;
        $antes = $regla->responsable !== null ? ['responsable_id' => $regla->responsable->id, 'responsable' => $regla->responsable->nombreCompleto()] : null;

        $regla->delete();

        $this->auditar($regla->colaborador, $puestoId, $actor, 'responsable_aprobacion_quitado', [
            'antes' => $antes,
            'despues' => null,
            'motivo' => $motivo,
        ]);

        $this->reencaminar($colaboradorId, $puestoId);
    }

    /**
     * @param  array<string, mixed>  $propiedades
     */
    private function auditar(?Colaborador $persona, ?int $puestoId, User $actor, string $evento, array $propiedades): void
    {
        $registro = activity('organigrama')->causedBy($actor)->event($evento)->withProperties([
            ...$propiedades,
            'ambito' => $persona !== null ? 'persona' : 'puesto',
            'colaborador_id' => $persona?->id,
            'puesto_id' => $puestoId,
            'modulo' => 'responsables_aprobacion',
        ]);

        if ($persona !== null) {
            $registro->performedOn($persona);
        } elseif ($puestoId !== null && ($puesto = Puesto::query()->whereKey($puestoId)->first()) !== null) {
            $registro->performedOn($puesto);
        }

        $registro->log($evento);
    }

    /**
     * Solicitudes abiertas de las personas afectadas: pasan al nuevo
     * responsable (nunca revierte la asignación si falla).
     */
    private function reencaminar(?int $colaboradorId, ?int $puestoId): void
    {
        try {
            $ids = $colaboradorId !== null
                ? [$colaboradorId]
                : Colaborador::query()->where('puesto_id', $puestoId)->pluck('id')->map(fn ($id) => (int) $id)->all();

            app(TareasSolicitudService::class)->reevaluarPendientes($ids);
        } catch (Throwable $e) {
            Log::warning('ResponsableAprobacionService: no se pudieron reencaminar las solicitudes abiertas.', ['error' => $e->getMessage()]);
        }
    }
}
