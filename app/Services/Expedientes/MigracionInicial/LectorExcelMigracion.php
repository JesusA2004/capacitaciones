<?php

namespace App\Services\Expedientes\MigracionInicial;

use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Lee la hoja BASE_GENERAL del Excel de migración inicial
 * (docs/MIGRACION_INICIAL_EXPEDIENTES.md). Los encabezados se reconocen por
 * nombre (sin acentos/mayúsculas) con sinónimos, nunca por posición. Solo
 * se lee esa hoja: CONTACTOS_SIN_MATCH no se importa. Celdas vacías → null.
 *
 * Cada fila conserva su número real de Excel y los valores ORIGINALES (para
 * el reporte) junto con los normalizados.
 */
class LectorExcelMigracion
{
    /**
     * Campo => encabezados aceptados (normalizados con Normalizador::clave).
     * El orden importa: se prueban primero los más específicos.
     */
    private const COLUMNAS = [
        'telefono_personal' => ['TELEFONO PERSONAL', 'TEL PERSONAL', 'CELULAR PERSONAL'],
        'contacto_telefono' => ['TELEFONO DEL CONTACTO', 'TELEFONO CONTACTO', 'TEL CONTACTO', 'TELEFONO DE CONTACTO', 'TELEFONO CONTACTO EMERGENCIA', 'TELEFONO DEL CONTACTO DE EMERGENCIA'],
        'contacto_direccion' => ['DIRECCION DEL CONTACTO', 'DIRECCION CONTACTO', 'DOMICILIO DEL CONTACTO', 'DOMICILIO CONTACTO'],
        'contacto_nombre' => ['CONTACTO DE EMERGENCIA', 'CONTACTO EMERGENCIA', 'NOMBRE DEL CONTACTO', 'NOMBRE CONTACTO', 'NOMBRE CONTACTO EMERGENCIA'],
        'parentesco' => ['PARENTESCO', 'PARENTESCO CONTACTO', 'PARENTESCO DEL CONTACTO'],
        'nombre_completo' => ['NOMBRE COMPLETO', 'NOMBRE COMPLETO DEL COLABORADOR', 'COLABORADOR'],
        'apellido_paterno' => ['APELLIDO PATERNO', 'PATERNO', 'A PATERNO', 'AP PATERNO'],
        'apellido_materno' => ['APELLIDO MATERNO', 'MATERNO', 'A MATERNO', 'AP MATERNO'],
        'nombre' => ['NOMBRE', 'NOMBRES', 'NOMBRE S'],
        'clave' => ['CLAVE', 'CLAVE EMPLEADO', 'NO EMPLEADO', 'NUM EMPLEADO', 'NUMERO DE EMPLEADO'],
        'correo' => ['CORREO ELECTRONICO', 'CORREO', 'EMAIL', 'E MAIL', 'MAIL'],
        'telefono' => ['TELEFONO', 'TEL', 'CELULAR', 'TELEFONO CELULAR'],
        'rfc' => ['RFC'],
        'curp' => ['CURP'],
        'nss' => ['NSS', 'AFILIACION IMSS', 'NSS AFILIACION IMSS', 'NO IMSS', 'NUMERO DE SEGURIDAD SOCIAL', 'IMSS', 'NO AFILIACION'],
        'fecha_nacimiento' => ['FECHA DE NACIMIENTO', 'FECHA NACIMIENTO', 'F NACIMIENTO', 'NACIMIENTO'],
        'sexo' => ['SEXO', 'GENERO'],
        'fecha_alta' => ['FECHA DE ALTA', 'FECHA ALTA', 'FECHA DE INGRESO', 'FECHA INGRESO', 'ALTA'],
        'estatus' => ['ESTATUS LABORAL', 'ESTATUS', 'ESTADO LABORAL', 'SITUACION'],
        'sucursal' => ['SUCURSAL', 'PLAZA', 'OFICINA'],
        'puesto' => ['PUESTO', 'CARGO'],
        'condicion_medica' => ['CONDICION MEDICA', 'CONDICIONES MEDICAS', 'ENFERMEDAD', 'PADECIMIENTO'],
        'alergias' => ['ALERGIAS', 'ALERGIA'],
    ];

    /**
     * @return array{filas: list<array<string, mixed>>, columnas: array<string, string>, sin_reconocer: list<string>, hoja: string}
     */
    public function leer(string $ruta): array
    {
        $hojaNombre = (string) config('expedientes.migracion_inicial.hoja', 'BASE_GENERAL');

        try {
            $lector = IOFactory::createReaderForFile($ruta);
            $lector->setReadDataOnly(true);
            $lector->setLoadSheetsOnly([$hojaNombre]);
            $libro = $lector->load($ruta);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['archivo' => 'No se pudo leer el Excel: '.$e->getMessage()]);
        }

        $hoja = $libro->getSheetByName($hojaNombre);

        if ($hoja === null) {
            throw ValidationException::withMessages(['archivo' => "El Excel no tiene la hoja «{$hojaNombre}»."]);
        }

        $datos = $hoja->toArray(null, true, false, false);
        [$filaEncabezado, $mapa, $sinReconocer] = $this->encabezados($datos);

        if (! isset($mapa['curp']) && ! isset($mapa['nombre']) && ! isset($mapa['nombre_completo'])) {
            throw ValidationException::withMessages(['archivo' => 'No se encontraron los encabezados (Nombre / CURP) en la hoja '.$hojaNombre.'.']);
        }

        $filas = [];

        foreach ($datos as $indice => $renglon) {
            if ($indice <= $filaEncabezado || ! is_array($renglon)) {
                continue;
            }

            $original = [];

            foreach ($mapa as $campo => $columna) {
                $valor = $renglon[$columna] ?? null;
                $original[$campo] = is_string($valor) ? trim($valor) : $valor;
            }

            if (array_filter($original, fn ($v) => $v !== null && $v !== '') === []) {
                continue;
            }

            $filas[] = $this->normalizarFila($original, $indice + 1);
        }

        return [
            'filas' => $filas,
            'columnas' => array_map(fn (int $c) => (string) ($datos[$filaEncabezado][$c] ?? ''), $mapa),
            'sin_reconocer' => $sinReconocer,
            'hoja' => $hojaNombre,
        ];
    }

    /**
     * Busca el renglón de encabezados en las primeras 10 filas.
     *
     * @param  array<int, mixed>  $datos
     * @return array{0: int, 1: array<string, int>, 2: list<string>}
     */
    private function encabezados(array $datos): array
    {
        $mejor = [0, [], []];

        foreach (array_slice($datos, 0, 10, true) as $indice => $renglon) {
            if (! is_array($renglon)) {
                continue;
            }

            $mapa = [];
            $sinReconocer = [];

            foreach ($renglon as $columna => $celda) {
                $texto = Normalizador::clave(is_scalar($celda) ? (string) $celda : '');

                if ($texto === '') {
                    continue;
                }

                $campo = $this->campoDe($texto, $mapa);

                if ($campo !== null) {
                    $mapa[$campo] = (int) $columna;
                } else {
                    $sinReconocer[] = (string) $celda;
                }
            }

            if (count($mapa) > count($mejor[1])) {
                $mejor = [(int) $indice, $mapa, $sinReconocer];
            }
        }

        return $mejor;
    }

    /**
     * @param  array<string, int>  $yaUsados
     */
    private function campoDe(string $encabezado, array $yaUsados): ?string
    {
        foreach (self::COLUMNAS as $campo => $sinonimos) {
            if (! isset($yaUsados[$campo]) && in_array($encabezado, $sinonimos, true)) {
                return $campo;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    private function normalizarFila(array $o, int $filaExcel): array
    {
        $t = fn (string $campo): ?string => Normalizador::texto(isset($o[$campo]) && is_scalar($o[$campo]) ? (string) $o[$campo] : null);
        $nombre = $t('nombre');
        $paterno = $t('apellido_paterno');
        $materno = $t('apellido_materno');
        $completo = $t('nombre_completo') ?? trim(implode(' ', array_filter([$nombre, $paterno, $materno])));
        $apellidos = trim(implode(' ', array_filter([$paterno, $materno]))) ?: null;

        // Solo nombre completo: nombre = todo menos los dos últimos tokens.
        if ($nombre === null && $completo !== '') {
            $partes = explode(' ', $completo);
            $nombre = count($partes) > 2 ? implode(' ', array_slice($partes, 0, -2)) : $partes[0];
            $apellidos ??= count($partes) > 2 ? implode(' ', array_slice($partes, -2)) : ($partes[1] ?? null);
        }

        return [
            'fila' => $filaExcel,
            'original' => $o,
            'clave' => $t('clave') !== null ? (string) preg_replace('/\.0+$/', '', (string) $t('clave')) : null,
            'nombre' => $nombre !== null ? $this->titulo($nombre) : null,
            'apellidos' => $apellidos !== null ? $this->titulo($apellidos) : null,
            'nombre_completo' => $completo !== '' ? $this->titulo($completo) : null,
            'tokens' => Normalizador::tokensNombre($completo),
            'correo' => Normalizador::correo($t('correo')),
            'telefono' => Normalizador::telefono($t('telefono')),
            'telefono_personal' => Normalizador::telefono($t('telefono_personal')),
            'rfc' => Normalizador::rfc($t('rfc')),
            'curp' => Normalizador::curp($t('curp')),
            'nss' => $t('nss') !== null ? (string) preg_replace('/[^0-9]/', '', (string) $t('nss')) ?: null : null,
            'fecha_nacimiento' => Normalizador::fecha($o['fecha_nacimiento'] ?? null),
            'sexo' => $this->sexo($t('sexo')),
            'fecha_alta' => Normalizador::fecha($o['fecha_alta'] ?? null),
            'estatus' => $this->estatus($t('estatus')),
            'estatus_original' => $t('estatus'),
            'sucursal' => $t('sucursal'),
            'puesto' => $t('puesto'),
            'condicion_medica' => $t('condicion_medica'),
            'alergias' => $t('alergias'),
            'contacto_nombre' => $t('contacto_nombre') !== null ? $this->titulo((string) $t('contacto_nombre')) : null,
            'parentesco' => $t('parentesco'),
            'contacto_telefono' => Normalizador::telefono($t('contacto_telefono')),
            'contacto_direccion' => $t('contacto_direccion'),
        ];
    }

    private function titulo(string $valor): string
    {
        return mb_convert_case(mb_strtolower($valor), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * «M» sola es ambigua (Masculino o Mujer, como en la CURP): no se
     * adivina; el planificador la toma de la CURP válida si existe.
     */
    private function sexo(?string $valor): ?string
    {
        return match (Normalizador::clave($valor)) {
            'MASCULINO', 'HOMBRE', 'H' => 'masculino',
            'F', 'FEMENINO', 'MUJER' => 'femenino',
            default => null,
        };
    }

    /**
     * activo | baja | null (sin dato o no reconocido: activo, con advertencia).
     * «Reingreso» describe CÓMO entró (sigue trabajando) e «Incapacidad»,
     * «Permiso» o «Vacaciones» no son baja: todos → activo. El texto
     * original se conserva aparte (estatus_origen).
     */
    private function estatus(?string $valor): ?string
    {
        $clave = Normalizador::clave($valor);

        return match (true) {
            $clave === '' => null,
            (bool) preg_match('/\b(BAJA|INACTIV\w*|RENUNCI\w*|DESPID\w*|TERMINAD\w*|LIQUIDAD\w*|SEPARAD\w*)\b/', $clave) => 'baja',
            (bool) preg_match('/\b(ACTIV\w*|ALTA|REINGRES\w*|INCAPACI\w*|PERMISO|VACACIONES|VIGENTE|LABORANDO)\b/', $clave) => 'activo',
            default => null,
        };
    }
}
