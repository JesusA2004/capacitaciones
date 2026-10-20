<?php

namespace App\Models\Concerns;

use App\Services\Colaboradores\DomicilioService;

/**
 * Modelos con domicilio estructurado (Colaborador, AltaDigital): al guardar,
 * normaliza los campos y recompone el texto `domicilio` a partir de ellos
 * (ver DomicilioService::sincronizar). Así TODO escritor (formularios, API,
 * OCR, solicitud aprobada, modal de documentos) deja ambos sincronizados.
 */
trait SincronizaDomicilio
{
    public static function bootSincronizaDomicilio(): void
    {
        static::saving(function (self $modelo): void {
            app(DomicilioService::class)->sincronizar($modelo);
        });
    }
}
