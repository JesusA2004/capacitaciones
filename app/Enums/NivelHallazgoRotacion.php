<?php

namespace App\Enums;

/**
 * Qué tan grave es algo que el lector del Excel de rotación encontró. Todo
 * hallazgo se muestra a RH antes de confirmar (nada se ignora en silencio):
 *  - error: el dato NO se importa (sucursal sin catálogo, valor no numérico…).
 *  - advertencia: se importa, pero algo no cuadra (índice o plantilla del
 *    Excel distintos del recalculado, coincidencia aproximada de sucursal…).
 *  - informativo: contexto (meses sin captura, resúmenes que se recalculan).
 */
enum NivelHallazgoRotacion: string
{
    case Error = 'error';
    case Advertencia = 'advertencia';
    case Informativo = 'informativo';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Error => 'No se importa',
            self::Advertencia => 'Revisar',
            self::Informativo => 'Informativo',
        };
    }
}
