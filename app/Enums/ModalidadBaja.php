<?php

namespace App\Enums;

/**
 * Cuándo surte efecto una baja (docs/BAJAS_Y_CAUSALES.md, «Bajas inmediatas
 * y bajas programadas»):
 *
 *  - Inmediata: al registrarla se suspenden sus accesos a PEOPLE (web y app)
 *    y, si el último día ya llegó, queda inactiva.
 *  - Programada: se registra con anticipación con una fecha futura. Hasta ese
 *    día la persona conserva su acceso; RH ya puede preparar finiquito y
 *    documentos de salida. Ese día `cierres:revisar-fechas` suspende sus
 *    accesos y la concluye igual que una inmediata.
 */
enum ModalidadBaja: string
{
    case Inmediata = 'inmediata';
    case Programada = 'programada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Inmediata => 'Baja inmediata',
            self::Programada => 'Baja programada',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::Inmediata => 'Sus accesos a PEOPLE se suspenden al registrarla.',
            self::Programada => 'Conserva su acceso hasta la fecha elegida; ese día se suspende y la baja se concluye.',
        };
    }
}
