<?php

namespace App\Services\Notificaciones;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;

/**
 * Lo que se le quiere decir a alguien (ver NotificacionEventosService).
 *
 * - evento: qué pasó en el negocio ("solicitud_creada"); parte de la clave
 *   idempotente.
 * - tipo: `data.type` que web/app usan para navegar ("rh_solicitud",
 *   "solicitud"...). Se conserva el catálogo existente.
 * - recurso: el registro exacto (related_type/related_id del payload).
 * - accion: qué se espera de quien lo recibe ("revisar_solicitud") o, en una
 *   confirmación/resultado, la transición ("registrada", "aprobada").
 * - ocurrencia: distingue dos transiciones legítimas iguales sobre el mismo
 *   recurso (p. ej. el mismo pendiente reabierto). Si es null se deriva del
 *   texto + updated_at del recurso + el día.
 * - notificacion: clase propia cuando el payload histórico debe conservarse;
 *   por defecto PendienteRhNotification.
 */
final class Aviso
{
    /**
     * @param  array<string, scalar|null>  $datos  Extras del payload (nunca PII sensible).
     */
    public function __construct(
        public readonly string $evento,
        public readonly string $tipo,
        public readonly string $titulo,
        public readonly string $mensaje,
        public readonly ?Model $recurso = null,
        public readonly ?string $accion = null,
        public readonly string $prioridad = 'media',
        public readonly array $datos = [],
        public readonly ?string $ocurrencia = null,
        public readonly ?Notification $notificacion = null,
    ) {}

    /**
     * Copia con otros textos (p. ej. el aviso combinado cuando quien hizo la
     * acción también es quien la resuelve). La clase de notificación propia
     * se descarta: sus textos vienen fijos en la clase.
     */
    public function conTextos(string $titulo, string $mensaje): self
    {
        return new self(
            $this->evento, $this->tipo, $titulo, $mensaje, $this->recurso,
            $this->accion, $this->prioridad, $this->datos, $this->ocurrencia, null,
        );
    }

    /**
     * Copia con otra ocurrencia (el reconciliador ancla el aviso al pendiente
     * concreto que lo originó).
     */
    public function conOcurrencia(string $ocurrencia): self
    {
        return new self(
            $this->evento, $this->tipo, $this->titulo, $this->mensaje, $this->recurso,
            $this->accion, $this->prioridad, $this->datos, $ocurrencia, $this->notificacion,
        );
    }
}
