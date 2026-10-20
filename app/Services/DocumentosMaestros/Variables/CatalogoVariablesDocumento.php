<?php

namespace App\Services\DocumentosMaestros\Variables;

use App\Enums\TransformacionTexto;
use App\Services\DocumentosMaestros\DatosDocumentoService;

/**
 * Catálogo CANÓNICO y visual de los datos con los que se llena un
 * documento maestro (los que produce DatosDocumentoService::resolver()).
 * Es lo que ve RH en el editor: grupos con nombres humanos («Nombre
 * completo», «Contacto de emergencia: teléfono»), nunca claves técnicas.
 *
 *  - `legal`: dato que, si el documento lo pide y falta, BLOQUEA la
 *    generación (nunca se completa con un supuesto).
 *  - `sinonimos`: textos del formato que sugieren este dato junto a un
 *    espacio en blanco («CURP:», «Número de seguridad social»). Sugerir no
 *    es decidir: RH confirma cada sugerencia.
 *  - VARIANTES: claves heredadas del mapa en config (`*_mayusculas`…) que
 *    equivalen a «dato base + transformación». Se siguen resolviendo igual
 *    (compatibilidad), pero el editor las muestra como el dato base.
 *
 * @phpstan-type Variable array{clave: string, etiqueta: string, grupo: string, ejemplo: string, sinonimos: list<string>, legal: bool, tipo: string}
 */
class CatalogoVariablesDocumento
{
    /** @var array<string, string> */
    public const GRUPOS = [
        'colaborador' => 'Datos del colaborador',
        'domicilio' => 'Domicilio',
        'emergencia' => 'Contacto de emergencia',
        'beneficiario' => 'Beneficiario',
        'laboral' => 'Datos laborales',
        'empresa' => 'Empresa',
        'contrato' => 'Contrato',
        'documento' => 'Fecha y lugar del documento',
        'solicitud' => 'Solicitud / permiso',
        'prestamo' => 'Préstamo',
        'cierre' => 'Cierre laboral',
        'evaluacion' => 'Evaluación',
        'acta' => 'Datos del acto (se capturan al generar)',
    ];

    /**
     * clave => [etiqueta, grupo, ejemplo, sinónimos, legal, tipo]
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: list<string>, 4: bool, 5: string}>
     */
    private const VARIABLES = [
        // ── Colaborador ──
        'nombre_completo' => ['Nombre completo', 'colaborador', 'José Francisco Hernández González', ['nombre del trabajador', 'nombre del colaborador', 'nombre completo', 'el trabajador', 'nombre del empleado', 'nombre'], true, 'texto'],
        'nombre' => ['Nombre(s)', 'colaborador', 'José Francisco', ['nombres', 'nombre s'], true, 'texto'],
        'apellidos' => ['Apellidos', 'colaborador', 'Hernández González', ['apellidos', 'apellido paterno y materno'], true, 'texto'],
        'fecha_nacimiento_larga' => ['Fecha de nacimiento', 'colaborador', '15 de marzo de 1990', ['fecha de nacimiento'], true, 'fecha'],
        'edad' => ['Edad («35 años»)', 'colaborador', '35 años', ['edad', 'años de edad'], true, 'texto'],
        'edad_numero' => ['Edad (solo número)', 'colaborador', '35', [], true, 'numero'],
        'sexo' => ['Sexo', 'colaborador', 'Masculino', ['sexo', 'genero'], true, 'texto'],
        'nacionalidad' => ['Nacionalidad', 'colaborador', 'Mexicana', ['nacionalidad', 'de nacionalidad'], true, 'texto'],
        'estado_civil' => ['Estado civil', 'colaborador', 'Soltero', ['estado civil'], true, 'texto'],
        'lugar_nacimiento' => ['Lugar de nacimiento', 'colaborador', 'Cuernavaca, Morelos', ['lugar de nacimiento', 'haber nacido en'], true, 'texto'],
        'curp' => ['CURP', 'colaborador', 'HEGJ900315HMSRNS09', ['curp', 'clave unica de registro de poblacion'], true, 'texto'],
        'rfc' => ['RFC', 'colaborador', 'HEGJ900315AB1', ['rfc', 'registro federal de contribuyentes'], true, 'texto'],
        'nss' => ['Número de seguridad social (NSS)', 'colaborador', '12345678901', ['nss', 'numero de seguridad social', 'no de afiliacion', 'imss'], true, 'texto'],
        'clave_elector' => ['INE / clave de elector', 'colaborador', 'HRGNJS90031517H400', ['clave de elector', 'clavel de elector', 'ine', 'credencial para votar'], true, 'texto'],
        'profesion' => ['Profesión u oficio', 'colaborador', 'Licenciado en Administración', ['profesion', 'oficio', 'ocupacion'], false, 'texto'],
        'telefono' => ['Teléfono personal', 'colaborador', '7771234567', ['telefono', 'celular', 'numero celular', 'numero de celular', 'tel'], true, 'texto'],
        'correo' => ['Correo personal', 'colaborador', 'jose.hernandez@correo.com', ['correo electronico', 'correo', 'e mail', 'email'], true, 'texto'],
        // ── Domicilio ──
        'domicilio' => ['Calle y número', 'domicilio', 'Av. Emiliano Zapata 1234, interior 5', ['domicilio', 'con domicilio en', 'calle'], true, 'texto'],
        'domicilio_calle' => ['Calle y número (sin colonia/municipio/estado/C.P. ya capturados)', 'domicilio', 'Av. Emiliano Zapata 1234, interior 5', ['calle'], true, 'texto'],
        'domicilio_numero_exterior' => ['Número exterior', 'domicilio', '1234', ['numero exterior', 'no exterior', 'num ext'], false, 'texto'],
        'domicilio_numero_interior' => ['Número interior', 'domicilio', '5', ['numero interior', 'no interior', 'num int'], false, 'texto'],
        'domicilio_colonia' => ['Colonia', 'domicilio', 'Lomas de la Selva', ['colonia'], false, 'texto'],
        'domicilio_municipio' => ['Municipio', 'domicilio', 'Cuernavaca', ['municipio', 'alcaldia'], false, 'texto'],
        'domicilio_estado' => ['Estado', 'domicilio', 'Morelos', ['entidad federativa'], false, 'texto'],
        'domicilio_cp' => ['C.P.', 'domicilio', '62270', ['codigo postal', 'c p', 'cp'], false, 'texto'],
        'domicilio_completo' => ['Domicilio completo', 'domicilio', 'Av. Emiliano Zapata 1234, col. Lomas de la Selva, Cuernavaca, Morelos, C.P. 62270', ['domicilio completo', 'domicilio particular'], true, 'texto'],
        // ── Contacto de emergencia (cada dato explícito; nunca un alias ambiguo) ──
        'contacto_emergencia_nombre' => ['Contacto de emergencia: nombre', 'emergencia', 'María López', ['en caso de emergencia avisar a', 'nombre del contacto'], false, 'texto'],
        'contacto_emergencia_parentesco' => ['Contacto de emergencia: parentesco', 'emergencia', 'Madre', ['parentesco'], false, 'texto'],
        'contacto_emergencia_telefono' => ['Contacto de emergencia: teléfono', 'emergencia', '7771234567', ['telefono de emergencia', 'telefono del contacto'], false, 'texto'],
        'contacto_emergencia_direccion' => ['Contacto de emergencia: dirección', 'emergencia', 'Calle Ejemplo 123, Cuernavaca', ['direccion del contacto', 'domicilio del contacto'], false, 'texto'],
        'contacto_emergencia_completo' => ['Contacto de emergencia completo', 'emergencia', 'María López (Madre) · Tel. 7771234567 · Calle Ejemplo 123', ['contacto de emergencia', 'en caso de emergencia'], false, 'texto'],
        // ── Beneficiario ──
        'beneficiario_nombre' => ['Beneficiario', 'beneficiario', 'Ana Hernández López', ['beneficiario', 'nombre del beneficiario', 'se deberan cubrir a'], true, 'texto'],
        'beneficiario_parentesco' => ['Parentesco del beneficiario', 'beneficiario', 'Esposa', ['parentesco del beneficiario', 'quien guarda parentesco de'], true, 'texto'],
        // ── Laboral ──
        'numero_empleado' => ['Número de empleado', 'laboral', 'EMP-0123', ['numero de empleado', 'no de empleado', 'numero de nomina'], false, 'texto'],
        'empresa_nombre' => ['Empresa (nombre comercial)', 'laboral', 'MR. LANA', [], false, 'texto'],
        'sucursal_nombre' => ['Sucursal', 'laboral', 'Cuernavaca Centro', ['sucursal', 'centro de trabajo'], false, 'texto'],
        'departamento' => ['Departamento', 'laboral', 'Cobranza', ['departamento', 'area'], false, 'texto'],
        'puesto' => ['Puesto', 'laboral', 'Gestor de cobranza', ['puesto', 'cargo', 'categoria'], false, 'texto'],
        'jefe_inmediato' => ['Jefe inmediato', 'laboral', 'Laura Méndez Ruiz', ['jefe inmediato', 'supervisor', 'gerente'], false, 'texto'],
        'fecha_ingreso_larga' => ['Fecha de ingreso', 'laboral', '4 de octubre de 2026', ['fecha de ingreso', 'fecha de alta'], false, 'fecha'],
        'salario_diario' => ['Salario diario', 'laboral', '300.00', ['salario diario'], false, 'moneda'],
        'salario_diario_integrado' => ['Salario diario integrado', 'laboral', '313.56', ['salario diario integrado', 'sdi'], false, 'moneda'],
        'sueldo_mensual_numero' => ['Sueldo mensual', 'laboral', '9,000.00', ['salario mensual', 'sueldo mensual', 'salario mensual bruto'], false, 'moneda'],
        'sueldo_mensual_numero_letra' => ['Sueldo mensual con letra', 'laboral', '9,000.00, nueve mil pesos', [], false, 'moneda'],
        // ── Empresa ──
        'empresa_razon_social' => ['Razón social', 'empresa', 'Productos y Servicios Mr. Lana, S.A.P.I. de C.V.', ['razon social', 'la empresa', 'el patron'], true, 'texto'],
        'empresa_rfc' => ['RFC de la empresa', 'empresa', 'PSM150101AB1', ['rfc de la empresa', 'rfc del patron'], true, 'texto'],
        'empresa_registro_patronal' => ['Registro patronal', 'empresa', 'A1234567101', ['registro patronal'], true, 'texto'],
        // No bloquea nunca: la firma de la empresa se escribe y firma a mano
        // («Nombre y firma del representante legal»).
        'representante_legal' => ['Representante legal', 'empresa', 'Lesli Maribel Rodríguez Herrera', ['representante legal', 'representada por'], false, 'texto'],
        'representante_legal_cargo' => ['Cargo del representante', 'empresa', 'Representante Legal', [], false, 'texto'],
        'empresa_domicilio' => ['Domicilio del patrón', 'empresa', 'Subida al Club 114, col. Reforma, Cuernavaca, Morelos', ['domicilio fiscal', 'domicilio de la empresa', 'domicilio del patron'], true, 'texto'],
        'empresa_cp' => ['C.P. fiscal', 'empresa', '62260', [], true, 'texto'],
        // ── Contrato ──
        'fecha_inicio_contrato_larga' => ['Fecha de inicio del contrato', 'contrato', '4 de octubre de 2026', ['fecha de inicio', 'a partir del dia'], false, 'fecha'],
        'fecha_fin_contrato_larga' => ['Fecha de término del contrato', 'contrato', '3 de diciembre de 2026', ['fecha de termino', 'culminara el dia'], false, 'fecha'],
        'duracion_letra' => ['Duración del contrato', 'contrato', 'dos meses', ['duracion', 'por un periodo de'], false, 'texto'],
        'lugar_y_fecha_inicio_contrato' => ['Lugar y fecha de firma del contrato', 'contrato', 'Cuernavaca, Morelos, el 4 de octubre de 2026', ['lo firman en'], false, 'texto'],
        // ── Documento ──
        'lugar_firma' => ['Lugar de firma', 'documento', 'Cuernavaca, Morelos', ['lugar', 'ciudad'], false, 'texto'],
        'fecha_documento_larga' => ['Fecha del documento', 'documento', '12 de octubre de 2026', ['fecha'], false, 'fecha'],
    ];

    /**
     * Claves heredadas = dato base + transformación (compatibilidad con el
     * mapa en config). Siguen existiendo en el resolver.
     *
     * @var array<string, array{0: string, 1: TransformacionTexto|null}>
     */
    private const VARIANTES = [
        'nombre_completo_mayusculas' => ['nombre_completo', TransformacionTexto::Mayusculas],
        'nacionalidad_mayusculas' => ['nacionalidad', TransformacionTexto::Mayusculas],
        'sexo_mayusculas' => ['sexo', TransformacionTexto::Mayusculas],
        'edad_mayusculas' => ['edad', TransformacionTexto::Mayusculas],
        'estado_civil_mayusculas' => ['estado_civil', TransformacionTexto::Mayusculas],
        'domicilio_mayusculas' => ['domicilio', TransformacionTexto::Mayusculas],
        'domicilio_calle_mayusculas' => ['domicilio_calle', TransformacionTexto::Mayusculas],
        'contacto_emergencia_nombre_mayusculas' => ['contacto_emergencia_nombre', TransformacionTexto::Mayusculas],
        'puesto_mayusculas' => ['puesto', TransformacionTexto::Mayusculas],
        'departamento_mayusculas' => ['departamento', TransformacionTexto::Mayusculas],
        'sucursal_nombre_mayusculas' => ['sucursal_nombre', TransformacionTexto::Mayusculas],
        'empresa_razon_social_mayusculas' => ['empresa_razon_social', TransformacionTexto::Mayusculas],
        'representante_legal_mayusculas' => ['representante_legal', TransformacionTexto::Mayusculas],
        'gerente_nombre_mayusculas' => ['jefe_inmediato', TransformacionTexto::Mayusculas],
        'fecha_inicio_contrato_larga_mayusculas' => ['fecha_inicio_contrato_larga', TransformacionTexto::Mayusculas],
        'duracion_capacitacion_letra' => ['duracion_letra', null],
        'duracion_capacitacion_letra_mayusculas' => ['duracion_letra', TransformacionTexto::Mayusculas],
        'duracion_capacitacion_letra_titulo' => ['duracion_letra', TransformacionTexto::Titulo],
        'lugar_documento' => ['lugar_firma', null],
        'ciudad_firma' => ['lugar_firma', null],
    ];

    /**
     * Claves que ya NO existen porque eran ambiguas: decían «contacto de
     * emergencia» y entregaban solo el nombre. Un mapa que las use se
     * reporta como dato desconocido (debe elegirse el dato exacto).
     */
    public const RETIRADAS = ['contacto_emergencia', 'contacto_emergencia_mayusculas'];

    /** @var list<string>|null */
    private ?array $clavesProceso = null;

    public function __construct(private readonly DatosDocumentoService $datos) {}

    /**
     * @return list<Variable>
     */
    public function variables(): array
    {
        $lista = [];

        foreach (self::VARIABLES as $clave => [$etiqueta, $grupo, $ejemplo, $sinonimos, $legal, $tipo]) {
            $lista[] = ['clave' => $clave, 'etiqueta' => $etiqueta, 'grupo' => $grupo, 'ejemplo' => $ejemplo, 'sinonimos' => $sinonimos, 'legal' => $legal, 'tipo' => $tipo];
        }

        // Datos de proceso que ya usan los masters (solicitud, préstamo,
        // cierre, evaluación, acta…) y no están en la lista curada.
        foreach ($this->clavesDeProceso() as $clave) {
            if (isset(self::VARIABLES[$clave]) || isset(self::VARIANTES[$clave])) {
                continue;
            }

            $lista[] = ['clave' => $clave, 'etiqueta' => $this->etiquetaProceso($clave), 'grupo' => $this->grupoProceso($clave), 'ejemplo' => '', 'sinonimos' => [], 'legal' => false, 'tipo' => str_starts_with($clave, 'marca_') || str_starts_with($clave, 'resultado_') ? 'casilla' : 'texto'];
        }

        return $lista;
    }

    /**
     * Variables agrupadas para el panel del editor.
     *
     * @return list<array{clave: string, etiqueta: string, variables: list<Variable>}>
     */
    public function agrupadas(): array
    {
        $porGrupo = [];

        foreach ($this->variables() as $variable) {
            $porGrupo[$variable['grupo']][] = $variable;
        }

        $grupos = [];

        foreach (self::GRUPOS as $clave => $etiqueta) {
            if (isset($porGrupo[$clave])) {
                $grupos[] = ['clave' => $clave, 'etiqueta' => $etiqueta, 'variables' => $porGrupo[$clave]];
            }
        }

        return $grupos;
    }

    public function existe(string $clave): bool
    {
        if (in_array($clave, self::RETIRADAS, true)) {
            return false;
        }

        return isset(self::VARIABLES[$clave]) || isset(self::VARIANTES[$clave]) || in_array($clave, $this->clavesDeProceso(), true);
    }

    /**
     * @return Variable|null
     */
    public function variable(string $clave): ?array
    {
        foreach ($this->variables() as $variable) {
            if ($variable['clave'] === $clave) {
                return $variable;
            }
        }

        return null;
    }

    /**
     * Dato base y transformación de una clave (variante heredada o no).
     *
     * @return array{0: string, 1: TransformacionTexto|null}
     */
    public function base(string $clave): array
    {
        return self::VARIANTES[$clave] ?? [$clave, null];
    }

    /**
     * Nombre humano de cualquier clave, variantes incluidas
     * («Nombre completo · MAYÚSCULAS»).
     */
    public function etiqueta(string $clave): string
    {
        [$base, $transformacion] = $this->base($clave);
        $variable = self::VARIABLES[$base] ?? null;
        $etiqueta = $variable !== null ? $variable[0] : $this->etiquetaProceso($base);

        return $transformacion !== null ? sprintf('%s · %s', $etiqueta, $transformacion->etiqueta()) : $etiqueta;
    }

    public function esLegal(string $clave): bool
    {
        [$base] = $this->base($clave);

        return (self::VARIABLES[$base][4] ?? false) === true;
    }

    /**
     * Claves que usan los masters del registro heredado (config) y no son
     * datos del colaborador: se ofrecen tal cual para no perder ninguna.
     *
     * @return list<string>
     */
    public function clavesDeProceso(): array
    {
        if ($this->clavesProceso !== null) {
            return $this->clavesProceso;
        }

        $encontradas = [];

        foreach ((array) config('documentos_maestros.documentos', []) as $definicion) {
            if (! is_array($definicion)) {
                continue;
            }

            foreach ([...(array) ($definicion['reglas'] ?? []), ...(array) ($definicion['campos'] ?? [])] as $regla) {
                if (is_array($regla) && is_string($regla['campo'] ?? null)) {
                    $encontradas[$regla['campo']] = true;
                }
            }

            foreach ((array) ($definicion['reglas_por_version'] ?? []) as $reglas) {
                foreach ((array) $reglas as $regla) {
                    if (is_array($regla) && is_string($regla['campo'] ?? null)) {
                        $encontradas[$regla['campo']] = true;
                    }
                }
            }
        }

        $claves = array_values(array_diff(array_map('strval', array_keys($encontradas)), self::RETIRADAS));
        sort($claves);

        return $this->clavesProceso = $claves;
    }

    private function etiquetaProceso(string $clave): string
    {
        $fuente = $this->datos->fuente($clave);
        $base = $fuente['etiqueta'];

        // La etiqueta de la fuente es la del dato BASE («Solicitud»): se
        // completa con el nombre del campo para distinguir los de un mismo
        // proceso («Solicitud: fecha permiso texto»).
        if ($fuente['base'] !== $clave) {
            return sprintf('%s: %s', $base, str_replace('_', ' ', $clave));
        }

        return $base;
    }

    private function grupoProceso(string $clave): string
    {
        $fuente = $this->datos->fuente($clave);

        return match (true) {
            $fuente['fuente'] === 'manual' => 'acta',
            in_array($fuente['base'], ['solicitud', 'prestamo', 'cierre', 'evaluacion', 'contrato'], true) => $fuente['base'],
            str_starts_with($clave, 'criterio_'), str_starts_with($clave, 'resultado_') => 'evaluacion',
            str_starts_with($clave, 'marca_') || str_contains($clave, 'permiso') => 'solicitud',
            str_contains($clave, 'prestamo') || str_contains($clave, 'pago') => 'prestamo',
            str_contains($clave, 'baja') || str_contains($clave, 'acta') || str_contains($clave, 'renuncia') => 'cierre',
            str_contains($clave, 'empresa') || str_contains($clave, 'representante') => 'empresa',
            str_contains($clave, 'sucursal') || str_contains($clave, 'puesto') => 'laboral',
            str_contains($clave, 'fecha') || str_contains($clave, 'lugar') => 'documento',
            default => 'colaborador',
        };
    }
}
