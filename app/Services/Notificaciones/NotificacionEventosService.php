<?php

namespace App\Services\Notificaciones;

use App\Enums\CategoriaNotificacion;
use App\Models\Colaborador;
use App\Models\NotificacionEntrega;
use App\Models\OnboardingAvance;
use App\Models\SolicitudExcepcionPeriodoPrueba;
use App\Models\User;
use App\Notifications\Mobile\PendienteRhNotification;
use App\Services\MobilePush\PushNotifier;
use App\Support\FechaMexico;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Capa ÚNICA de avisos de negocio (docs/NOTIFICACIONES_EVENTOS.md). Todo
 * evento que deja algo pendiente para alguien pasa por aquí, y cada entrega
 * produce, desde el backend y en un solo flujo:
 *
 *  A) la notificación en base de datos (campana web y bandeja de la app);
 *  B) su broadcast por Reverb (tiempo real web) — mismo job encolado;
 *  C) el push de Expo a cada dispositivo activo del destinatario.
 *
 * Todo sale por la cola dedicada `notifications` y DESPUÉS del commit
 * (queue.connections.*.after_commit), así que el worker nunca avisa de algo
 * que todavía no existe.
 *
 * Conceptos que NO se mezclan (CategoriaNotificacion):
 *  - confirmarAlActor(): a quien hizo la acción ("Solicitud enviada"),
 *    aunque sea el mismo usuario que la originó;
 *  - crearPendiente()/avisarSiguienteResponsable(): a quien debe resolver;
 *  - avisarResultado(): al interesado cuando algo se resolvió.
 * Si el actor también es responsable, registrarConPendiente() le manda UN
 * aviso combinado, nunca dos iguales ni cero.
 *
 * Idempotencia: cada entrega reclama una clave única (destinatario +
 * categoría + evento + recurso + acción + ocurrencia) en
 * `notificacion_entregas`; la misma transición pedida dos veces (doble
 * llamada, reconciliador) produce UNA notificación. Los reintentos del
 * worker no vuelven a pasar por aquí: reintentan el job ya encolado.
 *
 * Un fallo al notificar jamás revierte la acción principal: se registra en
 * el log y la red de seguridad (`notificaciones:reconciliar`) lo repone.
 */
class NotificacionEventosService
{
    public function __construct(
        private readonly PushNotifier $push,
        private readonly SucursalesSilenciadasService $silenciadas,
        private readonly PoliticaNotificacionRolService $politica,
    ) {}

    public function confirmarAlActor(User $actor, Aviso $aviso): bool
    {
        return $this->entregar($actor, CategoriaNotificacion::Confirmacion, $aviso);
    }

    /**
     * @param  iterable<User|null>  $destinatarios
     * @return Collection<int, User> a quiénes se les entregó en esta llamada
     */
    public function crearPendiente(iterable $destinatarios, Aviso $aviso, string $origen = 'directo'): Collection
    {
        $entregados = $this->unicos($destinatarios)
            ->filter(fn (User $usuario): bool => $this->entregar($usuario, CategoriaNotificacion::Pendiente, $aviso, $origen))
            ->values();

        $this->ampliar($aviso, CategoriaNotificacion::Pendiente);

        return $entregados;
    }

    /**
     * Roles que Roles y permisos → Notificaciones agregó a este aviso: reciben
     * sólo sobre personas dentro de su alcance. La idempotencia evita duplicar a
     * quien ya le llegó por su regla. Nunca lanza.
     *
     * @param  list<int>  $excluir
     */
    public function ampliar(Aviso $aviso, CategoriaNotificacion $categoria, array $excluir = []): void
    {
        try {
            foreach ($this->politica->usuariosAgregados($aviso, $this->colaboradorIdDe($aviso->recurso), $excluir) as $usuario) {
                $this->entregar($usuario, $categoria, $aviso, 'checklist');
            }
        } catch (Throwable $e) {
            Log::warning('NotificacionEventosService: no se pudo ampliar el aviso.', ['evento' => $aviso->evento, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Tras resolver un paso, avisa a quien sigue en la cadena (gerente →
     * regional → RH). Mismo comportamiento que crearPendiente().
     *
     * @param  iterable<User|null>  $destinatarios
     * @return Collection<int, User>
     */
    public function avisarSiguienteResponsable(iterable $destinatarios, Aviso $aviso): Collection
    {
        return $this->crearPendiente($destinatarios, $aviso);
    }

    public function avisarResultado(?User $interesado, Aviso $aviso): bool
    {
        return $interesado !== null && $this->entregar($interesado, CategoriaNotificacion::Resultado, $aviso);
    }

    /**
     * El recurso ya no necesita a nadie (p. ej. el solicitante canceló su
     * solicitud): avisa a quienes esta capa les había dejado una TAREA sobre
     * él, para que no la busquen ni la sigan viendo como pendiente. Solo a
     * ellos: nunca amplía destinatarios. Nunca lanza.
     *
     * @param  list<int>  $excluir  Cuentas que no se avisan (quien hizo la acción).
     * @return Collection<int, User> a quiénes se les entregó
     */
    public function avisarPendienteRetirado(Aviso $aviso, array $excluir = []): Collection
    {
        if ($aviso->recurso === null) {
            return collect();
        }

        try {
            $ids = NotificacionEntrega::query()
                ->where('categoria', CategoriaNotificacion::Pendiente->value)
                ->where('related_type', class_basename($aviso->recurso))
                ->where('related_id', $aviso->recurso->getKey())
                ->whereNotIn('user_id', $excluir)
                ->distinct()
                ->pluck('user_id');

            return User::query()
                ->whereIn('id', $ids)
                ->get()
                ->filter(fn (User $usuario): bool => $this->entregar($usuario, CategoriaNotificacion::Resultado, $aviso))
                ->values();
        } catch (Throwable $e) {
            Log::warning('NotificacionEventosService: no se pudo avisar el retiro del pendiente.', ['evento' => $aviso->evento, 'error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * Confirmación para quien hizo la acción + tarea para quien la resuelve,
     * como un solo flujo. Si el actor está entre los responsables recibe UN
     * aviso combinado ("… registrada y pendiente de tu resolución").
     *
     * @param  iterable<User|null>  $responsables
     */
    public function registrarConPendiente(User $actor, Aviso $confirmacion, iterable $responsables, Aviso $pendiente): void
    {
        $responsables = $this->unicos($responsables);
        $actorResuelve = $responsables->contains(fn (User $usuario): bool => $usuario->id === $actor->id);

        if ($actorResuelve) {
            $combinado = $pendiente->conTextos(
                sprintf('%s y pendiente de tu resolución', rtrim($confirmacion->titulo, '.')),
                sprintf('%s Además te toca resolverla.', rtrim($confirmacion->mensaje)),
            );

            $this->entregar($actor, CategoriaNotificacion::Pendiente, $combinado, 'directo', [
                $this->clave($actor, CategoriaNotificacion::Confirmacion, $confirmacion),
            ]);
        } else {
            $this->confirmarAlActor($actor, $confirmacion);
        }

        $this->crearPendiente($responsables->reject(fn (User $usuario): bool => $usuario->id === $actor->id), $pendiente);
    }

    /**
     * true si el usuario ya recibió cualquier aviso sobre ese recurso desde
     * $desde (entregas de esta capa o, para avisos anteriores a ella, la
     * propia tabla `notifications`). Lo usa el reconciliador.
     */
    public function yaAvisado(User $usuario, Model $recurso, CarbonInterface $desde): bool
    {
        $tipo = class_basename($recurso);
        $id = $recurso->getKey();

        if (NotificacionEntrega::query()
            ->where('user_id', $usuario->id)
            ->where('related_type', $tipo)
            ->where('related_id', $id)
            ->where('created_at', '>=', $desde)
            ->exists()) {
            return true;
        }

        return DB::table('notifications')
            ->where('notifiable_type', $usuario->getMorphClass())
            ->where('notifiable_id', $usuario->id)
            ->where('created_at', '>=', $desde)
            ->where(fn ($q) => $q
                ->where(fn ($r) => $r->where('data->related_type', $tipo)->where('data->related_id', $id))
                ->orWhere(fn ($r) => $r->whereIn('data->type', self::tiposLegados($tipo))->where('data->resource_id', $id)))
            ->exists();
    }

    /**
     * `data.type` históricos que apuntan a un recurso sin related_type.
     *
     * @return list<string>
     */
    private static function tiposLegados(string $tipoRecurso): array
    {
        return match ($tipoRecurso) {
            'SolicitudInterna' => ['rh_solicitud', 'solicitud', 'visto_bueno_pendiente'],
            'SolicitudVacaciones' => ['rh_vacaciones', 'vacaciones'],
            'EmployeeDocument' => ['rh_documento', 'documento'],
            'Colaborador' => ['rh_incorporacion', 'incorporacion'],
            default => ['-'],
        };
    }

    /**
     * Persona a la que pertenece el recurso del aviso (directo, o vía su
     * proceso de onboarding/evaluación), para que web/app abran su ficha.
     */
    public function colaboradorIdDe(?Model $relacionado): ?int
    {
        if ($relacionado === null) {
            return null;
        }

        if ($relacionado instanceof Colaborador) {
            return $relacionado->id;
        }

        $directo = $relacionado->getAttribute('colaborador_id');

        if (is_int($directo)) {
            return $directo;
        }

        if ($relacionado instanceof OnboardingAvance) {
            return $relacionado->proceso()->value('colaborador_id');
        }

        if ($relacionado instanceof SolicitudExcepcionPeriodoPrueba) {
            return $relacionado->evaluacion()->value('colaborador_id');
        }

        return null;
    }

    /**
     * @param  list<string>  $clavesAdicionales  Claves que esta misma entrega deja cubiertas (aviso combinado).
     */
    public function entregar(User $usuario, CategoriaNotificacion $categoria, Aviso $aviso, string $origen = 'directo', array $clavesAdicionales = []): bool
    {
        // Una cuenta suspendida (baja en trámite) no recibe tareas nuevas.
        if ($categoria === CategoriaNotificacion::Pendiente && $usuario->acceso_bloqueado_en !== null) {
            return false;
        }

        // «Avisos por sucursal»: la persona eligió no recibir avisos de la
        // sucursal de este recurso (nunca su propia confirmación, ni lo suyo
        // ni lo de su equipo directo). Lo pendiente sigue en su bandeja.
        if ($categoria !== CategoriaNotificacion::Confirmacion && $this->silenciadas->silencia($usuario, $aviso->recurso, fn (): ?int => $this->colaboradorIdDe($aviso->recurso))) {
            return false;
        }

        // Checklist por rol (Roles y permisos → Notificaciones): mismo punto para
        // campana, tiempo real, app y navegador. La tarea sigue en «Mis pendientes».
        if (! $this->politica->permite($usuario, $aviso, $categoria, $this->colaboradorIdDe($aviso->recurso))) {
            return false;
        }

        $relatedType = $aviso->recurso !== null ? class_basename($aviso->recurso) : null;
        $relatedId = $aviso->recurso?->getKey();
        $relatedId = is_int($relatedId) ? $relatedId : null;
        $notificationId = (string) Str::uuid();

        try {
            $entrega = NotificacionEntrega::query()->create([
                'clave' => $this->clave($usuario, $categoria, $aviso),
                'user_id' => $usuario->id,
                'categoria' => $categoria,
                'evento' => Str::limit($aviso->evento, 80, ''),
                'related_type' => $relatedType,
                'related_id' => $relatedId,
                'accion' => $aviso->accion !== null ? Str::limit($aviso->accion, 60, '') : null,
                'notification_id' => $notificationId,
                'origen' => $origen,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        } catch (Throwable $e) {
            Log::warning('NotificacionEventosService: no se pudo registrar la entrega.', ['evento' => $aviso->evento, 'user_id' => $usuario->id, 'error' => $e->getMessage()]);

            return false;
        }

        foreach ($clavesAdicionales as $clave) {
            try {
                NotificacionEntrega::query()->create([
                    ...$entrega->only(['user_id', 'evento', 'related_type', 'related_id', 'notification_id', 'origen']),
                    'clave' => $clave,
                    'categoria' => CategoriaNotificacion::Confirmacion,
                    'accion' => 'combinada',
                ]);
            } catch (Throwable) {
                // Ya cubierta por una entrega anterior: nada que hacer.
            }
        }

        try {
            $notificacion = $this->notificacion($aviso, $categoria, $aviso->payloadSinRecurso ? null : $relatedType, $aviso->payloadSinRecurso ? null : $relatedId, $notificationId);
            $usuario->notify($notificacion);
        } catch (Throwable $e) {
            Log::warning('NotificacionEventosService: fallo al notificar.', ['evento' => $aviso->evento, 'user_id' => $usuario->id, 'error' => $e->getMessage()]);

            return false;
        }

        try {
            [$tituloPush, $mensajePush] = $this->politica->textoPush($aviso, $categoria);
            $dispositivos = $this->push->aUsuarioConDatos($usuario, $tituloPush, $mensajePush, [
                ...$aviso->datos,
                'type' => $aviso->tipo,
                'resource_id' => $aviso->payloadSinRecurso ? null : $relatedId,
                'related_type' => $aviso->payloadSinRecurso ? null : $relatedType,
                'accion' => $aviso->accion,
                'categoria' => $categoria->value,
                'colaborador_id' => $this->colaboradorIdDe($aviso->recurso),
                'notification_id' => $notificationId,
            ]);

            if ($dispositivos > 0) {
                $entrega->update(['push_dispositivos' => $dispositivos]);
            }
        } catch (Throwable $e) {
            Log::warning('NotificacionEventosService: fallo el push.', ['evento' => $aviso->evento, 'user_id' => $usuario->id, 'error' => $e->getMessage()]);
        }

        return true;
    }

    private function notificacion(Aviso $aviso, CategoriaNotificacion $categoria, ?string $relatedType, ?int $relatedId, string $notificationId): Notification
    {
        $notificacion = $aviso->notificacion !== null
            ? clone $aviso->notificacion
            : new PendienteRhNotification(
                $aviso->tipo,
                $aviso->titulo,
                $aviso->mensaje,
                $relatedType,
                $relatedId,
                $aviso->accion,
                $aviso->prioridad,
                [...$aviso->datos, 'categoria' => $categoria->value, 'evento' => $aviso->evento],
                $this->colaboradorIdDe($aviso->recurso),
            );

        $notificacion->id = $notificationId;

        return $notificacion;
    }

    public function clave(User $usuario, CategoriaNotificacion $categoria, Aviso $aviso): string
    {
        $recurso = $aviso->recurso !== null ? sprintf('%s#%s', class_basename($aviso->recurso), (string) $aviso->recurso->getKey()) : '-';

        return sha1(sprintf(
            'u%d|%s|%s|%s|%s|%s',
            $usuario->id,
            $categoria->value,
            $aviso->evento,
            $recurso,
            $aviso->accion ?? '-',
            $aviso->ocurrencia ?? $this->ocurrenciaPorDefecto($aviso),
        ));
    }

    /**
     * Misma transición = mismo texto sobre el recurso en el mismo estado
     * (updated_at) el mismo día. Una transición nueva cambia el recurso o
     * el texto, así que vuelve a avisar.
     */
    private function ocurrenciaPorDefecto(Aviso $aviso): string
    {
        $estado = $aviso->recurso?->getAttribute('updated_at');
        $estado = $estado instanceof DateTimeInterface ? $estado->format('U') : '-';

        return substr(sha1(sprintf('%s|%s|%s|%s', $aviso->titulo, $aviso->mensaje, $estado, FechaMexico::hoy())), 0, 16);
    }

    /**
     * @param  iterable<User|null>  $usuarios
     * @return Collection<int, User>
     */
    private function unicos(iterable $usuarios): Collection
    {
        $unicos = [];

        foreach ($usuarios as $usuario) {
            if ($usuario instanceof User) {
                $unicos[$usuario->id] = $usuario;
            }
        }

        return collect(array_values($unicos));
    }
}
