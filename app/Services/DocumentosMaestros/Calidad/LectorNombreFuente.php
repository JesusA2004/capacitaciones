<?php

namespace App\Services\DocumentosMaestros\Calidad;

/**
 * Lee el nombre de familia (tabla OpenType `name`, nameID 1 y 16) de un
 * archivo TTF/OTF/TTC. Solo lectura de cabeceras; no carga la fuente.
 * Lo usa DiagnosticoFuentesService para saber qué familias tiene Windows
 * instaladas (los nombres de archivo — GOTHIC.TTF — no dicen la familia).
 */
class LectorNombreFuente
{
    /**
     * @return list<string>
     */
    public static function familias(string $archivo): array
    {
        $manejador = @fopen($archivo, 'rb');

        if ($manejador === false) {
            return [];
        }

        try {
            $cabecera = (string) fread($manejador, 12);

            if (strlen($cabecera) < 12) {
                return [];
            }

            // Colección TrueType: varias fuentes en un archivo.
            if (substr($cabecera, 0, 4) === 'ttcf') {
                $numero = min(self::u32(substr($cabecera, 8, 4)), 64);

                if ($numero < 1) {
                    return [];
                }

                $desplazamientos = [];
                $datos = (string) fread($manejador, 4 * $numero);

                for ($i = 0; $i < $numero; $i++) {
                    $desplazamientos[] = self::u32(substr($datos, $i * 4, 4));
                }

                $familias = [];

                foreach ($desplazamientos as $desplazamiento) {
                    foreach (self::deFuente($manejador, $desplazamiento) as $familia) {
                        $familias[$familia] = true;
                    }
                }

                return array_keys($familias);
            }

            return self::deFuente($manejador, 0);
        } finally {
            fclose($manejador);
        }
    }

    /**
     * @param  resource  $manejador
     * @return list<string>
     */
    private static function deFuente($manejador, int $inicio): array
    {
        fseek($manejador, $inicio);
        $cabecera = (string) fread($manejador, 12);

        if (strlen($cabecera) < 12) {
            return [];
        }

        $tablas = min(self::u16(substr($cabecera, 4, 2)), 128);

        if ($tablas < 1) {
            return [];
        }

        $directorio = (string) fread($manejador, 16 * $tablas);
        $tablaName = null;

        for ($i = 0; $i < $tablas; $i++) {
            $registro = substr($directorio, $i * 16, 16);

            if (substr($registro, 0, 4) === 'name') {
                $tablaName = self::u32(substr($registro, 8, 4));

                break;
            }
        }

        if ($tablaName === null) {
            return [];
        }

        fseek($manejador, $tablaName);
        $nombre = (string) fread($manejador, 6);

        if (strlen($nombre) < 6) {
            return [];
        }

        $cuenta = min(self::u16(substr($nombre, 2, 2)), 512);

        if ($cuenta < 1) {
            return [];
        }

        $almacen = $tablaName + self::u16(substr($nombre, 4, 2));
        $registros = (string) fread($manejador, 12 * $cuenta);
        $familias = [];

        for ($i = 0; $i < $cuenta; $i++) {
            $r = substr($registros, $i * 12, 12);

            if (strlen($r) < 12) {
                break;
            }

            $plataforma = self::u16(substr($r, 0, 2));
            $idioma = self::u16(substr($r, 4, 2));
            $id = self::u16(substr($r, 6, 2));
            $largo = self::u16(substr($r, 8, 2));
            $desplazamiento = self::u16(substr($r, 10, 2));

            // Familia (1) o familia tipográfica (16); Windows Unicode o Mac Roman.
            if (! in_array($id, [1, 16], true) || $largo < 1 || ! in_array($plataforma, [1, 3], true)) {
                continue;
            }

            if ($plataforma === 3 && ! in_array($idioma, [0x0409, 0x080A, 0x0C0A], true)) {
                continue;
            }

            fseek($manejador, $almacen + $desplazamiento);
            $bytes = (string) fread($manejador, $largo);
            $texto = $plataforma === 3 ? mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE') : mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1');
            $texto = trim($texto);

            if ($texto !== '') {
                $familias[$texto] = true;
            }
        }

        return array_keys($familias);
    }

    private static function u16(string $bytes): int
    {
        $valor = unpack('n', str_pad($bytes, 2, "\0"));

        return is_array($valor) ? (int) $valor[1] : 0;
    }

    private static function u32(string $bytes): int
    {
        $valor = unpack('N', str_pad($bytes, 4, "\0"));

        return is_array($valor) ? (int) $valor[1] : 0;
    }
}
