<?php

namespace App\Support;

use App\Enums\TipoTarea;

/**
 * Permisos del módulo «Pendientes» de Roles y permisos
 * (config/permisos_modulos.php → modulos.pendientes): un permiso
 * `pendientes.recibir_<tipo>` por cada tipo de pendiente vigente, para
 * decidir qué pendientes le llegan a cada rol. Ver docs/PENDIENTES.md.
 *
 * Clase pura (sin base de datos): la usa el archivo de configuración.
 */
final class CatalogoPermisosPendientes
{
    /**
     * @return array<string, string> permiso => etiqueta
     */
    public static function acciones(): array
    {
        $acciones = [];

        foreach (TipoTarea::configurables() as $tipo) {
            $acciones[$tipo->permisoRecibir()] = sprintf('Recibir: %s', $tipo->etiqueta());
        }

        return $acciones;
    }

    /**
     * @return list<string>
     */
    public static function permisos(): array
    {
        return array_keys(self::acciones());
    }
}
