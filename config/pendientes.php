<?php

/*
|--------------------------------------------------------------------------
| Mis pendientes (docs/PENDIENTES.md)
|--------------------------------------------------------------------------
|
| - semaforo: días de antigüedad a partir de los cuales un pendiente abierto
|   pasa a ámbar o rojo (un vencido siempre es rojo; uno que vence en 3 días
|   o menos, al menos ámbar).
| - motivo_max: largo máximo del motivo opcional de «Quitar de mi lista».
| - reconciliar_cada_segundos: al abrir «Mis pendientes» se limpian los
|   pendientes ya cumplidos como máximo una vez por este intervalo (además
|   del scheduler `pendientes:reconciliar`).
*/

return [
    'semaforo' => [
        'ambar' => (int) env('PENDIENTES_SEMAFORO_AMBAR', 3),
        'rojo' => (int) env('PENDIENTES_SEMAFORO_ROJO', 7),
    ],
    'motivo_max' => 255,
    'reconciliar_cada_segundos' => 120,
];
