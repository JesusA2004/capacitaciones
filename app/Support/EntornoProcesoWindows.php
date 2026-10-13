<?php

namespace App\Support;

/**
 * Variables de entorno mínimas de Windows para los procesos hijos
 * (PowerShell, Word, reg.exe, rasterizador de PDF). PHP dentro de Apache
 * (servicio de WAMP como LocalSystem) o de `php artisan serve` no hereda
 * `SystemRoot` ni el resto del entorno de usuario: PowerShell no arranca
 * («No se pudo cargar Windows PowerShell administrado. Error: 8009001d»).
 * Solo se completan las que falten; las existentes no se tocan. Fuera de
 * Windows devuelve [] (Linux hereda su entorno normal).
 */
class EntornoProcesoWindows
{
    /**
     * @return array<string, string>
     */
    public static function variables(): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return [];
        }

        $raiz = (string) (getenv('SystemRoot') ?: getenv('windir') ?: 'C:\\Windows');
        $temporal = rtrim(sys_get_temp_dir(), '\\/');
        $perfil = (string) (getenv('USERPROFILE') ?: $raiz.'\\System32\\config\\systemprofile');
        $base = [
            'SystemRoot' => $raiz,
            'windir' => $raiz,
            'SystemDrive' => substr($raiz, 0, 2),
            'ComSpec' => $raiz.'\\System32\\cmd.exe',
            'PATHEXT' => '.COM;.EXE;.BAT;.CMD;.VBS;.VBE;.JS;.JSE;.WSF;.WSH;.MSC',
            'TEMP' => $temporal,
            'TMP' => $temporal,
            'USERPROFILE' => $perfil,
            'APPDATA' => $perfil.'\\AppData\\Roaming',
            'LOCALAPPDATA' => $perfil.'\\AppData\\Local',
            'ProgramData' => substr($raiz, 0, 2).'\\ProgramData',
            'ProgramFiles' => substr($raiz, 0, 2).'\\Program Files',
            'ProgramFiles(x86)' => substr($raiz, 0, 2).'\\Program Files (x86)',
            'PSModulePath' => $raiz.'\\System32\\WindowsPowerShell\\v1.0\\Modules',
        ];
        $entorno = [];

        foreach ($base as $clave => $valor) {
            $actual = getenv($clave);
            $entorno[$clave] = is_string($actual) && $actual !== '' ? $actual : $valor;
        }

        // PowerShell y reg.exe deben encontrarse aunque el PATH de Apache venga recortado.
        $ruta = (string) (getenv('PATH') ?: getenv('Path') ?: '');
        $sistema = implode(';', [$raiz.'\\System32', $raiz, $raiz.'\\System32\\Wbem', $raiz.'\\System32\\WindowsPowerShell\\v1.0']);
        $entorno['PATH'] = $ruta === '' ? $sistema : $ruta.';'.$sistema;

        return $entorno;
    }
}
