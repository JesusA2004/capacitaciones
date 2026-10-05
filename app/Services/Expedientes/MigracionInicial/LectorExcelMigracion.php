<?php

namespace App\Services\Expedientes\MigracionInicial;

use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Lee la hoja BASE_GENERAL del Excel de migración inicial
 * (MR_LANA_PEOPLE_BASE_GENERAL_MIGRACION_FINAL.xlsx,
 * docs/MIGRACION_INICIAL_EXPEDIENTES.md). Los encabezados se reconocen por
 * NOMBRE (sin acentos/mayúsculas/signos) con sinónimos, nunca por posición:
 * mover una columna no rompe nada. Si dos encabezados compiten por el mismo
 * campo gana el sinónimo de mayor prioridad (p. ej. «Sucursal oficial» sobre
 * «Sucursal»). Solo se lee esa hoja: CONTACTOS_SIN_MATCH, CHECKLIST_DOCUMENTOS,
 * CATALOGO_SUCURSALES y CONFIG_MIGRACION_NAS no se importan. Celdas vacías → null.
 *
 * «Nombre», «Apellido paterno» y «Apellido materno» ya vienen separados: el
 * «Nombre completo» solo se usa para validar/emparejar, nunca para volver a
 * partir nombres y apellidos. Cada fila conserva su número real de Excel y
 * los valores ORIGINALES (para el reporte) junto con los normalizados.
 */
class LectorExcelMigracion
{
    /**
     * Campo => encabezados aceptados (normalizados con Normalizador::clave),
     * del más al menos prioritario.
     */
    private const COLUMNAS = [
        'telefono_personal' => ['TELEFONO PERSONAL CONTACTOS', 'TELEFONO PERSONAL', 'TEL PERSONAL', 'CELULAR PERSONAL'],
        'contacto_telefono' => ['TELEFONO EMERGENCIA', 'TELEFONO DE EMERGENCIA', 'TELEFONO DEL CONTACTO', 'TELEFONO CONTACTO', 'TEL CONTACTO', 'TELEFONO DE CONTACTO', 'TELEFONO CONTACTO EMERGENCIA', 'TELEFONO DEL CONTACTO DE EMERGENCIA'],
        'contacto_direccion' => ['DIRECCION CONTACTO EMERGENCIA', 'DIRECCION DEL CONTACTO DE EMERGENCIA', 'DIRECCION DEL CONTACTO', 'DIRECCION CONTACTO', 'DOMICILIO DEL CONTACTO', 'DOMICILIO CONTACTO'],
        'contacto_nombre' => ['CONTACTO DE EMERGENCIA', 'CONTACTO EMERGENCIA', 'NOMBRE DEL CONTACTO', 'NOMBRE CONTACTO', 'NOMBRE CONTACTO EMERGENCIA'],
        'parentesco' => ['PARENTESCO', 'PARENTESCO CONTACTO', 'PARENTESCO DEL CONTACTO'],
        'nombre_completo' => ['NOMBRE COMPLETO', 'NOMBRE COMPLETO DEL COLABORADOR', 'COLABORADOR'],
        'apellido_paterno' => ['APELLIDO PATERNO', 'PATERNO', 'A PATERNO', 'AP PATERNO'],
        'apellido_materno' => ['APELLIDO MATERNO', 'MATERNO', 'A MATERNO', 'AP MATERNO'],
        'nombre' => ['NOMBRE', 'NOMBRES', 'NOMBRE S'],
        'clave' => ['CLAVE', 'CLAVE EMPLEADO', 'NO EMPLEADO', 'NUM EMPLEADO', 'NUMERO DE EMPLEADO'],
        'correo' => ['CORREO ELECTRONICO', 'CORREO', 'EMAIL', 'E MAIL', 'MAIL'],
        'telefono' => ['TELEFONO BD', 'TELEFONO', 'TEL', 'CELULAR', 'TELEFONO CELULAR'],
        'rfc' => ['RFC'],
        'curp' => ['CURP'],
        'nss' => ['NSS AFILIACION IMSS', 'NSS', 'AFILIACION IMSS', 'NO IMSS', 'NUMERO DE SEGURIDAD SOCIAL', 'IMSS', 'NO AFILIACION'],
        'fecha_nacimiento' => ['FECHA DE NACIMIENTO', 'FECHA NACIMIENTO', 'F NACIMIENTO', 'NACIMIENTO'],
        'sexo' => ['SEXO', 'GENERO'],
        'fecha_alta' => ['FECHA DE ALTA', 'FECHA ALTA', 'FECHA DE INGRESO', 'FECHA INGRESO', 'ALTA'],
        'estatus' => ['ESTATUS LABORAL', 'ESTATUS', 'ESTADO LABORAL', 'SITUACION'],
        // Fuente oficial: «Sucursal oficial». «Sucursal origen» y «Sucursal
        // normalizada» solo quedan como auditoría.
        'sucursal' => ['SUCURSAL OFICIAL', 'SUCURSAL', 'PLAZA', 'OFICINA'],
        'sucursal_origen' => ['SUCURSAL ORIGEN'],
        'sucursal_normalizada' => ['SUCURSAL NORMALIZADA'],
        'empresa' => ['EMPRESA'],
        'departamento' => ['DEPARTAMENTO', 'AREA'],
        'puesto' => ['PUESTO', 'CARGO'],
        'condicion_medica' => ['CONDICION MEDICA CRONICA', 'CONDICION MEDICA', 'CONDICIONES MEDICAS', 'ENFERMEDAD', 'PADECIMIENTO'],
        'alergias' => ['ALERGIAS', 'ALERGIA'],
        // Columnas de CONTROL de la migración (auditoría), nunca datos laborales.
        'match_contacto' => ['MATCH CONTACTO'],
        'score_match' => ['SCORE MATCH'],
        'migrar_nas' => ['MIGRAR EXPEDIENTE NAS'],
        'observaciones_calidad' => ['OBSERVACIONES DE CALIDAD'],
        'hoja_origen' => ['HOJA ORIGEN'],
        'fila_origen' => ['FILA ORIGEN'],
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

        if (! isset($mapa['nombre']) || ! isset($mapa['apellido_paterno'])) {
            throw ValidationException::withMessages(['archivo' => 'No se encontraron los encabezados «Nombre» y «Apellido paterno» en la hoja '.$hojaNombre.'.']);
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

            /** campo => [columna, prioridad, encabezado original] */
            $asignados = [];
            $sinReconocer = [];

            foreach ($renglon as $columna => $celda) {
                $original = is_scalar($celda) ? trim((string) $celda) : '';

                if ($original === '') {
                    continue;
                }

                $reconocido = $this->campoDe(Normalizador::clave($original));

                if ($reconocido === null) {
                    $sinReconocer[] = $original;

                    continue;
                }

                [$campo, $prioridad] = $reconocido;

                if (isset($asignados[$campo]) && $asignados[$campo][1] <= $prioridad) {
                    $sinReconocer[] = $original;

                    continue;
                }

                if (isset($asignados[$campo])) {
                    $sinReconocer[] = $asignados[$campo][2];
                }

                $asignados[$campo] = [(int) $columna, $prioridad, $original];
            }

            if (count($asignados) > count($mejor[1])) {
                $mejor = [(int) $indice, array_map(fn (array $a): int => $a[0], $asignados), $sinReconocer];
            }
        }

        return $mejor;
    }

    /**
     * @return array{0: string, 1: int}|null campo y prioridad (0 = mejor)
     */
    private function campoDe(string $encabezado): ?array
    {
        foreach (self::COLUMNAS as $campo => $sinonimos) {
            $posicion = array_search($encabezado, $sinonimos, true);

            if ($posicion !== false) {
                return [$campo, (int) $posicion];
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

        // «Match contacto = NO»: el contacto de emergencia no se enlazó con
        // confianza suficiente → no se importa (nunca se inventa ni se adivina).
        $matchContacto = Normalizador::clave($t('match_contacto'));
        $contactoConfiable = $matchContacto !== 'NO';
        $c = fn (string $campo): ?string => $contactoConfiable ? $t($campo) : null;

        return [
            'fila' => $filaExcel,
            'original' => $o,
            'clave' => $t('clave') !== null ? (string) preg_replace('/\.0+$/', '', (string) $t('clave')) : null,
            'nombre' => $nombre !== null ? $this->titulo($nombre) : null,
            'apellido_paterno' => $paterno !== null ? $this->titulo($paterno) : null,
            'apellido_materno' => $materno !== null ? $this->titulo($materno) : null,
            'apellidos' => $apellidos !== null ? $this->titulo($apellidos) : null,
            'nombre_completo' => $completo !== '' ? $this->titulo($completo) : null,
            'tokens' => Normalizador::tokensNombre($completo),
            'correo' => Normalizador::correo($t('correo')),
            'telefono' => Normalizador::telefono($t('telefono')),
            'telefono_personal' => Normalizador::telefono($c('telefono_personal')),
            'rfc' => Normalizador::rfc($t('rfc')),
            'curp' => Normalizador::curp($t('curp')),
            'nss' => $t('nss') !== null ? (string) preg_replace('/[^0-9]/', '', (string) $t('nss')) ?: null : null,
            'fecha_nacimiento' => Normalizador::fecha($o['fecha_nacimiento'] ?? null),
            'sexo' => $this->sexo($t('sexo')),
            'fecha_alta' => Normalizador::fecha($o['fecha_alta'] ?? null),
            'estatus' => $this->estatus($t('estatus')),
            'estatus_original' => $t('estatus'),
            'sucursal' => $t('sucursal'),
            'sucursal_origen' => $t('sucursal_origen'),
            'empresa' => $t('empresa'),
            'departamento' => $t('departamento'),
            'puesto' => $t('puesto'),
            'condicion_medica' => $t('condicion_medica'),
            'alergias' => $t('alergias'),
            'match_contacto' => $matchContacto !== '' ? $matchContacto : null,
            'contacto_nombre' => $c('contacto_nombre') !== null ? $this->titulo((string) $c('contacto_nombre')) : null,
            'parentesco' => $c('parentesco'),
            'contacto_telefono' => Normalizador::telefono($c('contacto_telefono')),
            'contacto_direccion' => $c('contacto_direccion'),
            'migrar_nas' => $t('migrar_nas'),
            'observaciones_calidad' => $t('observaciones_calidad'),
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
