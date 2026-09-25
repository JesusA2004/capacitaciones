<?php

/*
|--------------------------------------------------------------------------
| Celebraciones: cumpleaños y aniversarios laborales
|--------------------------------------------------------------------------
|
| Ver docs/CELEBRACIONES.md. Cumpleaños conserva su propia configuración
| (config/cumpleanos.php); aquí va lo común y lo del aniversario.
|
*/

return [

    'aniversario' => [
        'enabled' => (bool) env('ANIVERSARIOS_ENABLED', true),

        // Texto base de la tarjeta; {anios} = "6 años", {nombre}, {sucursal}.
        // RH lo puede cambiar desde Configuración (celebracion_configuraciones).
        'mensaje' => 'MR. LANA quiere darte el más sincero agradecimiento por tu entrega y constancia durante estos {anios} de trabajo. Esperamos que sigas contribuyendo con entusiasmo a los objetivos comunes de esta, tu empresa, por muchos años más.',
    ],

    // Días que una celebración sigue abierta a recibir mensajes y visible en
    // "celebraciones activas" después del día del evento (0 = solo el día).
    'dias_visible' => (int) env('CELEBRACIONES_DIAS_VISIBLE', 3),

    // Al homenajeado: como máximo un push de "nuevas felicitaciones" cada N
    // minutos (el resto se acumula en su pantalla del evento).
    'minutos_entre_avisos_mensajes' => (int) env('CELEBRACIONES_MINUTOS_AVISOS', 60),

    'card_width' => 1080,
    'card_height' => 1350,
];
