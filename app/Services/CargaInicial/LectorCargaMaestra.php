<?php

namespace App\Services\CargaInicial;

use App\Services\Expedientes\MigracionInicial\Normalizador;
use App\Services\Expedientes\MigracionInicial\NormalizadorFilaMigracion;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Lee el libro maestro MR_LANA_PEOPLE_CARGA_INICIAL.xlsx (Configuración →
 * Importar datos → «Carga inicial PEOPLE»). Solo LEE: no decide nada.
 *
 * Las columnas se reconocen por NOMBRE (normalizado con Normalizador::clave:
 * «CLAVE_NOI» → «CLAVE NOI»), nunca por posición; el renglón de encabezados
 * se busca en las primeras 15 filas. Una hoja o columna obligatoria que
 * falta detiene el análisis con un mensaje que dice cuál (auditable, nunca se
 * ignora en silencio). Las fechas llegan como serial de Excel o texto y las
 * interpreta Normalizador::fecha().
 *
 * Hojas: COLABORADORES (estructura canónica del PlanificadorMigracion,
 * formato «maestra»), SALARIOS_VACACIONES, HEADCOUNT, ROTACION_2026,
 * CATALOGO_PUESTOS y PENDIENTES (informativa, opcional). RESUMEN no se lee.
 */
class LectorCargaMaestra
{
    public const HOJA_COLABORADORES = 'COLABORADORES';

    public const HOJA_SALARIOS = 'SALARIOS_VACACIONES';

    public const HOJA_HEADCOUNT = 'HEADCOUNT';

    public const HOJA_ROTACION = 'ROTACION_2026';

    public const HOJA_CATALOGO = 'CATALOGO_PUESTOS';

    public const HOJA_PENDIENTES = 'PENDIENTES';

    /** Tipos de la hoja PENDIENTES (informativa). */
    public const TIPOS_PENDIENTE = ['PENDIENTE_ACTUAL', 'EXCLUIDO_HEADCOUNT', 'EXCLUIDO'];

    /**
     * Columnas por hoja: campo => [encabezados aceptados (normalizados), obligatoria].
     *
     * @var array<string, array<string, array{0: list<string>, 1: bool}>>
     */
    private const COLUMNAS = [
        self::HOJA_COLABORADORES => [
            'clave' => [['CLAVE NOI', 'NO NOI', 'CLAVE'], true],
            'nombre' => [['NOMBRE', 'NOMBRES'], true],
            'apellido_paterno' => [['APELLIDO PATERNO'], true],
            'apellido_materno' => [['APELLIDO MATERNO'], false],
            'nombre_completo' => [['NOMBRE COMPLETO'], false],
            'curp' => [['CURP'], false],
            'rfc' => [['RFC'], false],
            'nss' => [['NSS'], false],
            'sexo' => [['GENERO', 'SEXO'], false],
            'fecha_nacimiento' => [['FECHA NACIMIENTO', 'FECHA DE NACIMIENTO'], false],
            'fecha_alta' => [['FECHA INGRESO', 'FECHA DE INGRESO'], false],
            'estatus' => [['ESTATUS'], false],
            'empresa' => [['EMPRESA'], false],
            'sucursal' => [['SUCURSAL'], true],
            'departamento' => [['DEPARTAMENTO', 'AREA'], true],
            'puesto' => [['PUESTO'], true],
            'correo' => [['CORREO PERSONAL'], false],
            'correo_corporativo' => [['CORREO CORPORATIVO'], false],
            'telefono' => [['TELEFONO PERSONAL'], false],
            'telefono_corporativo' => [['TELEFONO CORPORATIVO'], false],
        ],
        self::HOJA_SALARIOS => [
            'clave' => [['CLAVE NOI', 'NO NOI', 'CLAVE'], true],
            'nombre' => [['NOMBRE COMPLETO', 'NOMBRE'], false],
            'sucursal' => [['SUCURSAL'], false],
            'fecha_ingreso' => [['FECHA INGRESO', 'FECHA DE INGRESO'], false],
            'salario_diario' => [['SALARIO DIARIO'], true],
            'dias_otorgados' => [['DIAS OTORGADOS'], false],
            'dias_tomados' => [['DIAS TOMADOS'], true],
            'dias_restantes' => [['DIAS RESTANTES'], false],
        ],
        self::HOJA_HEADCOUNT => [
            'sucursal' => [['SUCURSAL'], true],
            'puesto' => [['PUESTO'], true],
            'plantilla' => [['PLANTILLA AUTORIZADA', 'AUTORIZADA'], true],
            'fecha_corte' => [['FECHA CORTE', 'FECHA DE CORTE'], false],
        ],
        self::HOJA_ROTACION => [
            'anio' => [['ANIO', 'ANO'], true],
            'mes' => [['MES'], true],
            'sucursal' => [['SUCURSAL'], true],
            'plantilla' => [['PLANTILLA'], true],
            'altas' => [['ALTAS'], true],
            'bajas' => [['BAJAS'], true],
            'rotacion' => [['ROTACION PORCENTAJE', 'ROTACION', 'INDICE'], true],
        ],
        self::HOJA_CATALOGO => [
            'puesto' => [['PUESTO'], true],
            'departamento' => [['DEPARTAMENTO', 'AREA'], true],
        ],
        self::HOJA_PENDIENTES => [
            'tipo' => [['TIPO', 'CATEGORIA', 'ESTADO'], false],
            'clave' => [['CLAVE NOI', 'NO NOI', 'CLAVE'], false],
            'nombre' => [['NOMBRE', 'NOMBRE COMPLETO'], false],
            'sucursal' => [['SUCURSAL'], false],
            'campo' => [['CAMPO'], false],
            'motivo' => [['MOTIVO'], false],
            'accion' => [['ACCION'], false],
        ],
    ];

    private const MESES = [
        'ENERO' => 1, 'FEBRERO' => 2, 'MARZO' => 3, 'ABRIL' => 4, 'MAYO' => 5, 'JUNIO' => 6,
        'JULIO' => 7, 'AGOSTO' => 8, 'SEPTIEMBRE' => 9, 'SETIEMBRE' => 9, 'OCTUBRE' => 10, 'NOVIEMBRE' => 11, 'DICIEMBRE' => 12,
        // Abreviaturas (el archivo real trae «SEPT»).
        'ENE' => 1, 'FEB' => 2, 'MAR' => 3, 'ABR' => 4, 'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AGO' => 8,
        'SEP' => 9, 'SEPT' => 9, 'SET' => 9, 'OCT' => 10, 'NOV' => 11, 'DIC' => 12,
    ];

    /**
     * @return array{
     *     colaboradores: array{filas: list<array<string, mixed>>, columnas: array<string, string>, sin_reconocer: list<string>, hoja: string, formato: string},
     *     salarios: list<array{fila: int, clave: string|null, nombre: string|null, sucursal: string|null, fecha_ingreso: string|null, salario_diario: float|null, dias_otorgados: float|null, dias_tomados: float|null, dias_restantes: float|null}>,
     *     headcount: list<array{fila: int, sucursal: string|null, puesto: string|null, plantilla: int|null, fecha_corte: string|null}>,
     *     rotacion: list<array{fila: int, anio: int|null, mes: int|null, mes_original: string|null, sucursal: string|null, plantilla: int|null, altas: int|null, bajas: int|null, rotacion: float|null}>,
     *     catalogo: list<array{fila: int, puesto: string|null, departamento: string|null}>,
     *     pendientes: list<array{fila: int, tipo: string|null, clave: string|null, nombre: string|null, sucursal: string|null, campo: string|null, motivo: string|null, accion: string|null}>,
     *     hoja_rotacion: string
     * }
     */
    public function leer(string $ruta): array
    {
        $libro = $this->cargar($ruta);

        $colaboradores = $this->hoja($libro, self::HOJA_COLABORADORES, true);
        $salarios = $this->hoja($libro, self::HOJA_SALARIOS, true);
        $headcount = $this->hoja($libro, self::HOJA_HEADCOUNT, true);
        $rotacion = $this->hoja($libro, self::HOJA_ROTACION, true);
        $catalogo = $this->hoja($libro, self::HOJA_CATALOGO, true);
        $pendientes = $this->hoja($libro, self::HOJA_PENDIENTES, false);

        return [
            'colaboradores' => $this->colaboradores($colaboradores['filas'], $colaboradores['columnas']),
            'salarios' => array_map(fn (array $f) => [
                'fila' => $f['_fila'],
                'clave' => $this->clave($f['clave'] ?? null),
                'nombre' => $this->texto($f['nombre'] ?? null),
                'sucursal' => $this->texto($f['sucursal'] ?? null),
                'fecha_ingreso' => Normalizador::fecha($f['fecha_ingreso'] ?? null),
                'salario_diario' => $this->numero($f['salario_diario'] ?? null),
                'dias_otorgados' => $this->numero($f['dias_otorgados'] ?? null),
                'dias_tomados' => $this->numero($f['dias_tomados'] ?? null),
                'dias_restantes' => $this->numero($f['dias_restantes'] ?? null),
            ], $salarios['filas']),
            'headcount' => array_map(fn (array $f) => [
                'fila' => $f['_fila'],
                'sucursal' => $this->texto($f['sucursal'] ?? null),
                'puesto' => $this->texto($f['puesto'] ?? null),
                'plantilla' => $this->entero($f['plantilla'] ?? null),
                'fecha_corte' => Normalizador::fecha($f['fecha_corte'] ?? null),
            ], $headcount['filas']),
            'rotacion' => array_map(fn (array $f) => [
                'fila' => $f['_fila'],
                'anio' => $this->entero($f['anio'] ?? null),
                'mes' => $this->mes($f['mes'] ?? null),
                'mes_original' => $this->texto($f['mes'] ?? null),
                'sucursal' => $this->texto($f['sucursal'] ?? null),
                'plantilla' => $this->entero($f['plantilla'] ?? null),
                'altas' => $this->entero($f['altas'] ?? null),
                'bajas' => $this->entero($f['bajas'] ?? null),
                'rotacion' => $this->numero($f['rotacion'] ?? null),
            ], $rotacion['filas']),
            'catalogo' => array_map(fn (array $f) => [
                'fila' => $f['_fila'],
                'puesto' => $this->texto($f['puesto'] ?? null),
                'departamento' => $this->texto($f['departamento'] ?? null),
            ], $catalogo['filas']),
            'pendientes' => array_map(fn (array $f) => [
                'fila' => $f['_fila'],
                'tipo' => $this->tipoPendiente($f),
                'clave' => $this->clave($f['clave'] ?? null),
                'nombre' => $this->texto($f['nombre'] ?? null),
                'sucursal' => $this->texto($f['sucursal'] ?? null),
                'campo' => $this->texto($f['campo'] ?? null),
                'motivo' => $this->texto($f['motivo'] ?? null),
                'accion' => $this->texto($f['accion'] ?? null),
            ], $pendientes['filas']),
            'hoja_rotacion' => $rotacion['hoja'],
        ];
    }

    private function cargar(string $ruta): Spreadsheet
    {
        try {
            $lector = IOFactory::createReaderForFile($ruta);
            $lector->setReadDataOnly(true);

            return $lector->load($ruta);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['archivo' => sprintf('No se pudo leer el Excel: %s', $e->getMessage())]);
        }
    }

    /**
     * Filas de una hoja como campo => valor crudo (más `_fila`, el número de
     * renglón en Excel). Renglones totalmente vacíos se saltan.
     *
     * @return array{filas: list<array<string, mixed>>, columnas: array<string, string>, hoja: string}
     */
    private function hoja(Spreadsheet $libro, string $nombre, bool $obligatoria): array
    {
        $hoja = $this->buscarHoja($libro, $nombre);

        if ($hoja === null) {
            if ($obligatoria) {
                throw ValidationException::withMessages(['archivo' => sprintf('Falta la hoja «%s» en el archivo de carga inicial.', $nombre)]);
            }

            return ['filas' => [], 'columnas' => [], 'hoja' => $nombre];
        }

        $datos = $hoja->toArray(null, true, false, false);
        $especificacion = self::COLUMNAS[$nombre];
        [$indiceEncabezado, $mapa, $originales] = $this->encabezados($datos, $especificacion);

        foreach ($especificacion as $campo => [$sinonimos, $requerida]) {
            if ($requerida && ! isset($mapa[$campo])) {
                throw ValidationException::withMessages(['archivo' => sprintf('La hoja «%s» no tiene la columna «%s».', $hoja->getTitle(), str_replace(' ', '_', $sinonimos[0]))]);
            }
        }

        $filas = [];

        foreach ($datos as $indice => $renglon) {
            if ($indice <= $indiceEncabezado || ! is_array($renglon)) {
                continue;
            }

            $fila = ['_fila' => (int) $indice + 1];
            $conDato = false;

            foreach ($mapa as $campo => $columna) {
                $valor = $renglon[$columna] ?? null;
                $valor = is_string($valor) ? trim($valor) : $valor;
                $fila[$campo] = $valor === '' ? null : $valor;
                $conDato = $conDato || $fila[$campo] !== null;
            }

            if ($conDato) {
                $filas[] = $fila;
            }
        }

        return ['filas' => $filas, 'columnas' => $originales, 'hoja' => $hoja->getTitle()];
    }

    private function buscarHoja(Spreadsheet $libro, string $nombre): ?Worksheet
    {
        $buscado = Normalizador::clave($nombre);

        foreach ($libro->getWorksheetIterator() as $hoja) {
            $clave = Normalizador::clave($hoja->getTitle());

            // ROTACION_2026 acepta otro año en el nombre (ROTACION_2027…).
            if ($clave === $buscado || ($nombre === self::HOJA_ROTACION && str_starts_with($clave, 'ROTACION'))) {
                return $hoja;
            }
        }

        return null;
    }

    /**
     * Renglón de encabezados (el que reconoce más columnas en las primeras
     * 15 filas) y campo => índice de columna.
     *
     * @param  array<int, mixed>  $datos
     * @param  array<string, array{0: list<string>, 1: bool}>  $especificacion
     * @return array{0: int, 1: array<string, int>, 2: array<string, string>}
     */
    private function encabezados(array $datos, array $especificacion): array
    {
        $mejor = [-1, [], []];

        foreach (array_slice($datos, 0, 15, true) as $indice => $renglon) {
            if (! is_array($renglon)) {
                continue;
            }

            $mapa = [];
            $originales = [];

            foreach ($renglon as $columna => $celda) {
                $texto = is_scalar($celda) ? trim((string) $celda) : '';

                if ($texto === '') {
                    continue;
                }

                $clave = Normalizador::clave($texto);

                foreach ($especificacion as $campo => [$sinonimos]) {
                    if (! isset($mapa[$campo]) && in_array($clave, $sinonimos, true)) {
                        $mapa[$campo] = (int) $columna;
                        $originales[$campo] = $texto;

                        break;
                    }
                }
            }

            if (count($mapa) > count($mejor[1])) {
                $mejor = [(int) $indice, $mapa, $originales];
            }
        }

        return $mejor;
    }

    /**
     * COLABORADORES → estructura canónica del PlanificadorMigracion.
     *
     * @param  list<array<string, mixed>>  $filas
     * @param  array<string, string>  $columnas
     * @return array{filas: list<array<string, mixed>>, columnas: array<string, string>, sin_reconocer: list<string>, hoja: string, formato: string}
     */
    private function colaboradores(array $filas, array $columnas): array
    {
        $empresaDefault = (string) config('expedientes.migracion_inicial.empresa', 'Mr. Lana');
        $normalizadas = [];

        foreach ($filas as $f) {
            $o = [];

            foreach (['clave', 'nombre', 'apellido_paterno', 'apellido_materno', 'nombre_completo', 'curp', 'rfc', 'nss', 'sexo', 'estatus', 'empresa', 'sucursal', 'departamento', 'puesto', 'correo', 'telefono'] as $campo) {
                $o[$campo] = isset($f[$campo]) && is_scalar($f[$campo]) ? $this->cadena($f[$campo]) : null;
            }

            $o['fecha_nacimiento'] = $f['fecha_nacimiento'] ?? null;
            $o['fecha_alta'] = $f['fecha_alta'] ?? null;
            $o['empresa'] ??= $empresaDefault;
            $o['hoja_origen'] = self::HOJA_COLABORADORES;

            $fila = NormalizadorFilaMigracion::normalizarFila($o, (int) $f['_fila']);
            $fila['correo_corporativo'] = Normalizador::correo($this->texto($f['correo_corporativo'] ?? null));
            $fila['telefono_corporativo'] = Normalizador::telefono($this->texto($f['telefono_corporativo'] ?? null));
            $normalizadas[] = $fila;
        }

        return ['filas' => $normalizadas, 'columnas' => $columnas, 'sin_reconocer' => [], 'hoja' => self::HOJA_COLABORADORES, 'formato' => 'maestra'];
    }

    /**
     * Tipo de una fila de PENDIENTES: por la columna TIPO/CATEGORIA/ESTADO o,
     * si no existe, buscando el token en cualquier celda de la fila.
     *
     * @param  array<string, mixed>  $f
     */
    private function tipoPendiente(array $f): ?string
    {
        $candidatos = isset($f['tipo']) ? [$f['tipo']] : array_values($f);

        foreach ($candidatos as $valor) {
            $clave = str_replace(' ', '_', Normalizador::clave(is_scalar($valor) ? (string) $valor : ''));

            foreach (self::TIPOS_PENDIENTE as $tipo) {
                if ($clave === $tipo) {
                    return $tipo;
                }
            }
        }

        return isset($f['tipo']) && is_scalar($f['tipo']) ? $this->texto($f['tipo']) : null;
    }

    private function cadena(mixed $valor): string
    {
        if (is_float($valor) && floor($valor) === $valor) {
            return (string) (int) $valor;
        }

        return is_scalar($valor) ? trim((string) $valor) : '';
    }

    private function texto(mixed $valor): ?string
    {
        return is_scalar($valor) ? Normalizador::texto($this->cadena($valor)) : null;
    }

    private function clave(mixed $valor): ?string
    {
        $texto = $this->texto($valor);

        return $texto !== null ? (string) preg_replace('/\.0+$/', '', $texto) : null;
    }

    private function numero(mixed $valor): ?float
    {
        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }

        $texto = $this->texto($valor);

        if ($texto === null) {
            return null;
        }

        $texto = str_replace([',', '$', '%', ' '], '', $texto);

        return is_numeric($texto) ? (float) $texto : null;
    }

    private function entero(mixed $valor): ?int
    {
        $numero = $this->numero($valor);

        return $numero !== null ? (int) round($numero) : null;
    }

    private function mes(mixed $valor): ?int
    {
        $numero = $this->numero($valor);

        if ($numero !== null) {
            $mes = (int) $numero;

            return $mes >= 1 && $mes <= 12 ? $mes : null;
        }

        return self::MESES[Normalizador::clave($this->texto($valor))] ?? null;
    }
}
