<?php

namespace App\Services\CicloLaboral;

use App\Enums\EstadoAprobacion;
use App\Enums\EtapaAprobacion;
use App\Enums\ProcesoAprobacion;
use App\Models\Aprobacion;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Motor genérico de aprobaciones del ciclo laboral:
 *
 *   preautorización operativa (superior según organigrama)
 *     → autorización final de RH
 *
 * Reglas:
 *  - Preautorizar NO es autorizar: el efecto final de cualquier proceso
 *    (contratar, renovar, no renovar, dar de baja, reingresar) solo ocurre
 *    con la etapa autorizacion_rh aprobada. No existe forma de saltarla.
 *  - Quien preautorizó una ronda no puede dar también la autorización RH
 *    de esa misma ronda (segregación de funciones).
 *  - Cada decisión guarda actor, fecha, comentario, snapshot del decisor,
 *    IP y user agent; las rondas devueltas se conservan como historial.
 *  - Todas las decisiones bloquean la fila pendiente (lockForUpdate) y la
 *    tabla tiene índice único por etapa+ronda: dos decisiones concurrentes
 *    nunca producen dos transiciones.
 *
 * Este service NO abre tareas ni notifica: lo hace el service del dominio
 * (que sabe el título, la acción y a quién avisar).
 */
class AprobacionService
{
    public function __construct(
        private readonly OrganizacionJerarquiaService $jerarquia,
        private readonly AuditoriaService $auditoria,
        private readonly ?Request $request = null,
    ) {}

    /**
     * Abre una ronda de aprobación. La preautorización queda:
     *  - aprobada al instante si es implícita (el solicitante es el superior),
     *  - "no aplica" si no hay superior operativo (con motivo),
     *  - pendiente en cualquier otro caso.
     * Si la preautorización quedó resuelta, se abre de inmediato la etapa RH.
     *
     * @param  array{colaborador?: Colaborador|null, candidato?: Candidato|null, solicitante?: User|null, aprobador_colaborador?: Colaborador|null, aprobador_user?: User|null, implicita?: bool, motivo_omision?: string|null, decision?: string|null, comentario?: string|null}  $contexto
     */
    public function abrir(Model $aprobable, ProcesoAprobacion $proceso, array $contexto): Aprobacion
    {
        return DB::transaction(function () use ($aprobable, $proceso, $contexto): Aprobacion {
            $ronda = $this->rondaActual($aprobable, $proceso);

            if ($ronda > 0 && $this->pendiente($aprobable, $proceso) !== null) {
                throw ValidationException::withMessages(['aprobacion' => 'Ya hay una aprobación en curso para este proceso.']);
            }

            $ronda++;
            $solicitante = $contexto['solicitante'] ?? null;
            $implicita = (bool) ($contexto['implicita'] ?? false);
            $motivoOmision = $contexto['motivo_omision'] ?? null;
            $aprobadorColaborador = $contexto['aprobador_colaborador'] ?? null;
            $aprobadorUsuario = $contexto['aprobador_user'] ?? $aprobadorColaborador?->user;

            $estadoPre = match (true) {
                $implicita => EstadoAprobacion::Aprobado,
                $motivoOmision !== null && $aprobadorColaborador === null && $aprobadorUsuario === null => EstadoAprobacion::Omitido,
                default => EstadoAprobacion::Pendiente,
            };

            $base = $this->base($aprobable, $proceso, $ronda, $contexto);

            $pre = $this->crear([
                ...$base,
                'etapa' => EtapaAprobacion::Preautorizacion,
                'aprobador_colaborador_id' => $aprobadorColaborador?->id,
                'aprobador_user_id' => $aprobadorUsuario?->id,
                'capacidad_requerida' => OrganizacionJerarquiaService::PERMISO_PREAUTORIZAR,
                'estado' => $estadoPre,
                'decision' => $implicita ? ($contexto['decision'] ?? null) : null,
                'comentario' => match ($estadoPre) {
                    EstadoAprobacion::Aprobado => $contexto['comentario'] ?? 'Preautorizado al solicitar (el solicitante es superior operativo de la persona).',
                    EstadoAprobacion::Omitido => $motivoOmision,
                    default => null,
                },
                'decidido_por_user_id' => $implicita ? $solicitante?->id : null,
                'decidido_en' => $estadoPre === EstadoAprobacion::Pendiente ? null : now(),
                'decisor_snapshot' => $implicita && $solicitante !== null ? $this->snapshot($solicitante) : null,
                ...($implicita ? $this->contextoHttp() : []),
            ]);

            if ($estadoPre === EstadoAprobacion::Pendiente) {
                return $pre;
            }

            return $this->abrirEtapaRh($aprobable, $proceso, $ronda, $base);
        });
    }

    /**
     * Preautorización del superior operativo. Devuelve la etapa RH abierta.
     */
    public function preautorizar(Model $aprobable, ProcesoAprobacion $proceso, User $actor, ?string $comentario = null, ?string $decision = null): Aprobacion
    {
        return DB::transaction(function () use ($aprobable, $proceso, $actor, $comentario, $decision): Aprobacion {
            $pendiente = $this->pendienteBloqueada($aprobable, $proceso, EtapaAprobacion::Preautorizacion);

            if (! $this->jerarquia->puedePreautorizar($actor, $pendiente)) {
                throw ValidationException::withMessages(['aprobacion' => 'No te corresponde preautorizar: solo el superior operativo de la persona (según el organigrama) puede hacerlo.']);
            }

            $this->decidir($pendiente, $actor, EstadoAprobacion::Aprobado, $comentario, $decision);
            $this->auditar('aprobacion_preautorizada', $pendiente, $actor);

            return $this->abrirEtapaRh($aprobable, $proceso, $pendiente->ronda, $this->base($aprobable, $proceso, $pendiente->ronda, [
                'colaborador_id' => $pendiente->colaborador_id,
                'candidato_id' => $pendiente->candidato_id,
                'solicitante_user_id' => $pendiente->solicitante_user_id,
            ]));
        });
    }

    /**
     * Autorización final de RH.
     */
    public function autorizarRh(Model $aprobable, ProcesoAprobacion $proceso, User $actor, ?string $comentario = null, ?string $decision = null): Aprobacion
    {
        return DB::transaction(function () use ($aprobable, $proceso, $actor, $comentario, $decision): Aprobacion {
            $pendiente = $this->pendienteBloqueada($aprobable, $proceso, EtapaAprobacion::AutorizacionRh);
            $this->exigirRh($actor, $pendiente);

            $this->decidir($pendiente, $actor, EstadoAprobacion::Aprobado, $comentario, $decision);
            $this->auditar('aprobacion_autorizada_rh', $pendiente, $actor);

            return $pendiente;
        });
    }

    /**
     * Rechazo definitivo en la etapa que esté pendiente. Motivo obligatorio.
     */
    public function rechazar(Model $aprobable, ProcesoAprobacion $proceso, User $actor, string $motivo): Aprobacion
    {
        $this->exigirMotivo($motivo);

        return DB::transaction(function () use ($aprobable, $proceso, $actor, $motivo): Aprobacion {
            $pendiente = $this->pendienteBloqueada($aprobable, $proceso);
            $this->exigirDecisor($actor, $pendiente);

            $this->decidir($pendiente, $actor, EstadoAprobacion::Rechazado, $motivo);
            $this->auditar('aprobacion_rechazada', $pendiente, $actor, ['motivo' => $motivo]);

            return $pendiente;
        });
    }

    /**
     * RH devuelve para corrección: la ronda se cierra como "devuelta" y se
     * abre una ronda nueva con la preautorización pendiente (el superior
     * corrige y vuelve a preautorizar). Motivo obligatorio.
     */
    public function devolver(Model $aprobable, ProcesoAprobacion $proceso, User $actor, string $motivo): Aprobacion
    {
        $this->exigirMotivo($motivo);

        return DB::transaction(function () use ($aprobable, $proceso, $actor, $motivo): Aprobacion {
            $pendiente = $this->pendienteBloqueada($aprobable, $proceso, EtapaAprobacion::AutorizacionRh);
            $this->exigirRh($actor, $pendiente);

            $this->decidir($pendiente, $actor, EstadoAprobacion::Devuelto, $motivo);
            $this->auditar('aprobacion_devuelta', $pendiente, $actor, ['motivo' => $motivo]);

            $preAnterior = Aprobacion::query()
                ->where('aprobable_type', $aprobable->getMorphClass())
                ->where('aprobable_id', $aprobable->getKey())
                ->where('proceso', $proceso->value)
                ->where('etapa', EtapaAprobacion::Preautorizacion->value)
                ->where('ronda', $pendiente->ronda)
                ->first();

            return $this->crear([
                ...$this->base($aprobable, $proceso, $pendiente->ronda + 1, [
                    'colaborador_id' => $pendiente->colaborador_id,
                    'candidato_id' => $pendiente->candidato_id,
                    'solicitante_user_id' => $pendiente->solicitante_user_id,
                ]),
                'etapa' => EtapaAprobacion::Preautorizacion,
                'aprobador_colaborador_id' => $preAnterior->aprobador_colaborador_id ?? $preAnterior?->decididoPor?->colaborador_id,
                'aprobador_user_id' => $preAnterior->aprobador_user_id ?? $preAnterior?->decidido_por_user_id,
                'capacidad_requerida' => OrganizacionJerarquiaService::PERMISO_PREAUTORIZAR,
                'estado' => EstadoAprobacion::Pendiente,
            ]);
        });
    }

    /**
     * Cancela lo pendiente cuando el proceso se cancela por otra vía.
     */
    public function cancelarPendientes(Model $aprobable, ProcesoAprobacion $proceso, ?User $actor = null, ?string $motivo = null): void
    {
        $this->query($aprobable, $proceso)
            ->where('estado', EstadoAprobacion::Pendiente->value)
            ->update([
                'estado' => EstadoAprobacion::Cancelado->value,
                'comentario' => $motivo,
                'decidido_por_user_id' => $actor?->id,
                'decidido_en' => now(),
                'updated_at' => now(),
            ]);
    }

    public function pendiente(Model $aprobable, ProcesoAprobacion $proceso): ?Aprobacion
    {
        return $this->query($aprobable, $proceso)
            ->where('estado', EstadoAprobacion::Pendiente->value)
            ->orderByDesc('ronda')
            ->first();
    }

    public function estaAutorizadoPorRh(Model $aprobable, ProcesoAprobacion $proceso): bool
    {
        return $this->query($aprobable, $proceso)
            ->where('etapa', EtapaAprobacion::AutorizacionRh->value)
            ->where('estado', EstadoAprobacion::Aprobado->value)
            ->exists();
    }

    /**
     * @return Collection<int, Aprobacion>
     */
    public function historial(Model $aprobable, ProcesoAprobacion $proceso): Collection
    {
        return $this->query($aprobable, $proceso)
            ->with(['decididoPor:id,name,apellidos,colaborador_id', 'aprobadorColaborador:id,name,apellidos', 'aprobadorUsuario:id,name,apellidos,colaborador_id'])
            ->orderBy('ronda')
            ->orderBy('id')
            ->get()
            ->toBase();
    }

    /**
     * Respuesta directa a "¿quién preautorizó?" y "¿RH ya autorizó?".
     *
     * @return array<string, mixed>
     */
    public function resumen(Model $aprobable, ProcesoAprobacion $proceso): array
    {
        $historial = $this->historial($aprobable, $proceso);
        $ronda = (int) ($historial->max('ronda') ?? 0);
        $actuales = $historial->where('ronda', $ronda);
        $pre = $actuales->first(fn (Aprobacion $a) => $a->etapa === EtapaAprobacion::Preautorizacion);
        $rh = $actuales->first(fn (Aprobacion $a) => $a->etapa === EtapaAprobacion::AutorizacionRh);
        $pendiente = $actuales->first(fn (Aprobacion $a) => $a->estado === EstadoAprobacion::Pendiente);

        return [
            'proceso' => $proceso->value,
            'ronda' => $ronda,
            'preautorizacion' => $pre !== null ? $this->aArray($pre) : null,
            'autorizacion_rh' => $rh !== null ? $this->aArray($rh) : null,
            'pendiente' => $pendiente !== null ? [
                'etapa' => $pendiente->etapa->value,
                'etapa_etiqueta' => $pendiente->etapa->etiqueta(),
                'aprobador' => $this->nombreAprobador($pendiente),
            ] : null,
            'autorizado_rh' => $rh?->estado === EstadoAprobacion::Aprobado,
            'historial' => $historial->map(fn (Aprobacion $a) => $this->aArray($a))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function aArray(Aprobacion $aprobacion): array
    {
        return [
            'id' => $aprobacion->id,
            'proceso' => $aprobacion->proceso->value,
            'etapa' => $aprobacion->etapa->value,
            'etapa_etiqueta' => $aprobacion->etapa->etiqueta(),
            'ronda' => $aprobacion->ronda,
            'estado' => $aprobacion->estado->value,
            'estado_etiqueta' => $aprobacion->estado->etiqueta(),
            'decision' => $aprobacion->decision,
            'comentario' => $aprobacion->comentario,
            'aprobador' => $this->nombreAprobador($aprobacion),
            'decidido_por' => $aprobacion->decisor_snapshot['nombre'] ?? $aprobacion->decididoPor?->nombreCompleto(),
            'decidido_por_puesto' => $aprobacion->decisor_snapshot['puesto'] ?? null,
            'decidido_en' => $aprobacion->decidido_en?->toIso8601String(),
            'creada_en' => $aprobacion->created_at?->toIso8601String(),
        ];
    }

    private function nombreAprobador(Aprobacion $aprobacion): ?string
    {
        if ($aprobacion->etapa === EtapaAprobacion::AutorizacionRh) {
            return 'Recursos Humanos';
        }

        return $aprobacion->aprobadorColaborador?->nombreCompleto() ?? $aprobacion->aprobadorUsuario?->nombreCompleto();
    }

    /**
     * @param  array<string, mixed>  $base
     */
    private function abrirEtapaRh(Model $aprobable, ProcesoAprobacion $proceso, int $ronda, array $base): Aprobacion
    {
        return $this->crear([
            ...$base,
            'ronda' => $ronda,
            'etapa' => EtapaAprobacion::AutorizacionRh,
            'capacidad_requerida' => OrganizacionJerarquiaService::PERMISO_AUTORIZAR_RH,
            'estado' => EstadoAprobacion::Pendiente,
        ]);
    }

    /**
     * @param  array<string, mixed>  $contexto
     * @return array<string, mixed>
     */
    private function base(Model $aprobable, ProcesoAprobacion $proceso, int $ronda, array $contexto): array
    {
        $colaborador = $contexto['colaborador'] ?? null;
        $candidato = $contexto['candidato'] ?? null;
        $solicitante = $contexto['solicitante'] ?? null;

        return [
            'aprobable_type' => $aprobable->getMorphClass(),
            'aprobable_id' => $aprobable->getKey(),
            'proceso' => $proceso,
            'ronda' => $ronda,
            'colaborador_id' => $colaborador instanceof Colaborador ? $colaborador->id : ($contexto['colaborador_id'] ?? null),
            'candidato_id' => $candidato instanceof Candidato ? $candidato->id : ($contexto['candidato_id'] ?? null),
            'solicitante_user_id' => $solicitante instanceof User ? $solicitante->id : ($contexto['solicitante_user_id'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function crear(array $atributos): Aprobacion
    {
        try {
            return Aprobacion::query()->create($atributos);
        } catch (QueryException) {
            // Índice único etapa+ronda: otra petición concurrente ya abrió/decidió esta etapa.
            throw ValidationException::withMessages(['aprobacion' => 'Esta aprobación ya fue registrada por otra operación simultánea.']);
        }
    }

    private function decidir(Aprobacion $aprobacion, User $actor, EstadoAprobacion $estado, ?string $comentario, ?string $decision = null): void
    {
        $aprobacion->update([
            'estado' => $estado,
            'comentario' => $comentario,
            'decision' => $decision ?? $aprobacion->decision,
            'decidido_por_user_id' => $actor->id,
            'decidido_en' => now(),
            'decisor_snapshot' => $this->snapshot($actor),
            ...$this->contextoHttp(),
        ]);
    }

    private function pendienteBloqueada(Model $aprobable, ProcesoAprobacion $proceso, ?EtapaAprobacion $etapa = null): Aprobacion
    {
        $pendiente = $this->query($aprobable, $proceso)
            ->where('estado', EstadoAprobacion::Pendiente->value)
            ->when($etapa !== null, fn ($q) => $q->where('etapa', $etapa?->value))
            ->orderByDesc('ronda')
            ->lockForUpdate()
            ->first();

        if ($pendiente === null) {
            $mensaje = match ($etapa) {
                EtapaAprobacion::Preautorizacion => 'No hay una preautorización pendiente: ya fue decidida o el proceso cambió de estado.',
                EtapaAprobacion::AutorizacionRh => 'No hay una autorización de RH pendiente: falta la preautorización operativa o ya fue decidida.',
                default => 'No hay ninguna aprobación pendiente para este proceso.',
            };

            throw ValidationException::withMessages(['aprobacion' => $mensaje]);
        }

        return $pendiente;
    }

    private function exigirRh(User $actor, Aprobacion $pendiente): void
    {
        if (! $this->jerarquia->puedeAutorizarRh($actor, $pendiente)) {
            throw ValidationException::withMessages(['aprobacion' => 'Solo Recursos Humanos puede dar la autorización final.']);
        }

        $preautorizador = Aprobacion::query()
            ->where('aprobable_type', $pendiente->aprobable_type)
            ->where('aprobable_id', $pendiente->aprobable_id)
            ->where('proceso', $pendiente->proceso->value)
            ->where('etapa', EtapaAprobacion::Preautorizacion->value)
            ->where('ronda', $pendiente->ronda)
            ->where('estado', EstadoAprobacion::Aprobado->value)
            ->value('decidido_por_user_id');

        if ($preautorizador !== null && (int) $preautorizador === $actor->id) {
            throw ValidationException::withMessages(['aprobacion' => 'Quien preautorizó no puede dar también la autorización final de RH.']);
        }
    }

    private function exigirDecisor(User $actor, Aprobacion $pendiente): void
    {
        if ($pendiente->etapa === EtapaAprobacion::AutorizacionRh) {
            $this->exigirRh($actor, $pendiente);

            return;
        }

        if (! $this->jerarquia->puedePreautorizar($actor, $pendiente) && ! $this->jerarquia->puedeAutorizarRh($actor, $pendiente)) {
            throw ValidationException::withMessages(['aprobacion' => 'No te corresponde decidir esta aprobación.']);
        }
    }

    private function exigirMotivo(string $motivo): void
    {
        if (trim($motivo) === '') {
            throw ValidationException::withMessages(['motivo' => 'El motivo es obligatorio.']);
        }
    }

    private function rondaActual(Model $aprobable, ProcesoAprobacion $proceso): int
    {
        return (int) ($this->query($aprobable, $proceso)->max('ronda') ?? 0);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Aprobacion>
     */
    private function query(Model $aprobable, ProcesoAprobacion $proceso): \Illuminate\Database\Eloquent\Builder
    {
        return Aprobacion::query()
            ->where('aprobable_type', $aprobable->getMorphClass())
            ->where('aprobable_id', $aprobable->getKey())
            ->where('proceso', $proceso->value);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(User $usuario): array
    {
        $colaborador = $usuario->colaborador;
        $colaborador?->loadMissing('puesto:id,nombre');

        return [
            'user_id' => $usuario->id,
            'colaborador_id' => $colaborador?->id,
            'nombre' => $usuario->nombreCompleto(),
            'puesto' => $colaborador?->puesto?->nombre,
            'roles' => $usuario->getRoleNames()->values()->all(),
        ];
    }

    /**
     * @return array{ip?: string|null, user_agent?: string}
     */
    private function contextoHttp(): array
    {
        if ($this->request === null || $this->request->ip() === null) {
            return [];
        }

        return ['ip' => $this->request->ip(), 'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 500)];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function auditar(string $accion, Aprobacion $aprobacion, User $actor, array $extra = []): void
    {
        $this->auditoria->registrar($accion, $aprobacion, $actor, [
            'proceso' => $aprobacion->proceso->value,
            'etapa' => $aprobacion->etapa->value,
            'ronda' => $aprobacion->ronda,
            'colaborador_id' => $aprobacion->colaborador_id,
            'candidato_id' => $aprobacion->candidato_id,
            ...$extra,
        ]);
    }
}
