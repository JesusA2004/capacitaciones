<?php

namespace App\Mail\Sistemas;

/**
 * Colores y tipografía de los correos a Sistemas (alta y baja,
 * resources/views/mail/sistemas/*). Un correo no lee las variables CSS de
 * resources/css/app.css, así que la paleta de marca se repite aquí, una sola
 * vez, para que el layout y sus parciales la compartan.
 */
final class PaletaCorreoSistemas
{
    public const COLORES = [
        'fondo' => '#f4f1ea',
        'tarjeta' => '#ffffff',
        'borde' => '#e6dfd2',
        'linea' => '#efeae1',
        'petroleo' => '#315b59',
        'petroleoOscuro' => '#23413f',
        'oro' => '#c7a66b',
        'oroTexto' => '#8a6a36',
        'texto' => '#2b3432',
        'texto2' => '#5f6b68',
        'etiqueta' => '#7c8582',
    ];

    public const FUENTE = 'font-family:Arial,Helvetica,sans-serif;';
}
