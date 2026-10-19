<?php

namespace App\Services\Tareas;

use App\Enums\CategoriaNotificacion;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Configuracion\WorkflowRoutingService;
use App\Services\Notificaciones\Aviso;
use App\Services\Notificaciones\NotificacionEventosService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notificaciones del ciclo laboral (base de datos + broadcast + push).
 * Ya no envía nada por su cuenta: delega en la capa única
 * App\Services\Notificaciones\NotificacionEventosService (idempotencia,
 * cola dedicada, push), conservando su API para los servicios que la usan.
 * Regla del proyecto: un fallo al notificar nunca revierte la acción
 * principal — se captura y se registra con Log::warning.
 */
class NotificadorRhService
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly WorkflowRoutingService $routing,
        private readonly NotificacionEventosService $eventos,
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
        // Eventos LEGACY (config configuracion_sistema.eventos.*.legacy, p. ej.
        // cierre_autorizacion_rh): se conservan para leer avisos anteriores,
        // pero nunca se vuelven a emitir.
        if ((bool) config(sprintf('configuracion_sistema.eventos.%s.legacy', $evento), false)) {
            Log::warning('NotificadorRhService: se intentó notificar un evento legacy; se ignora.', ['evento' => $evento]);

            return collect();
        }

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
     * Una acción que pide algo ("firmar", "revisar_expediente") es una TAREA
     * para quien la recibe; "ver_*" o sin acción es un RESULTADO informativo.
     *
     * @param  iterable<User|null>  $usuarios
     * @param  array<string, string>  $ruteo
     */
    public function notificar(iterable $usuarios, string $tipo, string $titulo, string $mensaje, ?Model $relacionado = null, ?string $accion = null, string $prioridad = 'media', array $ruteo = []): void
    {
        $aviso = new Aviso($tipo, $tipo, $titulo, $mensaje, $relacionado, $accion, $prioridad, $ruteo);
        $categoria = $accion !== null && ! str_starts_with($accion, 'ver')
            ? CategoriaNotificacion::Pendiente
            : CategoriaNotificacion::Resultado;

        foreach ($usuarios as $usuario) {
            if ($usuario instanceof User) {
                $this->eventos->entregar($usuario, $categoria, $aviso);
            }
        }
    }
}
