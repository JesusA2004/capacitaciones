<?php

namespace App\Services\Tareas;

use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\OnboardingAvance;
use App\Models\User;
use App\Notifications\Mobile\PendienteRhNotification;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Configuracion\WorkflowRoutingService;
use App\Services\MobilePush\PushNotifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Notificaciones del ciclo laboral (base de datos + broadcast + push).
 * Regla del proyecto: un fallo al notificar nunca revierte la acción
 * principal — se captura y se registra con Log::warning.
 */
class NotificadorRhService
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly PushNotifier $push,
        private readonly WorkflowRoutingService $routing,
    ) {}

    /**
     * Cuentas con el permiso indicado y alcance organizacional sobre el
     * colaborador (RH global, gerente de su sucursal, su jefe directo).
     *
     * @return Collection<int, User>
     */
    public function responsablesDe(Colaborador $colaborador, string $permiso): Collection
    {
        try {
            return User::query()
                ->permission($permiso)
                ->whereNull('acceso_bloqueado_en')
                ->when($colaborador->user !== null, fn ($q) => $q->where('id', '!=', $colaborador->user?->id))
                ->get()
                ->filter(fn (User $u) => $this->alcance->alcanzaColaborador($u, $colaborador))
                ->values();
        } catch (Throwable $e) {
            Log::warning('NotificadorRhService: no se pudieron resolver responsables.', ['permiso' => $permiso, 'error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * Notifica un EVENTO a los destinatarios que resuelve su regla de ruteo
     * (Administración → Configuración → Notificaciones). Cada notificación
     * guarda por qué le llegó a ese usuario (route_rule + recipient_reason).
     * Nunca lanza: un fallo se registra y la acción principal sigue.
     *
     * @param  array{solicitante?: User|null, creador?: User|null, evaluador?: User|Colaborador|null, aprobador?: User|null, excluir?: list<int>}  $contexto
     * @return Collection<int, User> a quiénes se avisó
     */
    public function notificarEvento(string $evento, Colaborador|Candidato|null $sujeto, array $contexto, string $titulo, string $mensaje, ?Model $relacionado = null, ?string $accion = null, string $prioridad = 'media'): Collection
    {
        try {
            $destinatarios = $this->routing->resolver($evento, $sujeto, $contexto);
        } catch (Throwable $e) {
            Log::warning('NotificadorRhService: no se pudo resolver el ruteo.', ['evento' => $evento, 'error' => $e->getMessage()]);

            return collect();
        }

        foreach ($destinatarios as $destino) {
            $this->notificar([$destino['usuario']], $evento, $titulo, $mensaje, $relacionado, $accion, $prioridad, [
                'route_rule' => $evento,
                'recipient_reason' => implode('; ', array_unique($destino['motivos'])),
            ]);
        }

        return $destinatarios->pluck('usuario')->values();
    }

    /**
     * @param  iterable<User>  $usuarios
     * @param  array<string, string>  $ruteo
     */
    public function notificar(iterable $usuarios, string $tipo, string $titulo, string $mensaje, ?Model $relacionado = null, ?string $accion = null, string $prioridad = 'media', array $ruteo = []): void
    {
        $destinatarios = collect($usuarios)->filter()->unique('id')->values();

        if ($destinatarios->isEmpty()) {
            return;
        }

        $relatedId = $relacionado?->getKey();
        $relatedId = is_int($relatedId) ? $relatedId : null;

        try {
            Notification::send($destinatarios, new PendienteRhNotification(
                $tipo,
                $titulo,
                $mensaje,
                $relacionado !== null ? class_basename($relacionado) : null,
                $relatedId,
                $accion,
                $prioridad,
                $ruteo,
                $this->colaboradorIdDe($relacionado),
            ));
        } catch (Throwable $e) {
            Log::warning('NotificadorRhService: fallo al notificar.', ['tipo' => $tipo, 'error' => $e->getMessage()]);

            return;
        }

        // Push por usuario: si falla uno, los demás igual reciben el suyo.
        // El payload lleva lo necesario para abrir el recurso exacto en la app.
        foreach ($destinatarios as $usuario) {
            try {
                $this->push->aUsuarioConDatos($usuario, $titulo, $mensaje, [
                    'type' => $tipo,
                    'resource_id' => $relatedId,
                    'related_type' => $relacionado !== null ? class_basename($relacionado) : null,
                    'accion' => $accion,
                    'colaborador_id' => $this->colaboradorIdDe($relacionado),
                ]);
            } catch (Throwable $e) {
                Log::warning('NotificadorRhService: fallo el push.', ['tipo' => $tipo, 'user_id' => $usuario->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Persona a la que pertenece el recurso del aviso (directo, o vía su
     * proceso de onboarding), para que web/app abran su ficha.
     */
    private function colaboradorIdDe(?Model $relacionado): ?int
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

        return null;
    }
}
