<?php

namespace App\Services\Expedientes\MigracionInicial;

/**
 * Combina la base por sucursales (identidad, SEMANA 41) con el roster
 * nominal (puesto/departamento). La identidad SIEMPRE manda desde la base;
 * el puesto y el departamento SOLO se toman del roster y, sin match, quedan
 * null (nunca se inventan: esa fila quedará «en revisión» en el plan).
 *
 * El cruce por identidad es EXACTO, nunca aproximado:
 *   1. clave legacy normalizada, si es única en el roster;
 *   2. si no, nombre completo normalizado, si es único en el roster.
 * Si el roster tiene 2+ entradas para la misma identidad, no se asigna
 * puesto (ambiguo) y se deja una advertencia auditable en la fila.
 */
class CombinadorRosterNominal
{
    /**
     * @param  array{filas: list<array<string, mixed>>, columnas: array<string, string>, sin_reconocer: list<string>, hoja: string, formato: string}  $excel
     * @param  array{entradas: list<array{clave: string|null, nombre_clave: string, puesto: string|null, departamento: string|null}>, sin_reconocer: list<string>}  $roster
     * @return array{filas: list<array<string, mixed>>, columnas: array<string, string>, sin_reconocer: list<string>, hoja: string, formato: string}
     */
    public function combinar(array $excel, array $roster): array
    {
        $porClave = [];
        $porNombre = [];
        $conteoClave = [];
        $conteoNombre = [];

        foreach ($roster['entradas'] as $entrada) {
            if ($entrada['clave'] !== null && $entrada['clave'] !== '') {
                $clave = Normalizador::clave($entrada['clave']);
                $conteoClave[$clave] = ($conteoClave[$clave] ?? 0) + 1;
                $porClave[$clave] = $entrada;
            }

            if ($entrada['nombre_clave'] !== '') {
                $conteoNombre[$entrada['nombre_clave']] = ($conteoNombre[$entrada['nombre_clave']] ?? 0) + 1;
                $porNombre[$entrada['nombre_clave']] = $entrada;
            }
        }

        $excel['filas'] = array_map(function (array $fila) use ($porClave, $porNombre, $conteoClave, $conteoNombre): array {
            $fila['puesto'] = null;
            $fila['departamento'] = null;
            $fila['advertencia_roster'] = null;

            $clave = $fila['clave'] !== null ? Normalizador::clave((string) $fila['clave']) : '';
            $nombre = Normalizador::clave((string) ($fila['nombre_completo'] ?? ''));

            // 1. Clave legacy única.
            if ($clave !== '' && ($conteoClave[$clave] ?? 0) === 1) {
                return $this->aplicar($fila, $porClave[$clave], 'clave');
            }

            // 2. Nombre completo normalizado exacto.
            if ($nombre !== '') {
                if (($conteoNombre[$nombre] ?? 0) === 1) {
                    return $this->aplicar($fila, $porNombre[$nombre], 'nombre');
                }

                if (($conteoNombre[$nombre] ?? 0) > 1) {
                    $fila['advertencia_roster'] = 'El roster nominal tiene 2 o más entradas para esta identidad: no se asigna puesto (ambiguo), queda en revisión.';
                }
            }

            return $fila;
        }, $excel['filas']);

        $excel['sin_reconocer'] = array_values(array_unique([...$excel['sin_reconocer'], ...$roster['sin_reconocer']]));

        return $excel;
    }

    /**
     * @param  array<string, mixed>  $fila
     * @param  array{clave: string|null, nombre_clave: string, puesto: string|null, departamento: string|null}  $entrada
     * @return array<string, mixed>
     */
    private function aplicar(array $fila, array $entrada, string $regla): array
    {
        $fila['puesto'] = $entrada['puesto'];
        $fila['departamento'] = $entrada['departamento'];
        $fila['roster_match'] = $regla;

        return $fila;
    }
}
