<?php

namespace App\Mail;

use App\Services\Notificaciones\AvisoAltaSistemasService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo «Alta en sistemas» a Sistemas. NO implementa ShouldQueue: la cola
 * es App\Jobs\EnviarAvisoAltaSistemasJob, que lo manda y registra el
 * resultado (enviado / fallido) en avisos_alta_sistemas. El remitente es el
 * global del .env (MAIL_FROM_ADDRESS / MAIL_FROM_NAME). Sin adjuntos.
 *
 * HTML con tablas y estilos en línea (resources/views/mail/alta-colaborador-sistemas.blade.php)
 * + texto plano (alta-colaborador-sistemas-texto): Outlook y Gmail descartan
 * hojas de estilo, SVG y JavaScript. El botón «Abrir y copiar datos» lleva al
 * detalle autenticado del aviso, donde cada dato tiene su botón Copiar.
 */
class AltaColaboradorSistemasMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, array{titulo: string, datos: array<string, string>}>  $secciones  persona / contacto / puesto ("Sin capturar" si falta)
     */
    public function __construct(
        public readonly string $nombreCompleto,
        public readonly string $puesto,
        public readonly string $sucursal,
        public readonly string $tipoIngreso,
        public readonly array $secciones,
        public readonly string $urlExpediente,
        public readonly string $urlDetalle,
        public readonly ?string $urlBandeja,
        public readonly string $fechaIngresoLarga,
        public readonly string $fechaIngresoCorta,
        public readonly string $logoUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Alta en sistemas: %s — %s, %s', $this->nombreCompleto, $this->puesto, $this->sucursal),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.alta-colaborador-sistemas',
            text: 'mail.alta-colaborador-sistemas-texto',
            with: ['faltantes' => $this->faltantes(), 'lineasCopiar' => $this->lineasCopiar()],
        );
    }

    /** Valor de un dato por su etiqueta (cualquier tarjeta). */
    public function dato(string $etiqueta): ?string
    {
        return AvisoAltaSistemasService::aplanar($this->secciones)[$etiqueta] ?? null;
    }

    /** Cuántos datos aún no están capturados en PEOPLE. */
    public function faltantes(): int
    {
        return count(array_filter(
            AvisoAltaSistemasService::aplanar($this->secciones),
            fn (string $valor): bool => $valor === AvisoAltaSistemasService::SIN_CAPTURAR,
        ));
    }

    /**
     * Bloque «Para copiar y pegar»: un dato por renglón.
     *
     * @return list<string>
     */
    public function lineasCopiar(): array
    {
        $lineas = [];

        foreach (AvisoAltaSistemasService::aplanar($this->secciones) as $etiqueta => $valor) {
            $lineas[] = sprintf('%s: %s', $etiqueta, $valor);
        }

        return $lineas;
    }
}
