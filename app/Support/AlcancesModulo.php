<?php

namespace App\Support;

use App\Enums\AlcanceDatos;

/**
 * Permisos de alcance de un módulo de config/permisos_modulos.php, con los
 * que se derivan solos.
 *
 * «Sucursal y corporativo» (AlcanceDatos::SucursalCorporativo): todo módulo
 * que tiene alcance «sucursal» lo ofrece también, con el permiso
 * `<permiso de sucursal>_corporativo` (p. ej. `directorio.ver_sucursal` →
 * `directorio.ver_sucursal_corporativo`). Un módulo puede declararlo a mano
 * en su `alcances` con otro nombre; entonces manda el declarado.
 *
 * Única fuente para la pantalla de roles (AlcanceModuloService::modulos)
 * y para el catálogo que crea `people:sincronizar-permisos`
 * (RolesYPermisosSeeder::permisosDeModulos): no hay que repetir el permiso
 * en cada módulo. Ningún rol lo recibe por defecto (alcance_por_rol no lo usa).
 */
final class AlcancesModulo
{
    public const SUFIJO_CORPORATIVO = '_corporativo';

    /**
     * @param  array<mixed>  $definicion  Un módulo de permisos_modulos.modulos.
     * @return array<string, string> nivel => permiso
     */
    public static function de(array $definicion): array
    {
        $alcances = [];

        foreach ((array) ($definicion['alcances'] ?? []) as $nivel => $permiso) {
            if (is_string($nivel) && is_string($permiso) && $permiso !== '') {
                $alcances[$nivel] = $permiso;
            }
        }

        $sucursal = $alcances[AlcanceDatos::Sucursal->value] ?? null;

        if ($sucursal !== null && ! isset($alcances[AlcanceDatos::SucursalCorporativo->value])) {
            $alcances[AlcanceDatos::SucursalCorporativo->value] = sprintf('%s%s', $sucursal, self::SUFIJO_CORPORATIVO);
        }

        return $alcances;
    }
}
