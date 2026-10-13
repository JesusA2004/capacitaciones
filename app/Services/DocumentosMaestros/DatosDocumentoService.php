<?php

namespace App\Services\DocumentosMaestros;

use App\Enums\EstadoCivil;
use App\Enums\FuenteDomicilioPatron;
use App\Enums\Genero;
use App\Enums\ResultadoEvaluacion;
use App\Enums\TipoSolicitudInterna;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Formatos\Variables\NumeroALetras;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Datos con los que se llena un documento maestro: SIEMPRE los que PEOPLE
 * ya conoce del colaborador y del proceso (nada se captura dos veces), en
 * la forma exacta en que el formato los pide (mayúsculas, fecha en letra,
 * importe con letra, casillas ☒/☐…).
 *
 * El resultado es el SNAPSHOT que se guarda en el GeneratedDocument: un
 * contrato emitido en octubre conserva el sueldo de octubre aunque después
 * cambie.
 *
 * Cada campo sabe de dónde viene (fuente()): si un dato requerido falta,
 * el motor no genera basura — devuelve la lista de faltantes con la
 * columna que hay que completar (colaborador, sucursal, empresa o dato del
 * acto), para que la UI pida solo eso.
 */
class DatosDocumentoService
{
    private const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    private const NUMEROS = [1 => 'un', 2 => 'dos', 3 => 'tres', 4 => 'cuatro', 5 => 'cinco', 6 => 'seis', 7 => 'siete', 8 => 'ocho', 9 => 'nueve', 10 => 'diez', 11 => 'once', 12 => 'doce'];

    /**
     * Origen de los datos base que la UI puede pedir completar. clave =>
     * [fuente, columna, etiqueta, tipo de captura].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private const BASE = [
        'nombre' => ['colaborador', 'name', 'Nombre', 'texto'],
        'apellidos' => ['colaborador', 'apellidos', 'Apellidos', 'texto'],
        'nacionalidad' => ['colaborador', 'nacionalidad', 'Nacionalidad', 'texto'],
        'genero' => ['colaborador', 'genero', 'Sexo', 'genero'],
        'fecha_nacimiento' => ['colaborador', 'fecha_nacimiento', 'Fecha de nacimiento', 'fecha'],
        'estado_civil' => ['colaborador', 'estado_civil', 'Estado civil', 'estado_civil'],
        'lugar_nacimiento' => ['colaborador', 'lugar_nacimiento', 'Lugar de nacimiento', 'texto'],
        'clave_elector' => ['colaborador', 'clave_elector', 'Clave de elector (INE)', 'texto'],
        'profesion' => ['colaborador', 'profesion', 'Profesión u oficio', 'texto'],
        'curp' => ['colaborador', 'curp', 'CURP', 'texto'],
        'rfc' => ['colaborador', 'rfc', 'RFC', 'texto'],
        'nss' => ['colaborador', 'nss', 'Número de seguridad social', 'texto'],
        'telefono' => ['colaborador', 'telefono', 'Número de celular', 'texto'],
        'correo' => ['colaborador', 'correo_personal', 'Correo electrónico', 'correo'],
        'domicilio' => ['colaborador', 'domicilio', 'Domicilio', 'texto'],
        'domicilio_colonia' => ['colaborador', 'domicilio_colonia', 'Colonia del domicilio', 'texto'],
        'domicilio_municipio' => ['colaborador', 'domicilio_municipio', 'Municipio del domicilio', 'texto'],
        'domicilio_estado' => ['colaborador', 'domicilio_estado', 'Estado del domicilio', 'texto'],
        'domicilio_cp' => ['colaborador', 'domicilio_cp', 'C.P. del domicilio', 'texto'],
        'beneficiario_nombre' => ['colaborador', 'beneficiario_nombre', 'Beneficiario: nombre', 'texto'],
        'beneficiario_parentesco' => ['colaborador', 'beneficiario_parentesco', 'Beneficiario: parentesco', 'texto'],
        'contacto_emergencia_nombre' => ['colaborador', 'contacto_emergencia_nombre', 'Contacto de emergencia: nombre', 'texto'],
        'contacto_emergencia_parentesco' => ['colaborador', 'contacto_emergencia_parentesco', 'Contacto de emergencia: parentesco', 'texto'],
        'contacto_emergencia_telefono' => ['colaborador', 'contacto_emergencia_telefono', 'Contacto de emergencia: teléfono', 'texto'],
        'contacto_emergencia_direccion' => ['colaborador', 'contacto_emergencia_direccion', 'Contacto de emergencia: domicilio', 'texto'],
        'fecha_ingreso' => ['colaborador', 'fecha_ingreso', 'Fecha de ingreso', 'fecha'],
        'sueldo_mensual' => ['colaborador', 'sueldo_mensual', 'Sueldo mensual', 'moneda'],
        'salario_diario' => ['colaborador', 'salario_diario', 'Salario diario', 'moneda'],
        'salario_diario_integrado' => ['colaborador', 'salario_diario_integrado', 'Salario diario integrado', 'moneda'],
        'puesto' => ['colaborador', 'puesto_id', 'Puesto', 'relacion'],
        'departamento' => ['colaborador', 'departamento_id', 'Departamento', 'relacion'],
        'sucursal' => ['colaborador', 'sucursal_principal_id', 'Sucursal', 'relacion'],
        'jefe' => ['colaborador', 'jefe_id', 'Jefe inmediato', 'relacion'],
        'sucursal_direccion' => ['sucursal', 'direccion', 'Domicilio de la sucursal', 'texto'],
        'sucursal_ciudad' => ['sucursal', 'ciudad', 'Ciudad de la sucursal', 'texto'],
        'sucursal_estado' => ['sucursal', 'estado', 'Estado de la sucursal', 'texto'],
        'sucursal_municipio' => ['sucursal', 'municipio', 'Municipio de la sucursal', 'texto'],
        'sucursal_cp' => ['sucursal', 'codigo_postal', 'C.P. de la sucursal', 'texto'],
        // Datos del patrón: se capturan en Configuración → Empresa (no desde
        // el documento).
        'empresa_razon_social' => ['empresa', 'razon_social', 'Razón social de la empresa', 'empresa'],
        'empresa_rfc' => ['empresa', 'rfc', 'RFC de la empresa', 'empresa'],
        'empresa_registro_patronal' => ['empresa', 'registro_patronal', 'Registro patronal de la empresa', 'empresa'],
        'empresa_cp' => ['empresa', 'codigo_postal_fiscal', 'C.P. fiscal de la empresa', 'empresa'],
        'empresa_domicilio' => ['empresa', 'domicilio_fiscal', 'Domicilio fiscal de la empresa', 'empresa'],
        'representante_legal' => ['empresa', 'representante_legal_nombre', 'Representante legal de la empresa', 'empresa'],
        'representante_legal_cargo' => ['empresa', 'representante_legal_cargo', 'Cargo del representante legal', 'empresa'],
        'contrato' => ['proceso', 'contrato', 'Contrato laboral (inicio, fin y sueldo)', 'proceso'],
        'evaluacion' => ['proceso', 'evaluacion', 'Evaluación capturada', 'proceso'],
        'solicitud' => ['proceso', 'solicitud', 'Solicitud', 'proceso'],
        'prestamo' => ['proceso', 'prestamo', 'Préstamo autorizado', 'proceso'],
        'cierre' => ['proceso', 'cierre', 'Cierre laboral', 'proceso'],
        'testigo_1_nombre' => ['manual', 'testigo_1_nombre', 'Testigo 1: nombre', 'texto'],
        'testigo_1_cargo' => ['manual', 'testigo_1_cargo', 'Testigo 1: cargo', 'texto'],
        'testigo_2_nombre' => ['manual', 'testigo_2_nombre', 'Testigo 2: nombre', 'texto'],
        'testigo_2_cargo' => ['manual', 'testigo_2_cargo', 'Testigo 2: cargo', 'texto'],
        'rh_nombre' => ['manual', 'rh_nombre', 'Representante de RH: nombre', 'texto'],
        'rh_cargo' => ['manual', 'rh_cargo', 'Representante de RH: cargo', 'texto'],
        'jefe_nombre' => ['manual', 'jefe_nombre', 'Jefe inmediato: nombre', 'texto'],
        'jefe_cargo' => ['manual', 'jefe_cargo', 'Jefe inmediato: cargo', 'texto'],
        'hora_acta' => ['manual', 'hora_acta', 'Hora del acta', 'hora'],
        'lugar_acta' => ['manual', 'lugar_acta', 'Ciudad donde se levanta el acta', 'texto'],
        'domicilio_acta' => ['manual', 'domicilio_acta', 'Domicilio donde se levanta el acta', 'texto'],
    ];

    /**
     * Campo del documento → dato base del que depende.
     *
     * @var array<string, string>
     */
    private const DEPENDE = [
        'nombre_completo' => 'nombre', 'nombre_completo_mayusculas' => 'nombre',
        'fecha_nacimiento_larga' => 'fecha_nacimiento',
        'domicilio_completo' => 'domicilio',
        'contacto_emergencia_completo' => 'contacto_emergencia_nombre',
        'empresa_razon_social_mayusculas' => 'empresa_razon_social', 'representante_legal_mayusculas' => 'representante_legal',
        'departamento_mayusculas' => 'departamento', 'jefe_inmediato' => 'jefe',
        'nacionalidad_mayusculas' => 'nacionalidad',
        'sexo' => 'genero', 'sexo_mayusculas' => 'genero',
        'edad' => 'fecha_nacimiento', 'edad_numero' => 'fecha_nacimiento', 'edad_mayusculas' => 'fecha_nacimiento',
        'estado_civil_mayusculas' => 'estado_civil',
        'domicilio_mayusculas' => 'domicilio',
        'contacto_emergencia_nombre_mayusculas' => 'contacto_emergencia_nombre',
        'puesto_mayusculas' => 'puesto',
        'sucursal_nombre' => 'sucursal', 'sucursal_nombre_mayusculas' => 'sucursal',
        'sucursal_domicilio' => 'sucursal_direccion', 'sucursal_domicilio_con_nombre' => 'sucursal_direccion',
        'sucursal_direccion_mayusculas' => 'sucursal_direccion', 'sucursal_municipio_mayusculas' => 'sucursal_municipio',
        'lugar_firma' => 'sucursal_ciudad', 'lugar_documento' => 'sucursal_ciudad', 'ciudad_firma' => 'sucursal_ciudad',
        'fecha_ingreso_larga' => 'fecha_ingreso', 'fecha_ingreso_dia_mes_anio' => 'fecha_ingreso',
        'sueldo_mensual_numero' => 'sueldo_mensual', 'sueldo_mensual_numero_letra' => 'sueldo_mensual',
        'fecha_inicio_contrato_larga' => 'contrato', 'fecha_inicio_contrato_larga_mayusculas' => 'contrato', 'fecha_inicio_contrato_dia' => 'contrato',
        'fecha_fin_contrato_larga' => 'contrato', 'duracion_letra' => 'contrato', 'duracion_letra_capital' => 'contrato',
        'duracion_capacitacion_letra' => 'contrato', 'duracion_capacitacion_letra_mayusculas' => 'contrato', 'duracion_capacitacion_letra_titulo' => 'contrato',
        'lugar_y_fecha_inicio_contrato' => 'contrato', 'fecha_firma_a_los_inicio_contrato' => 'contrato',
        'ciudad_y_fecha_firma_a_los_inicio_contrato' => 'contrato', 'fecha_firma_dias_del_mes_inicio_contrato' => 'contrato',
        'elaboro_nombre_cargo' => 'evaluacion', 'valido_nombre_cargo' => 'evaluacion', 'fecha_elaboro_larga' => 'evaluacion', 'fecha_valido_larga' => 'evaluacion',
        'fecha_entrega_larga' => 'cierre', 'fecha_notificacion_larga' => 'cierre', 'fecha_acta_larga' => 'cierre',
        'fecha_permiso_texto' => 'solicitud', 'motivo_permiso' => 'solicitud', 'fecha_inicio_permiso_larga' => 'solicitud',
        'monto_prestamo' => 'prestamo', 'fecha_solicitud_prestamo_corta' => 'prestamo',
        'gerente_nombre_mayusculas' => 'jefe',
    ];

    public function __construct(private readonly NumeroALetras $letras) {}

    /**
     * Todos los campos que los masters conocen, ya formateados.
     *
     * @return array<string, string>
     */
    public function resolver(ContextoDocumento $contexto): array
    {
        $c = $contexto->colaborador;
        $c->loadMissing(['sucursalPrincipal.empresa', 'puesto', 'departamento', 'jefe.puesto', 'gerente', 'user']);
        $sucursal = $c->sucursalPrincipal;
        $empresa = $sucursal?->empresa;
        $hoy = $contexto->fechaDocumento();
        $contrato = $contexto->contrato;

        $nombre = $c->nombreCompleto();
        $edad = $c->fecha_nacimiento !== null ? (int) $c->fecha_nacimiento->diffInYears($hoy) : null;
        $sexo = match ($c->genero) {
            Genero::Masculino => 'Masculino',
            Genero::Femenino => 'Femenino',
            default => '',
        };
        $estadoCivil = $c->estado_civil instanceof EstadoCivil ? $c->estado_civil->etiqueta($c->genero) : '';
        $sueldo = $contrato->sueldo_mensual ?? $c->sueldo_mensual;
        $lugar = $this->lugar($sucursal, $empresa);
        $inicio = $contrato?->fecha_inicio;
        $fin = $contrato?->fecha_fin;
        $meses = $this->mesesContrato($contexto);
        $duracion = $meses !== null ? $this->duracion($meses) : '';
        // Datos LEGALES: solo lo capturado. Nunca se completa con un supuesto
        // (nacionalidad «Mexicana», el correo de la cuenta, el representante
        // o el domicilio del registro jurídico): si el documento lo pide y
        // falta, el motor bloquea y pide capturarlo (faltantesDe()).
        $representante = trim((string) $empresa?->representante_legal_nombre);
        $nacionalidad = trim((string) $c->nacionalidad);
        $datos = [
            'nombre_completo' => $nombre,
            'nombre_completo_mayusculas' => $this->mayus($nombre),
            'nombre' => trim((string) $c->name),
            'apellidos' => trim((string) $c->apellidos),
            'fecha_nacimiento_larga' => $this->fechaLarga($c->fecha_nacimiento),
            'nacionalidad' => $nacionalidad,
            'nacionalidad_mayusculas' => $this->mayus($nacionalidad),
            'sexo' => $sexo,
            'sexo_mayusculas' => $this->mayus($sexo),
            'edad' => $edad !== null ? sprintf('%d años', $edad) : '',
            'edad_numero' => $edad !== null ? (string) $edad : '',
            'edad_mayusculas' => $edad !== null ? sprintf('%d AÑOS', $edad) : '',
            'estado_civil' => $estadoCivil,
            'estado_civil_mayusculas' => $this->mayus($estadoCivil),
            'lugar_nacimiento' => (string) $c->lugar_nacimiento,
            'clave_elector' => $this->mayus((string) $c->clave_elector),
            'profesion' => (string) $c->profesion,
            'curp' => $this->mayus((string) $c->curp),
            'rfc' => $this->mayus((string) $c->rfc),
            'nss' => (string) $c->nss,
            'telefono' => (string) $c->telefono,
            // Solo el correo PERSONAL capturado (el de la cuenta de acceso
            // puede ser corporativo o un usuario técnico).
            'correo' => trim((string) $c->correo_personal),
            'domicilio' => (string) $c->domicilio,
            'domicilio_mayusculas' => $this->mayus((string) $c->domicilio),
            'domicilio_colonia' => trim((string) $c->domicilio_colonia),
            'domicilio_municipio' => trim((string) $c->domicilio_municipio),
            'domicilio_estado' => trim((string) $c->domicilio_estado),
            'domicilio_cp' => trim((string) $c->domicilio_cp),
            'domicilio_completo' => $this->domicilioCompleto($c),
            'beneficiario_nombre' => $this->mayus((string) $c->beneficiario_nombre),
            'beneficiario_parentesco' => (string) $c->beneficiario_parentesco,
            // Contacto de emergencia: cada dato por separado y el «completo»
            // explícito. Ya NO existe un alias «contacto_emergencia» (antes
            // entregaba solo el nombre aunque el formato pidiera más).
            'contacto_emergencia_nombre' => (string) $c->contacto_emergencia_nombre,
            'contacto_emergencia_nombre_mayusculas' => $this->mayus((string) $c->contacto_emergencia_nombre),
            'contacto_emergencia_parentesco' => (string) $c->contacto_emergencia_parentesco,
            'contacto_emergencia_telefono' => (string) $c->contacto_emergencia_telefono,
            'contacto_emergencia_direccion' => (string) $c->contacto_emergencia_direccion,
            'contacto_emergencia_completo' => $this->contactoEmergenciaCompleto($c),
            'numero_empleado' => (string) $c->numero_empleado,
            'empresa_nombre' => (string) $empresa?->nombre,
            'puesto' => (string) $c->puesto?->nombre,
            'puesto_mayusculas' => $this->mayus((string) $c->puesto?->nombre),
            'departamento' => (string) $c->departamento?->nombre,
            'departamento_mayusculas' => $this->mayus((string) $c->departamento?->nombre),
            'jefe_inmediato' => (string) ($c->jefe?->nombreCompleto() ?? ''),
            'salario_diario' => $c->salario_diario !== null ? number_format((float) $c->salario_diario, 2) : '',
            'salario_diario_integrado' => $c->salario_diario_integrado !== null ? number_format((float) $c->salario_diario_integrado, 2) : '',
            'sucursal_nombre' => (string) $sucursal?->nombre,
            'sucursal_nombre_mayusculas' => $this->mayus((string) $sucursal?->nombre),
            'sucursal_domicilio' => $this->domicilioSucursal($sucursal),
            'sucursal_domicilio_con_nombre' => $sucursal !== null && $this->domicilioSucursal($sucursal) !== '' ? sprintf('%s (sucursal %s)', $this->domicilioSucursal($sucursal), $sucursal->nombre) : '',
            'sucursal_direccion_mayusculas' => $this->mayus((string) $sucursal?->direccion),
            'sucursal_municipio_mayusculas' => $this->mayus((string) ($sucursal?->municipio ?: $sucursal?->ciudad)),
            'sucursal_estado' => (string) $sucursal?->estado,
            'sucursal_cp' => (string) $sucursal?->codigo_postal,
            // Razón social: la capturada (el nombre comercial no es la razón
            // social legal).
            'empresa_razon_social' => trim((string) $empresa?->razon_social),
            'empresa_razon_social_mayusculas' => $this->mayus(trim((string) $empresa?->razon_social)),
            'empresa_rfc' => $this->mayus(trim((string) $empresa?->rfc)),
            'empresa_registro_patronal' => $this->mayus(trim((string) $empresa?->registro_patronal)),
            'empresa_cp' => trim((string) $empresa?->codigo_postal_fiscal),
            'empresa_domicilio' => $this->domicilioPatron($empresa, $sucursal),
            'representante_legal' => $representante,
            'representante_legal_mayusculas' => $this->mayus($representante),
            'representante_legal_cargo' => trim((string) $empresa?->representante_legal_cargo),
            'lugar_firma' => $lugar,
            'lugar_documento' => $lugar,
            'ciudad_firma' => $lugar,
            'fecha_documento_larga' => $this->fechaLarga($hoy),
            'lugar_y_fecha_documento_corta' => $lugar !== '' ? sprintf('%s a %s', $lugar, $hoy->format('d-m-Y')) : '',
            'fecha_ingreso_larga' => $this->fechaLarga($c->fecha_ingreso),
            'fecha_ingreso_dia_mes_anio' => $c->fecha_ingreso !== null ? sprintf('%d del mes de %s del año %s', $c->fecha_ingreso->day, self::MESES[$c->fecha_ingreso->month - 1], $c->fecha_ingreso->year) : '',
            'sueldo_mensual_numero' => $sueldo !== null ? number_format((float) $sueldo, 2) : '',
            'sueldo_mensual_numero_letra' => $sueldo !== null ? sprintf('%s, %s', number_format((float) $sueldo, 2), $this->pesosSinCentavos((float) $sueldo)) : '',
            'fecha_inicio_contrato_larga' => $this->fechaLarga($inicio),
            'fecha_inicio_contrato_larga_mayusculas' => $this->mayus($this->fechaLarga($inicio)),
            'fecha_inicio_contrato_dia' => $inicio !== null ? (string) $inicio->day : '',
            'fecha_fin_contrato_larga' => $this->fechaLarga($fin),
            'duracion_letra' => $duracion,
            'duracion_letra_capital' => $this->capital($duracion),
            'duracion_capacitacion_letra' => $duracion,
            'duracion_capacitacion_letra_mayusculas' => $this->mayus($duracion),
            'duracion_capacitacion_letra_titulo' => mb_convert_case($duracion, MB_CASE_TITLE, 'UTF-8'),
            'lugar_y_fecha_inicio_contrato' => $lugar !== '' && $inicio !== null ? sprintf('%s, el %s', $lugar, $this->fechaLarga($inicio)) : '',
            'fecha_firma_a_los_inicio_contrato' => $inicio !== null ? sprintf('%d días del mes de %s de %s', $inicio->day, self::MESES[$inicio->month - 1], $inicio->year) : '',
            'ciudad_y_fecha_firma_a_los_inicio_contrato' => $lugar !== '' && $inicio !== null ? sprintf('%s, a los %d días del mes de %s de %s', $lugar, $inicio->day, self::MESES[$inicio->month - 1], $inicio->year) : '',
            'fecha_firma_dias_del_mes_inicio_contrato' => $inicio !== null ? sprintf('%d días del mes de %s del año %s', $inicio->day, self::MESES[$inicio->month - 1], $inicio->year) : '',
            'gerente_nombre_mayusculas' => $this->mayus((string) ($c->jefe?->nombreCompleto() ?? '')),
        ];

        return [
            ...$datos,
            ...$this->datosEvaluacion($contexto),
            ...$this->datosCierre($contexto, $lugar, $sucursal),
            ...$this->datosSolicitud($contexto),
            ...$this->datosPrestamo($contexto),
        ];
    }

    /**
     * Fuente de un campo del documento (para pedir solo lo que falta).
     *
     * @return array{campo: string, base: string, fuente: string, columna: string, etiqueta: string, tipo: string}
     */
    public function fuente(string $campo): array
    {
        $base = self::DEPENDE[$campo] ?? $campo;

        // Domicilio del patrón configurado como «de la sucursal»: lo que
        // falta es la dirección de la sucursal, no la fiscal.
        if ($base === 'empresa_domicilio' && $this->domicilioPatronDeSucursal()) {
            $base = 'sucursal_direccion';
        }

        if (preg_match('/^criterio_\d+_/', $campo) === 1 || str_starts_with($campo, 'resultado_')) {
            $base = 'evaluacion';
        }

        [$fuente, $columna, $etiqueta, $tipo] = self::BASE[$base] ?? ['proceso', $base, ucfirst(str_replace('_', ' ', $base)), 'proceso'];

        return ['campo' => $campo, 'base' => $base, 'fuente' => $fuente, 'columna' => $columna, 'etiqueta' => $etiqueta, 'tipo' => $tipo];
    }

    /**
     * Cómo pide la UI (web y app) un dato faltante:
     *  - editable: se puede capturar desde el modal del documento. Nunca
     *    los datos de relación (puesto, sucursal, departamento, jefe) ni los
     *    del proceso: esos se corrigen en su módulo, no "a mano" en un
     *    documento;
     *  - persistencia: 'colaborador' / 'sucursal' (se guarda en la ficha y
     *    no se vuelve a pedir) o 'documento' (dato del acto: testigos, hora
     *    del acta… solo vive en el snapshot del documento);
     *  - control + opciones: selector, fecha, hora, correo, texto.
     *
     * @param  array{fuente: string, tipo: string, base: string}  $fuente
     * @return array{editable: bool, persistencia: string, control: string, opciones: list<array{value: string, label: string}>, sugerencias: list<string>}
     */
    public function captura(array $fuente): array
    {
        $editable = in_array($fuente['fuente'], ['colaborador', 'sucursal', 'manual'], true) && ! in_array($fuente['tipo'], ['relacion', 'proceso'], true);

        return [
            'editable' => $editable,
            'persistencia' => match ($fuente['fuente']) {
                'manual' => 'documento',
                'sucursal' => 'sucursal',
                default => 'colaborador',
            },
            'control' => match ($fuente['tipo']) {
                'estado_civil', 'genero' => 'select',
                'fecha' => 'fecha',
                'hora' => 'hora',
                'correo' => 'correo',
                'moneda' => 'moneda',
                default => 'texto',
            },
            'opciones' => match ($fuente['tipo']) {
                'estado_civil' => EstadoCivil::opciones(),
                'genero' => [['value' => 'masculino', 'label' => 'Masculino'], ['value' => 'femenino', 'label' => 'Femenino']],
                default => [],
            },
            'sugerencias' => $fuente['base'] === 'nacionalidad' ? ['Mexicana'] : [],
        ];
    }

    /**
     * Columnas del colaborador que la UI puede completar desde el modal de
     * faltantes (y su validación).
     *
     * @return array<string, string>
     */
    public static function columnasEditablesColaborador(): array
    {
        return [
            'nacionalidad' => 'nullable|string|max:60',
            'genero' => 'nullable|in:masculino,femenino',
            'fecha_nacimiento' => 'nullable|date|before:today',
            'estado_civil' => 'nullable|in:'.implode(',', array_map(fn (EstadoCivil $e) => $e->value, EstadoCivil::cases())),
            'lugar_nacimiento' => 'nullable|string|max:120',
            'clave_elector' => 'nullable|string|max:30',
            'profesion' => 'nullable|string|max:120',
            'curp' => 'nullable|string|size:18',
            'rfc' => 'nullable|string|min:12|max:13',
            'nss' => 'nullable|string|max:15',
            'telefono' => 'nullable|string|max:20',
            'correo_personal' => 'nullable|email|max:191',
            'domicilio' => 'nullable|string|max:191',
            'domicilio_colonia' => 'nullable|string|max:120',
            'domicilio_municipio' => 'nullable|string|max:120',
            'domicilio_estado' => 'nullable|string|max:80',
            'domicilio_cp' => 'nullable|string|max:10',
            'beneficiario_nombre' => 'nullable|string|max:191',
            'beneficiario_parentesco' => 'nullable|string|max:60',
            'contacto_emergencia_nombre' => 'nullable|string|max:191',
            'contacto_emergencia_parentesco' => 'nullable|string|max:60',
            'contacto_emergencia_telefono' => 'nullable|string|max:20',
            'contacto_emergencia_direccion' => 'nullable|string|max:255',
            'fecha_ingreso' => 'nullable|date',
        ];
    }

    /**
     * Columnas de la sucursal que la UI puede completar.
     *
     * @return array<string, string>
     */
    public static function columnasEditablesSucursal(): array
    {
        return [
            'direccion' => 'nullable|string|max:191',
            'ciudad' => 'nullable|string|max:191',
            'estado' => 'nullable|string|max:191',
            'municipio' => 'nullable|string|max:120',
            'codigo_postal' => 'nullable|string|max:10',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function datosEvaluacion(ContextoDocumento $contexto): array
    {
        $evaluacion = $contexto->evaluacion;

        if ($evaluacion === null) {
            return [];
        }

        $evaluacion->loadMissing(['capturadaPor.colaborador.puesto', 'autorizadaPor.colaborador.puesto']);

        // version null/1 = formato libre legacy ("Capacitación inicial", 7
        // criterios Acredita/No acredita); version >= 2 = formato oficial
        // FO-RH-EPP-001 (9 criterios + 5 filtros). Nunca se mezclan: cada
        // fila de `criterios` tiene una forma distinta según la versión.
        $datos = (int) ($evaluacion->version ?? 1) >= 2
            ? $this->datosEvaluacionOficial($evaluacion)
            : $this->datosEvaluacionLegacy($evaluacion);

        $resultado = $evaluacion->resultado;
        $acreditaGlobal = $resultado !== null ? $resultado === ResultadoEvaluacion::Aprobado : null;

        $datos['resultado_acredita'] = $acreditaGlobal === null ? '' : ($acreditaGlobal ? '☒' : '☐');
        $datos['resultado_no_acredita'] = $acreditaGlobal === null ? '' : ($acreditaGlobal ? '☐' : '☒');
        $datos['elaboro_nombre_cargo'] = $this->nombreYCargo($evaluacion->capturadaPor);
        $datos['valido_nombre_cargo'] = $this->nombreYCargo($evaluacion->autorizadaPor);
        $datos['fecha_elaboro_larga'] = $this->fechaLarga($evaluacion->capturada_en);
        $datos['fecha_valido_larga'] = $this->fechaLarga($evaluacion->autorizada_en);

        return $datos;
    }

    /**
     * Formato libre legacy ("Capacitación inicial"): 7 criterios sugeridos,
     * casillas Acredita/No acredita según la calificación 0-10 capturada.
     *
     * @return array<string, string>
     */
    private function datosEvaluacionLegacy(EvaluacionPeriodoPrueba $evaluacion): array
    {
        $datos = [];
        $oficiales = array_values((array) config('contratos.criterios_evaluacion', []));
        $minima = (float) config('contratos.calificacion_minima_aprobatoria', 7);
        /** @var Collection<int, array{criterio: string, calificacion: float|int, comentario?: string|null, acredita?: bool}> $capturados */
        $capturados = collect((array) $evaluacion->criterios);

        foreach ($oficiales as $i => $nombre) {
            $criterio = $capturados->first(fn (array $c): bool => $this->mismoCriterio($c['criterio'], (string) $nombre));

            if ($criterio === null) {
                // Sin resultado capturado: el campo queda vacío (requerido).
                $datos[sprintf('criterio_%d_acredita', $i + 1)] = '';
                $datos[sprintf('criterio_%d_no_acredita', $i + 1)] = '';

                continue;
            }

            $acredita = isset($criterio['acredita']) ? $criterio['acredita'] : ((float) $criterio['calificacion'] >= $minima);
            $datos[sprintf('criterio_%d_acredita', $i + 1)] = $acredita ? '☒' : '☐';
            $datos[sprintf('criterio_%d_no_acredita', $i + 1)] = $acredita ? '☐' : '☒';
        }

        return $datos;
    }

    /**
     * Formato oficial FO-RH-EPP-001: 9 criterios (escala 4-1) y 5 preguntas
     * filtro Sí/No, tal como se capturaron — nunca se recalculan aquí (la
     * regla canónica ya corrió en EvaluacionPeriodoPruebaService).
     *
     * @return array<string, string>
     */
    private function datosEvaluacionOficial(EvaluacionPeriodoPrueba $evaluacion): array
    {
        $datos = [];
        /** @var list<array{clave: string, etiqueta: string, calificacion: int}> $criterios */
        $criterios = (array) $evaluacion->criterios;
        /** @var list<array{clave: string, etiqueta: string, respuesta: bool}> $filtros */
        $filtros = (array) $evaluacion->filtros;

        foreach ($criterios as $i => $criterio) {
            $datos[sprintf('criterio_%d_etiqueta', $i + 1)] = $criterio['etiqueta'];
            $datos[sprintf('criterio_%d_calificacion', $i + 1)] = (string) $criterio['calificacion'];
        }

        foreach ($filtros as $i => $filtro) {
            $datos[sprintf('filtro_%d_etiqueta', $i + 1)] = $filtro['etiqueta'];
            $datos[sprintf('filtro_%d_respuesta', $i + 1)] = $filtro['respuesta'] ? 'Sí' : 'No';
        }

        $datos['total_calificacion'] = (string) $evaluacion->calificacion;
        $datos['renueva_etiqueta'] = $evaluacion->recomienda_renovar ? 'SE RENUEVA' : 'NO SE RENUEVA';

        return $datos;
    }

    /**
     * @return array<string, string>
     */
    private function datosCierre(ContextoDocumento $contexto, string $lugar, ?Sucursal $sucursal): array
    {
        $cierre = $contexto->cierre;
        $manual = $contexto->manuales;
        $jefe = $contexto->colaborador->jefe;
        $actorColaborador = $contexto->actor?->colaborador;
        $datos = [];

        if ($cierre !== null) {
            $participantes = (array) ($cierre->negativa_participantes ?? []);
            $testigos = $cierre->testigos ?? [];
            $negativa = $cierre->negativa_firma_en;

            $datos = [
                'fecha_entrega_larga' => $this->fechaLarga($cierre->fecha_efectiva),
                'fecha_notificacion_larga' => $this->fechaLarga($negativa ?? $cierre->fecha_efectiva),
                'fecha_acta_larga' => $this->fechaLarga($negativa ?? $contexto->fechaDocumento()),
                'hora_acta' => (string) ($participantes['hora_acta'] ?? ($negativa?->format('H:i') ?? '')),
                'lugar_acta' => (string) ($participantes['lugar_acta'] ?? $lugar),
                'domicilio_acta' => (string) ($participantes['domicilio_acta'] ?? $this->domicilioSucursal($sucursal)),
                'rh_nombre' => (string) ($participantes['rh_nombre'] ?? ''),
                'rh_cargo' => (string) ($participantes['rh_cargo'] ?? ''),
                'jefe_nombre' => (string) ($participantes['jefe_nombre'] ?? ''),
                'jefe_cargo' => (string) ($participantes['jefe_cargo'] ?? ''),
            ];

            foreach ([0, 1] as $i) {
                $datos[sprintf('testigo_%d_nombre', $i + 1)] = $this->mayus($testigos[$i]['nombre'] ?? '');
                $datos[sprintf('testigo_%d_cargo', $i + 1)] = $testigos[$i]['cargo'] ?? '';
            }
        }

        // Valores propuestos cuando nadie los capturó todavía.
        $datos['rh_nombre'] = ($datos['rh_nombre'] ?? '') !== '' ? $datos['rh_nombre'] : $this->mayus((string) ($actorColaborador?->nombreCompleto() ?? $contexto->actor->name ?? ''));
        $datos['rh_cargo'] = ($datos['rh_cargo'] ?? '') !== '' ? $datos['rh_cargo'] : (string) ($actorColaborador->puesto->nombre ?? '');
        $datos['jefe_nombre'] = ($datos['jefe_nombre'] ?? '') !== '' ? $datos['jefe_nombre'] : $this->mayus((string) ($jefe?->nombreCompleto() ?? ''));
        $datos['jefe_cargo'] = ($datos['jefe_cargo'] ?? '') !== '' ? $datos['jefe_cargo'] : (string) ($jefe->puesto->nombre ?? '');

        foreach ($manual as $clave => $valor) {
            if (trim($valor) !== '') {
                $datos[$clave] = str_contains($clave, 'nombre') ? $this->mayus($valor) : $valor;
            }
        }

        return $datos;
    }

    /**
     * @return array<string, string>
     */
    private function datosSolicitud(ContextoDocumento $contexto): array
    {
        $solicitud = $contexto->solicitud;

        if ($solicitud === null) {
            return [];
        }

        $tipo = $solicitud->tipo;
        $inicio = $solicitud->fecha_inicio;
        $fin = $solicitud->fecha_fin;
        $fecha = $inicio === null ? '' : ($fin !== null && ! $fin->isSameDay($inicio)
            ? sprintf('%s al %s', $inicio->format('d/m/Y'), $fin->format('d/m/Y'))
            : $inicio->format('d/m/Y'));
        $dias = (int) ($solicitud->dias_solicitados ?? 0);
        $faltar = in_array($tipo, [TipoSolicitudInterna::PermisoConGoce, TipoSolicitudInterna::PermisoSinGoce, TipoSolicitudInterna::PermisoEspecialCumpleanos, TipoSolicitudInterna::PermisoEspecialPaternidad, TipoSolicitudInterna::PermisoEspecialFallecimiento], true);
        $especial = in_array($tipo, [TipoSolicitudInterna::PermisoEspecialCumpleanos, TipoSolicitudInterna::PermisoEspecialPaternidad, TipoSolicitudInterna::PermisoEspecialFallecimiento], true);
        $modalidad = $solicitud->modalidad_permiso ?? match (true) {
            $especial => 'permiso_especial',
            $tipo === TipoSolicitudInterna::PermisoSinGoce => 'descuento_nomina',
            default => null,
        };

        return [
            'fecha_permiso_texto' => $fecha,
            'fecha_inicio_permiso_larga' => $this->fechaLarga($inicio),
            'motivo_permiso' => trim((string) $solicitud->motivo),
            'marca_faltar' => $faltar ? ($dias > 1 ? sprintf('X  (%d días)', $dias) : 'X') : '',
            'marca_salir' => in_array($tipo, [TipoSolicitudInterna::SalidaTemprano, TipoSolicitudInterna::PermisoTiempo], true) ? 'X' : '',
            'marca_llegar_tarde' => $tipo === TipoSolicitudInterna::LlegadaTarde ? 'X' : '',
            'hora_salida' => $this->hora($solicitud->hora_salida),
            'hora_entrada' => $this->hora($solicitud->hora_entrada),
            'marca_tiempo_por_tiempo' => $modalidad === 'tiempo_por_tiempo' ? 'X' : '',
            'marca_descuento_nomina' => $modalidad === 'descuento_nomina' ? 'X' : '',
            'marca_permiso_especial' => $modalidad === 'permiso_especial' ? 'X' : '',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function datosPrestamo(ContextoDocumento $contexto): array
    {
        $prestamo = $contexto->prestamo;

        if ($prestamo === null) {
            return [];
        }

        $monto = (float) $prestamo->monto_original;
        $pago = (float) $prestamo->pago_programado;
        $fechaSolicitud = $prestamo->fecha_solicitud ?? $prestamo->created_at;
        $otorgamiento = $prestamo->fecha_otorgamiento ?? $prestamo->autorizado_en ?? $contexto->fechaDocumento();
        $primero = $prestamo->fecha_primer_descuento;
        $ultimo = $primero !== null ? $this->ultimoPago(CarbonImmutable::instance($primero), (string) $prestamo->periodicidad, (int) $prestamo->plazo) : null;
        $periodicidad = mb_strtolower((string) $prestamo->periodicidad);
        $unidad = match (true) {
            str_contains($periodicidad, 'seman') => 'semanas',
            str_contains($periodicidad, 'quincen') => 'quincenas',
            str_contains($periodicidad, 'mens') => 'meses',
            str_contains($periodicidad, 'diar') => 'días',
            default => 'pagos',
        };
        $c = $contexto->colaborador;
        [$paterno, $materno] = $this->apellidos((string) $c->apellidos);
        $suscripcion = $contexto->fechaDocumento();

        return [
            'prestamo_folio' => sprintf('PRE-%06d', $prestamo->id),
            'monto_prestamo' => sprintf('$%s', number_format($monto, 2)),
            'monto_prestamo_numero' => number_format($monto, 2),
            'monto_prestamo_letra' => $this->letras->moneda($monto),
            'plazo_prestamo' => sprintf('%d', $prestamo->plazo),
            'plazo_prestamo_texto' => sprintf('%d %s', $prestamo->plazo, $unidad),
            'periodicidad_prestamo' => (string) $prestamo->periodicidad,
            'pago_prestamo' => sprintf('$%s', number_format($pago, 2)),
            'pago_prestamo_numero' => number_format($pago, 2),
            'pago_prestamo_letra' => $this->letras->moneda($pago),
            'marca_pagos_semanales' => $unidad === 'semanas' ? 'X' : '',
            'marca_pagos_diarios' => $unidad === 'días' ? 'X' : '',
            'fecha_solicitud_prestamo_corta' => $fechaSolicitud?->format('d/m/Y') ?? '',
            'fecha_otorgamiento_corta' => $otorgamiento->format('d/m/Y'),
            'fecha_primer_pago_corta' => $primero?->format('d/m/Y') ?? '',
            'fecha_ultimo_pago_corta' => $ultimo?->format('d/m/Y') ?? '',
            'primer_pago_dia' => $primero !== null ? $primero->format('d') : '',
            'primer_pago_mes' => $primero !== null ? self::MESES[$primero->month - 1] : '',
            'primer_pago_anio_corto' => $primero !== null ? $primero->format('y') : '',
            'ultimo_pago_dia' => $ultimo !== null ? $ultimo->format('d') : '',
            'ultimo_pago_mes' => $ultimo !== null ? self::MESES[$ultimo->month - 1] : '',
            'ultimo_pago_anio_corto' => $ultimo !== null ? $ultimo->format('y') : '',
            'suscripcion_dia' => $suscripcion->format('d'),
            'suscripcion_mes' => self::MESES[$suscripcion->month - 1],
            'suscripcion_anio' => $suscripcion->format('Y'),
            'nombre_mayusculas' => $this->mayus((string) $c->name),
            'apellido_paterno_mayusculas' => $this->mayus($paterno),
            'apellido_materno_mayusculas' => $this->mayus($materno),
            'domicilio_colonia_mayusculas' => $this->mayus((string) $c->domicilio_colonia),
            'domicilio_municipio_mayusculas' => $this->mayus((string) $c->domicilio_municipio),
            'domicilio_estado_mayusculas' => $this->mayus((string) $c->domicilio_estado),
            'domicilio_cp' => (string) $c->domicilio_cp,
            'sucursal_numero' => (string) $c->sucursalPrincipal?->clave,
            'tipo_identificacion' => trim((string) $c->clave_elector) !== '' ? 'INE' : '',
            'representante_legal_mayusculas' => $this->mayus(trim((string) $c->sucursalPrincipal?->empresa?->representante_legal_nombre)),
        ];
    }

    /**
     * Meses del contrato: del propio contrato (inicio→fin) y, si no tiene
     * fin, de la configuración del puesto (puestos.meses_periodo_prueba).
     */
    private function mesesContrato(ContextoDocumento $contexto): ?int
    {
        $contrato = $contexto->contrato ?? $contexto->evaluacion?->contrato;

        if ($contrato !== null && $contrato->fecha_fin !== null) {
            return max(1, (int) round($contrato->fecha_inicio->diffInMonths($contrato->fecha_fin->copy()->addDay())));
        }

        $meses = $contexto->colaborador->puesto?->meses_periodo_prueba;

        return $meses !== null ? (int) $meses : null;
    }

    /**
     * Fecha del último pago según la periodicidad del préstamo.
     */
    private function ultimoPago(CarbonImmutable $primero, string $periodicidad, int $plazo): CarbonImmutable
    {
        $restantes = max(0, $plazo - 1);
        $periodicidad = mb_strtolower($periodicidad);

        return match (true) {
            str_contains($periodicidad, 'seman') => $primero->addWeeks($restantes),
            str_contains($periodicidad, 'quincen') => $primero->addDays(15 * $restantes),
            str_contains($periodicidad, 'diar') => $primero->addDays($restantes),
            default => $primero->addMonthsNoOverflow($restantes),
        };
    }

    /**
     * "López García" → ["López", "García"]. Apellidos compuestos ("De la
     * Cruz Pérez") se cortan en el primer espacio que no sea partícula.
     *
     * @return array{0: string, 1: string}
     */
    private function apellidos(string $apellidos): array
    {
        $palabras = preg_split('/\s+/', trim($apellidos)) ?: [];
        $particulas = ['de', 'del', 'la', 'las', 'los', 'y', 'san', 'santa'];
        $paterno = [];

        while ($palabras !== []) {
            $palabra = array_shift($palabras);
            $paterno[] = $palabra;

            if (! in_array(mb_strtolower($palabra), $particulas, true)) {
                break;
            }
        }

        return [implode(' ', $paterno), implode(' ', $palabras)];
    }

    private function duracion(int $meses): string
    {
        $numero = self::NUMEROS[$meses] ?? (string) $meses;

        return $meses === 1 ? 'un mes' : sprintf('%s meses', $numero);
    }

    private function lugar(?Sucursal $sucursal, ?Empresa $empresa): string
    {
        $ciudad = trim((string) $sucursal?->ciudad);
        $estado = trim((string) $sucursal?->estado);

        if ($ciudad !== '') {
            return $estado !== '' && ! str_contains(mb_strtolower($ciudad), mb_strtolower($estado)) ? sprintf('%s, %s', $ciudad, $estado) : $ciudad;
        }

        return trim((string) $empresa?->ciudad_firma);
    }

    /**
     * De dónde salen los datos del PATRÓN para esta persona — para que RH
     * vea en la vista previa qué domicilio y qué representante legal se
     * imprimirán. Sin dato capturado la fuente es «falta» (el documento que
     * lo pida no se genera): ya no hay valores predeterminados ocultos.
     *
     * @return array{domicilio: array{valor: string, fuente: string, configurado: string}, representante: array{valor: string, fuente: string}}
     */
    public function origenesPatron(ContextoDocumento $contexto): array
    {
        $contexto->colaborador->loadMissing('sucursalPrincipal.empresa');
        $sucursal = $contexto->colaborador->sucursalPrincipal;
        $empresa = $sucursal?->empresa;
        $configurado = $this->domicilioPatronDeSucursal() ? 'sucursal' : 'fiscal';
        $domicilio = $this->domicilioPatron($empresa, $sucursal);
        $representanteEmpresa = trim((string) $empresa?->representante_legal_nombre);

        return [
            'domicilio' => ['valor' => $domicilio, 'fuente' => $domicilio === '' ? 'falta' : $configurado, 'configurado' => $configurado],
            'representante' => ['valor' => $representanteEmpresa, 'fuente' => $representanteEmpresa !== '' ? 'empresa' : 'falta'],
        ];
    }

    /**
     * Domicilio del patrón según Configuración → Documentos: el fiscal de
     * la empresa o el de la sucursal del colaborador. Sin el elegido
     * capturado queda vacío (falta): nunca se cambia en silencio al otro.
     */
    private function domicilioPatron(?Empresa $empresa, ?Sucursal $sucursal): string
    {
        if ($this->domicilioPatronDeSucursal()) {
            return $this->domicilioSucursal($sucursal);
        }

        return trim((string) $empresa?->domicilio_fiscal);
    }

    private function domicilioPatronDeSucursal(): bool
    {
        return FuenteDomicilioPatron::tryFrom((string) config('documentos_maestros.domicilio_patron')) === FuenteDomicilioPatron::Sucursal;
    }

    /**
     * «Calle y número, col. Colonia, Municipio, Estado, C.P. 00000» con lo
     * capturado (sin repetir lo que ya trae la calle).
     */
    private function domicilioCompleto(Colaborador $c): string
    {
        $calle = trim((string) $c->domicilio);

        if ($calle === '') {
            return '';
        }

        $segmentos = array_map(fn (string $p): string => mb_strtolower(trim($p)), explode(',', $calle));
        $partes = [$calle];

        foreach ([
            $c->domicilio_colonia ? sprintf('col. %s', trim((string) $c->domicilio_colonia)) : null,
            trim((string) $c->domicilio_municipio),
            trim((string) $c->domicilio_estado),
            $c->domicilio_cp ? sprintf('C.P. %s', trim((string) $c->domicilio_cp)) : null,
        ] as $parte) {
            if ($parte !== null && $parte !== '' && ! in_array(mb_strtolower($parte), $segmentos, true)) {
                $partes[] = $parte;
            }
        }

        return implode(', ', $partes);
    }

    /**
     * «María López (Madre) · Tel. 7771234567 · Calle Ejemplo 123»: solo con
     * el nombre capturado; las demás partes se omiten si están vacías.
     */
    private function contactoEmergenciaCompleto(Colaborador $c): string
    {
        $nombre = trim((string) $c->contacto_emergencia_nombre);

        if ($nombre === '') {
            return '';
        }

        $parentesco = trim((string) $c->contacto_emergencia_parentesco);
        $telefono = trim((string) $c->contacto_emergencia_telefono);
        $direccion = trim((string) $c->contacto_emergencia_direccion);

        return implode(' · ', array_filter([
            $parentesco !== '' ? sprintf('%s (%s)', $nombre, $parentesco) : $nombre,
            $telefono !== '' ? sprintf('Tel. %s', $telefono) : '',
            $direccion,
        ], fn (string $p): bool => $p !== ''));
    }

    private function domicilioSucursal(?Sucursal $sucursal): string
    {
        if ($sucursal === null || trim((string) $sucursal->direccion) === '') {
            return '';
        }

        $partes = array_filter([
            trim((string) $sucursal->direccion),
            $sucursal->colonia ? sprintf('colonia %s', $sucursal->colonia) : null,
            $sucursal->codigo_postal ? sprintf('C.P. %s', $sucursal->codigo_postal) : null,
            trim((string) ($sucursal->municipio ?: $sucursal->ciudad)),
            trim((string) $sucursal->estado),
        ], fn (?string $p): bool => $p !== null && $p !== '');

        // La dirección capturada a veces ya incluye ciudad/estado como
        // segmento propio ("…, Cuernavaca, Morelos"): no se repiten. Solo
        // segmentos completos — «Av. Morelos 120» no contiene el estado.
        $segmentos = array_map(fn (string $p): string => mb_strtolower(trim($p)), explode(',', (string) $sucursal->direccion));
        $partes = array_values(array_filter($partes, fn (string $p, int $i): bool => $i === 0 || ! in_array(mb_strtolower($p), array_slice($segmentos, 1), true), ARRAY_FILTER_USE_BOTH));

        return implode(', ', $partes);
    }

    private function nombreYCargo(?User $usuario): string
    {
        if ($usuario === null) {
            return '';
        }

        $colaborador = $usuario->colaborador;
        $nombre = $this->mayus($colaborador?->nombreCompleto() ?: $usuario->name);
        $cargo = (string) $colaborador?->puesto?->nombre;

        return $cargo !== '' ? sprintf('%s, %s', $nombre, $cargo) : $nombre;
    }

    private function fechaLarga(?CarbonInterface $fecha): string
    {
        if ($fecha === null) {
            return '';
        }

        return sprintf('%d de %s de %d', $fecha->day, self::MESES[$fecha->month - 1], $fecha->year);
    }

    private function pesosSinCentavos(float $monto): string
    {
        // "QUINCE MIL PESOS 00/100 M.N." → "QUINCE MIL PESOS" (el formato ya trae "00/100 m.n.").
        return trim((string) preg_replace('/\s+\d{2}\/100 M\.N\.$/u', '', $this->letras->moneda($monto)));
    }

    private function hora(?string $hora): string
    {
        if ($hora === null || trim($hora) === '') {
            return '';
        }

        return Carbon::createFromFormat('H:i:s', strlen($hora) === 5 ? $hora.':00' : $hora)?->format('H:i') ?? $hora;
    }

    private function mismoCriterio(string $a, string $b): bool
    {
        $normalizar = fn (string $t): string => mb_strtolower(trim((string) preg_replace("/[’'´`]/u", '', $t)));

        return $normalizar($a) === $normalizar($b);
    }

    private function mayus(string $texto): string
    {
        return mb_strtoupper(trim($texto), 'UTF-8');
    }

    private function capital(string $texto): string
    {
        return $texto === '' ? '' : mb_strtoupper(mb_substr($texto, 0, 1)).mb_substr($texto, 1);
    }
}
