<?php

namespace App\Services\Tareas;

use App\Models\Colaborador;
use App\Models\User;
use App\Notifications\Mobile\PendienteRhNotification;
use App\Services\AlcanceOrganizacionalService;
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
     * @param  iterable<User>  $usuarios
     */
    public function notificar(iterable $usuarios, string $tipo, string $titulo, string $mensaje, ?Model $relacionado = null, ?string $accion = null, string $prioridad = 'media'): void
    {
        try {
            $destinatarios = collect($usuarios)->filter()->unique('id')->values();

            if ($destinatarios->isEmpty()) {
                return;
            }

            $relatedId = $relacionado?->getKey();
            $relatedId = is_int($relatedId) ? $relatedId : null;

            Notification::send($destinatarios, new PendienteRhNotification(
                $tipo,
                $titulo,
                $mensaje,
                $relacionado !== null ? class_basename($relacionado) : null,
                $relatedId,
                $accion,
                $prioridad,
            ));

            foreach ($destinatarios as $usuario) {
                $this->push->aUsuarioConDatos($usuario, $titulo, $mensaje, [
                    'type' => $tipo,
                    'resource_id' => $relatedId,
                    'related_type' => $relacionado !== null ? class_basename($relacionado) : null,
                    'accion' => $accion,
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('NotificadorRhService: fallo al notificar.', ['tipo' => $tipo, 'error' => $e->getMessage()]);
        }
    }
}
