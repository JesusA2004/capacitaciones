<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo «Alta para asignar usuarios» a Sistemas. NO implementa ShouldQueue:
 * la cola es App\Jobs\EnviarAvisoAltaSistemasJob, que lo manda y registra
 * el resultado (enviado / fallido) en avisos_alta_sistemas. El remitente es
 * el global del .env (MAIL_FROM_ADDRESS / MAIL_FROM_NAME). Sin adjuntos.
 */
class AltaColaboradorSistemasMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, string>  $personales  etiqueta => valor ("Sin capturar" si falta)
     * @param  array<string, string>  $laborales  etiqueta => valor ("Sin capturar" si falta)
     */
    public function __construct(
        public readonly string $nombreCompleto,
        public readonly string $puesto,
        public readonly string $sucursal,
        public readonly string $origen,
        public readonly array $personales,
        public readonly array $laborales,
        public readonly string $urlExpediente,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Alta para asignar usuarios: %s — %s, %s', $this->nombreCompleto, $this->puesto, $this->sucursal),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.alta-colaborador-sistemas');
    }
}
