<?php

namespace App\Enums;

/**
 * Visibilidad de un RecursoMultimedia dentro de las pantallas
 * administrables. "Publica" es administrable desde la biblioteca general;
 * "restringida" solo es accesible desde el flujo de revisión que la generó
 * (entrega de actividad, respuesta de cuestionario tipo carga_archivo, etc.).
 */
enum VisibilidadRecursoMultimedia: string
{
    case Publica = 'publica';
    case Restringida = 'restringida';
}
