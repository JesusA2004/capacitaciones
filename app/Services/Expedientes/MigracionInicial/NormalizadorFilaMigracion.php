<?php

namespace App\Services\Expedientes\MigracionInicial;

/**
 * Reglas COMPARTIDAS de lectura de una fila de migración inicial, sin
 * acoplarse a un formato de Excel concreto. Tanto el lector del formato
 * único (LectorExcelMigracion, hoja BASE_GENERAL) como el del formato nuevo
 * por sucursales (LectorBaseSemana41, hojas Sucursales + Corporativo) las
 * reutilizan: el reconocimiento de encabezados por NOMBRE (sinónimos, nunca
 * por posición) y la normalización de cada fila a la MISMA estructura
 * canónica que consume el PlanificadorMigracion.
 *
 * Nada de esto adivina datos: celdas vacías quedan null y el valor original
 * importante se conserva aparte para el reporte.
 */
final class NormalizadorFilaMigracion
{
    /**
     * Campo => encabezados aceptados (normalizados con Normalizador::clave),
     * del más al menos prioritario. Si dos encabezados compiten por el mismo
     * campo gana el sinónimo de mayor prioridad (p. ej. «Sucursal oficial»
     * sobre «Sucursal»).
     */
    public const COLUMNAS = [
        'telefono_personal' => ['TELEFONO PERSONAL CONTACTOS', 'TELEFONO PERSONAL', 'TEL PERSONAL', 'CELULAR PERSONAL'],
        'contacto_telefono' => ['TELEFONO EMERGENCIA', 'TELEFONO DE EMERGENCIA', 'TELEFONO DEL CONTACTO', 'TELEFONO CONTACTO', 'TEL CONTACTO', 'TELEFONO DE CONTACTO', 'TELEFONO CONTACTO EMERGENCIA', 'TELEFONO DEL CONTACTO DE EMERGENCIA'],
        'contacto_direccion' => ['DIRECCION CONTACTO EMERGENCIA', 'DIRECCION DEL CONTACTO DE EMERGENCIA', 'DIRECCION DEL CONTACTO', 'DIRECCION CONTACTO', 'DOMICILIO DEL CONTACTO', 'DOMICILIO CONTACTO'],
        'contacto_nombre' => ['CONTACTO DE EMERGENCIA', 'CONTACTO EMERGENCIA', 'NOMBRE DEL CONTACTO', 'NOMBRE CONTACTO', 'NOMBRE CONTACTO EMERGENCIA'],
        'parentesco' => ['PARENTESCO', 'PARENTESCO CONTACTO', 'PARENTESCO DEL CONTACTO'],
        'nombre_completo' => ['NOMBRE COMPLETO', 'NOMBRE COMPLETO DEL COLABORADOR', 'NOMBRE DEL COLABORADOR', 'COLABORADOR'],
        'apellido_paterno' => ['APELLIDO PATERNO', 'PATERNO', 'A PATERNO', 'AP PATERNO'],
        'apellido_materno' => ['APELLIDO MATERNO', 'MATERNO', 'A MATERNO', 'AP MATERNO'],
        'nombre' => ['NOMBRE', 'NOMBRES', 'NOMBRE S'],
        // «NO NOI» = número de empleado de nómina (NOI): la misma «Clave» de
        // la base SEMANA 41 (DIRECTORIO_GERENCIAS_MRLANA.xlsx).
        'clave' => ['CLAVE', 'CLAVE EMPLEADO', 'NO EMPLEADO', 'NUM EMPLEADO', 'NUMERO DE EMPLEADO', 'NO NOI'],
        // Contacto corporativo (directorio de gerencias): solo lo usa el roster.
        'correo_corporativo' => ['CORREO INSTITUCIONAL', 'CORREO CORPORATIVO'],
        'telefono_corporativo' => ['NO TELEFONICO', 'TELEFONO CORPORATIVO', 'TELEFONO INSTITUCIONAL'],
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
     * Busca el renglón de encabezados en las primeras $maxFilas filas y
     * devuelve [índice del encabezado, campo => columna, encabezados sin
     * reconocer]. El mapa gana el renglón que reconoce MÁS columnas.
     *
     * @param  array<int, mixed>  $datos
     * @return array{0: int, 1: array<string, int>, 2: list<string>}
     */
    public static function mapaEncabezados(array $datos, int $maxFilas = 10): array
    {
        $mejor = [0, [], []];

        foreach (array_slice($datos, 0, $maxFilas, true) as $indice => $renglon) {
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

                $reconocido = self::campoDe(Normalizador::clave($original));

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
    private static function campoDe(string $encabezado): ?array
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
     * Normaliza una fila (valores originales por campo) a la estructura
     * canónica. `$o['fecha_nacimiento']` y `$o['fecha_alta']` se esperan tal
     * cual venían (serial de Excel o texto); el resto como texto ya recortado.
     *
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    public static function normalizarFila(array $o, int $filaExcel): array
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
            'hoja_origen' => $t('hoja_origen'),
            'clave' => $t('clave') !== null ? (string) preg_replace('/\.0+$/', '', (string) $t('clave')) : null,
            'nombre' => $nombre !== null ? self::titulo($nombre) : null,
            'apellido_paterno' => $paterno !== null ? self::titulo($paterno) : null,
            'apellido_materno' => $materno !== null ? self::titulo($materno) : null,
            'apellidos' => $apellidos !== null ? self::titulo($apellidos) : null,
            'nombre_completo' => $completo !== '' ? self::titulo($completo) : null,
            'tokens' => Normalizador::tokensNombre($completo),
            'correo' => Normalizador::correo($t('correo')),
            'telefono' => Normalizador::telefono($t('telefono')),
            'telefono_personal' => Normalizador::telefono($c('telefono_personal')),
            'rfc' => Normalizador::rfc($t('rfc')),
            'curp' => Normalizador::curp($t('curp')),
            'nss' => $t('nss') !== null ? (string) preg_replace('/[^0-9]/', '', (string) $t('nss')) ?: null : null,
            'fecha_nacimiento' => Normalizador::fecha($o['fecha_nacimiento'] ?? null),
            'sexo' => self::sexo($t('sexo')),
            'fecha_alta' => Normalizador::fecha($o['fecha_alta'] ?? null),
            'estatus' => self::estatus($t('estatus')),
            'estatus_original' => $t('estatus'),
            'sucursal' => $t('sucursal'),
            'sucursal_origen' => $t('sucursal_origen'),
            'empresa' => $t('empresa'),
            'departamento' => $t('departamento'),
            'puesto' => $t('puesto'),
            'condicion_medica' => $t('condicion_medica'),
            'alergias' => $t('alergias'),
            'match_contacto' => $matchContacto !== '' ? $matchContacto : null,
            'contacto_nombre' => $c('contacto_nombre') !== null ? self::titulo((string) $c('contacto_nombre')) : null,
            'parentesco' => $c('parentesco'),
            'contacto_telefono' => Normalizador::telefono($c('contacto_telefono')),
            'contacto_direccion' => $c('contacto_direccion'),
            'migrar_nas' => $t('migrar_nas'),
            'observaciones_calidad' => $t('observaciones_calidad'),
        ];
    }

    public static function titulo(string $valor): string
    {
        return mb_convert_case(mb_strtolower($valor), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * «M» sola es ambigua (Masculino o Mujer, como en la CURP): no se
     * adivina; el planificador la toma de la CURP válida si existe.
     */
    public static function sexo(?string $valor): ?string
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
    public static function estatus(?string $valor): ?string
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
