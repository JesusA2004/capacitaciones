<?php

namespace App\Mail;

use App\Enums\EventoAvisoBajaSistemas;
use App\Services\Notificaciones\AvisoAltaSistemasService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo de BAJA a Sistemas (dar de baja accesos / baja concluida / baja
 * cancelada). NO implementa ShouldQueue: la cola es
 * App\Jobs\EnviarAvisoBajaSistemasJob, que registra el resultado en
 * avisos_baja_sistemas. Mismo layout que el de alta
 * (resources/views/mail/sistemas/*) + texto plano. Sin adjuntos, sin causal,
 * motivo ni montos, y sin el nombre de quien capturó la baja.
 */
class BajaColaboradorSistemasMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, array{titulo: string, datos: array<string, string>}>  $secciones  persona / puesto / baja / accesos
     * @param  list<string>  $plataformas  Lista de verificación (config notificaciones.sistemas.plataformas).
     */
    public function __construct(
        public readonly EventoAvisoBajaSistemas $evento,
        public readonly string $nombreCompleto,
        public readonly string $puesto,
        public readonly string $sucursal,
        public readonly array $secciones,
        public readonly array $plataformas,
        public readonly string $fechaBajaLarga,
        public readonly ?string $fechaFiniquitoLarga,
        public readonly string $urlDetalle,
        public readonly string $urlBandeja,
        public readonly string $logoUrl,
    ) {}

    public function envelope(): Envelope
    {
        $asunto = match ($this->evento) {
            EventoAvisoBajaSistemas::AccesosBloqueados => 'Baja en sistemas: dar de baja accesos',
            EventoAvisoBajaSistemas::BajaConcluida => 'Baja concluida — finiquito entregado',
            EventoAvisoBajaSistemas::BajaCancelada => 'Baja cancelada: reactivar accesos',
        };

        return new Envelope(
            subject: sprintf('%s — %s, %s, %s', $asunto, $this->nombreCompleto, $this->puesto, $this->sucursal),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.baja-colaborador-sistemas',
            text: 'mail.baja-colaborador-sistemas-texto',
            with: ['etiquetaEvento' => $this->etiquetaEvento(), 'instruccion' => $this->instruccion(), 'tituloLista' => $this->tituloLista()],
        );
    }

    /** Valor de un dato por su etiqueta (cualquier sección). */
    public function dato(string $etiqueta): ?string
    {
        return AvisoAltaSistemasService::aplanar($this->secciones)[$etiqueta] ?? null;
    }

    public function etiquetaEvento(): string
    {
        return mb_strtoupper(sprintf('Baja en sistemas · %s', $this->evento->etiqueta()));
    }

    public function instruccion(): string
    {
        return match ($this->evento) {
            EventoAvisoBajaSistemas::AccesosBloqueados => 'Se aplicó la baja de esta persona (su acceso a PEOPLE se indica abajo). Da de baja sus usuarios en los demás sistemas y márcalo como atendido en PEOPLE.',
            EventoAvisoBajaSistemas::BajaConcluida => sprintf(
                'RH registró la entrega del finiquito%s y la baja quedó concluida. Confirma que no le quede ningún usuario activo en los demás sistemas.',
                $this->fechaFiniquitoLarga !== null ? sprintf(' el %s', $this->fechaFiniquitoLarga) : '',
            ),
            EventoAvisoBajaSistemas::BajaCancelada => 'La baja de esta persona se canceló y sigue laborando. Si ya diste de baja sus usuarios en los demás sistemas, reactívalos.',
        };
    }

    public function tituloLista(): string
    {
        return $this->evento === EventoAvisoBajaSistemas::BajaConcluida ? 'Confirmar desactivado' : 'Por desactivar';
    }
}
