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
 * - directo: el emisor nombró a esta persona (aprobador, evaluador, jefe): el
 *   checklist por rol no la filtra.
 * - pushTitulo: título para la pantalla bloqueada; sin él, el del catálogo. El
 *   push nunca lleva nombre completo, causal, calificación, texto libre ni montos.
 * - payloadSinRecurso: la app no debe abrir el recurso (no es una pantalla de esta
 *   persona); la entrega lo conserva para su idempotencia y la web por `tipo`.
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
        public readonly bool $directo = false,
        public readonly ?string $pushTitulo = null,
        public readonly bool $payloadSinRecurso = false,
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
            $this->directo, $this->pushTitulo, $this->payloadSinRecurso,
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
            $this->directo, $this->pushTitulo, $this->payloadSinRecurso,
        );
    }
}
