<?php

namespace App\Services\Documentos\Versiones;

use Illuminate\Support\Facades\View;
use Throwable;

/**
 * Formatos propios de PEOPLE (vistas Blade: acta, responsiva). Su contenido entra
 * en la huella del documento: si el formato cambia, «Regenerar» emite otra versión
 * aunque los datos sean los mismos (reimprimir con el formato vigente).
 */
final class HuellaFormato
{
    public static function deVista(string $vista): ?string
    {
        try {
            $ruta = View::getFinder()->find($vista);
        } catch (Throwable) {
            return null;
        }

        $huella = is_file($ruta) ? hash_file('sha256', $ruta) : false;

        return $huella !== false ? $huella : null;
    }
}
