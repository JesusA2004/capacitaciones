<?php

namespace App\Services\Incorporacion\SolicitudEmpleo;

use App\Enums\BienMueble;
use App\Enums\Escolaridad;
use App\Enums\EstadoSalud;
use App\Enums\EstiloTrabajo;
use App\Enums\FormaAdquisicionInmueble;
use App\Enums\FuenteVacante;
use App\Enums\MaterialParedes;
use App\Enums\ParentescoDependiente;
use App\Enums\ServicioMedico;
use App\Enums\ServicioVivienda;
use App\Enums\TenenciaVivienda;
use App\Enums\TipoIdentificacion;
use App\Enums\TipoPropiedadInmueble;
use App\Enums\TipoSanguineo;
use App\Enums\TipoVehiculoBien;
use App\Enums\TipoVivienda;
use App\Enums\ZonaInmueble;

/**
 * Catálogo ÚNICO de lo que piden la Solicitud de empleo y el Estudio
 * socioeconómico (docs/SOLICITUD_EMPLEO_Y_SOCIOECONOMICO.md): secciones
 * progresivas, cada dato con su etiqueta en español, control, dónde se
 * guarda, si es obligatorio (y bajo qué condición), si admite «No aplica»
 * y qué documento lo usa. Web, app, completitud y generación leen de aquí:
 * ninguna lista de «obligatorios» se mantiene aparte.
 *
 * almacen:
 *   base         dato que ya existe en `colaboradores` y se captura en
 *                «Datos personales» del registro (aquí solo se lee).
 *   colaborador  columna nueva de `colaboradores` (teléfono alterno, escolaridad).
 *   medicos      colaborador_datos_medicos (cifrado).
 *   perfil       colaborador_perfiles_ingreso.
 *   economica    colaborador_perfiles_ingreso.situacion_economica (JSON cifrado).
 *
 * requerido_si: [campo, operador, valor] — operador: igual | en | distinto | positivo | contiene.
 *
 * @phpstan-type Campo array{campo: string, seccion: string, etiqueta: string, tipo: string, almacen: string, columna: string, requerido: bool, requerido_si: array{0: string, 1: string, 2?: mixed}|null, no_aplica: bool, documentos: list<string>, opciones: class-string|null, max: int|null, min: int|null, ayuda: string|null}
 * @phpstan-type Lista array{seccion: string, clave: string, etiqueta: string, singular: string, minimo: int, maximo: int, bandera_vacia: string|null, etiqueta_vacia: string|null, documentos: list<string>, campos: array<string, array{etiqueta: string, tipo: string, requerido: bool, max?: int, min?: int, opciones?: class-string}>}
 */
final class CatalogoSolicitudEmpleo
{
    public const SOLICITUD = 'solicitud_empleo';

    public const ESTUDIO = 'estudio_socioeconomico';

    public const AMBOS = [self::SOLICITUD, self::ESTUDIO];

    /** Nombre de cada documento en los mensajes («Faltan 3 datos para generar la Solicitud de Empleo: …»). */
    public const DOCUMENTOS = [
        self::SOLICITUD => 'Solicitud de Empleo',
        self::ESTUDIO => 'Estudio Socioeconómico',
    ];

    /** Artículo de cada documento («la Solicitud…», «el Estudio…»). */
    public const ARTICULOS = [
        self::SOLICITUD => 'la',
        self::ESTUDIO => 'el',
    ];

    /** Secciones progresivas, en el orden en que se capturan. */
    public const SECCIONES = [
        'personal' => ['titulo' => 'Datos personales', 'descripcion' => 'Datos que completan tu información personal, de salud e identificación.'],
        'domicilio' => ['titulo' => 'Domicilio', 'descripcion' => 'Cómo llegar a tu casa, cuánto tiempo llevas ahí y con quién vives.'],
        'dependientes' => ['titulo' => 'Familia y dependientes', 'descripcion' => 'Personas que dependen económicamente de ti.'],
        'vivienda' => ['titulo' => 'Vivienda', 'descripcion' => 'Características de la vivienda donde habitas.'],
        'economica' => ['titulo' => 'Situación económica', 'descripcion' => 'Gastos mensuales, créditos, bienes y servicio médico.'],
        'escolaridad' => ['titulo' => 'Escolaridad y competencias', 'descripcion' => 'Tus estudios y experiencia relevante para el puesto.'],
        'experiencia' => ['titulo' => 'Experiencia laboral', 'descripcion' => 'Tus últimos 3 empleos.'],
        'referencias' => ['titulo' => 'Referencias personales', 'descripcion' => 'Personas que te conocen y pueden dar referencias de ti.'],
        'perfil' => ['titulo' => 'Perfil humano y vacante', 'descripcion' => 'Cómo te describes y cómo llegaste a la vacante.'],
    ];

    /** Mínimo de referencias personales (el formato trae 4 renglones). */
    public const MINIMO_REFERENCIAS = 2;

    private const S = [self::SOLICITUD];

    private const E = [self::ESTUDIO];

    private const SE = [self::SOLICITUD, self::ESTUDIO];

    /**
     * campo => [seccion, etiqueta, tipo, almacen, requerido, documentos, extra]
     */
    private const CAMPOS = [
        // ── Datos personales (base: se capturan en «Datos personales» del registro) ──
        'name' => ['personal', 'Nombre(s)', 'texto', 'base', true, self::SE],
        'apellidos' => ['personal', 'Apellidos', 'texto', 'base', true, self::SE],
        'genero' => ['personal', 'Sexo', 'texto', 'base', true, self::S],
        'fecha_nacimiento' => ['personal', 'Fecha de nacimiento', 'fecha', 'base', true, self::SE],
        'telefono' => ['personal', 'Teléfono celular', 'texto', 'base', true, self::SE],
        'estado_civil' => ['personal', 'Estado civil', 'texto', 'base', true, self::SE],
        'correo_personal' => ['personal', 'Correo electrónico', 'texto', 'base', true, self::S],
        'curp' => ['personal', 'CURP', 'texto', 'base', true, self::SE],
        'rfc' => ['personal', 'RFC', 'texto', 'base', true, self::S],
        'nss' => ['personal', 'Número de seguridad social (IMSS)', 'texto', 'base', true, self::S],
        // ── Datos personales (nuevos) ──
        'telefono_alterno' => ['personal', 'Teléfono alterno (recados)', 'texto', 'colaborador', true, self::S, ['no_aplica' => true, 'max' => 20, 'ayuda' => 'Otro número donde podamos localizarte. Si no tienes, marca «No aplica».']],
        'numero_hijos' => ['personal', 'Número de hijos', 'entero', 'perfil', true, self::S, ['min' => 0, 'max' => 20]],
        'conyuge_nombre' => ['personal', 'Nombre de tu cónyuge', 'texto', 'perfil', true, self::S, ['requerido_si' => ['estado_civil', 'en', ['casado', 'union_libre']], 'max' => 191]],
        'tipo_sanguineo' => ['personal', 'Tipo sanguíneo', 'select', 'medicos', true, self::E, ['opciones' => TipoSanguineo::class, 'ayuda' => 'Si no lo sabes, elige «No lo sé».']],
        'estado_salud' => ['personal', '¿Cómo consideras tu estado de salud?', 'select', 'perfil', true, self::S, ['opciones' => EstadoSalud::class]],
        'padece_enfermedad_cronica' => ['personal', '¿Padeces alguna enfermedad crónica?', 'booleano', 'perfil', true, self::S],
        'enfermedad_cronica_cual' => ['personal', '¿Cuál enfermedad crónica?', 'texto', 'medicos', true, self::S, ['requerido_si' => ['padece_enfermedad_cronica', 'igual', true], 'max' => 120, 'columna' => 'condicion_medica']],
        'proceso_legal_laboral' => ['personal', '¿Estás o has estado en algún proceso legal laboral?', 'booleano', 'perfil', true, self::S],
        'identificacion_tipo' => ['personal', 'Identificación oficial', 'select', 'perfil', true, self::E, ['opciones' => TipoIdentificacion::class]],
        'identificacion_otro' => ['personal', 'Otra identificación (cuál)', 'texto', 'perfil', true, self::E, ['requerido_si' => ['identificacion_tipo', 'igual', 'otro'], 'max' => 80]],
        'identificacion_institucion' => ['personal', 'Institución que expide la identificación', 'texto', 'perfil', true, self::E, ['requerido_si' => ['identificacion_tipo', 'distinto', 'ine'], 'max' => 150]],
        'identificacion_folio' => ['personal', 'Número de folio de la identificación', 'texto', 'perfil', true, self::E, ['max' => 40, 'ayuda' => 'El folio impreso en tu identificación.']],
        // ── Domicilio (base) ──
        'domicilio_calle' => ['domicilio', 'Calle', 'texto', 'base', true, self::SE],
        'domicilio_colonia' => ['domicilio', 'Colonia', 'texto', 'base', true, self::SE],
        'domicilio_municipio' => ['domicilio', 'Municipio', 'texto', 'base', true, self::SE],
        'domicilio_estado' => ['domicilio', 'Estado', 'texto', 'base', true, self::SE],
        'domicilio_cp' => ['domicilio', 'Código postal', 'texto', 'base', true, self::SE],
        // ── Domicilio (nuevos) ──
        'tiempo_residencia_anios' => ['domicilio', 'Años viviendo en tu domicilio', 'entero', 'perfil', true, self::SE, ['min' => 0, 'max' => 99]],
        'tiempo_residencia_meses' => ['domicilio', 'Meses adicionales viviendo en tu domicilio', 'entero', 'perfil', false, self::SE, ['min' => 0, 'max' => 11]],
        'con_quien_vive' => ['domicilio', '¿Con quién vives?', 'texto', 'perfil', true, self::S, ['max' => 150]],
        'tiempo_traslado_minutos' => ['domicilio', 'Minutos de tu domicilio a la sucursal', 'entero', 'perfil', true, self::S, ['min' => 1, 'max' => 600]],
        'entre_calle_1' => ['domicilio', 'Entre calle', 'texto', 'perfil', true, self::E, ['max' => 120]],
        'entre_calle_2' => ['domicilio', 'Y calle', 'texto', 'perfil', true, self::E, ['max' => 120]],
        'calle_posterior' => ['domicilio', 'Calle de atrás', 'texto', 'perfil', true, self::E, ['no_aplica' => true, 'max' => 120]],
        'calle_lateral' => ['domicilio', 'Otra calle de referencia', 'texto', 'perfil', true, self::E, ['no_aplica' => true, 'max' => 120]],
        'referencias_domicilio' => ['domicilio', 'Referencias para llegar a tu domicilio', 'texto_largo', 'perfil', true, self::S, ['max' => 300, 'ayuda' => 'Color de la casa, negocios cercanos, señas para encontrarla.']],
        // ── Vivienda ──
        'vivienda_tipo' => ['vivienda', 'Tipo de vivienda', 'select', 'perfil', true, self::E, ['opciones' => TipoVivienda::class]],
        'vivienda_tenencia' => ['vivienda', 'La vivienda es', 'select', 'perfil', true, self::SE, ['opciones' => TenenciaVivienda::class]],
        'paredes' => ['vivienda', 'Material de las paredes', 'select', 'perfil', true, self::E, ['opciones' => MaterialParedes::class]],
        'paredes_otro' => ['vivienda', 'Otro material de paredes (cuál)', 'texto', 'perfil', true, self::E, ['requerido_si' => ['paredes', 'igual', 'otro'], 'max' => 80]],
        'numero_cuartos' => ['vivienda', 'Número de cuartos', 'entero', 'perfil', true, self::E, ['min' => 0, 'max' => 30]],
        'numero_banos' => ['vivienda', 'Número de baños', 'entero', 'perfil', true, self::E, ['min' => 0, 'max' => 20]],
        'tiene_patio' => ['vivienda', '¿Tiene patio?', 'booleano', 'perfil', true, self::E],
        'construccion_otros' => ['vivienda', 'Otras características de la construcción', 'texto', 'perfil', true, self::E, ['no_aplica' => true, 'max' => 120]],
        'servicios_vivienda' => ['vivienda', 'Servicios con los que cuenta la vivienda', 'multiselect', 'perfil', true, self::E, ['opciones' => ServicioVivienda::class]],
        'servicios_vivienda_otro' => ['vivienda', 'Otro servicio (cuál)', 'texto', 'perfil', true, self::E, ['requerido_si' => ['servicios_vivienda', 'contiene', 'otro'], 'max' => 120]],
        'personas_habitan' => ['vivienda', 'Personas que habitan la vivienda', 'entero', 'perfil', true, self::E, ['min' => 1, 'max' => 30]],
        // ── Situación económica (JSON cifrado) ──
        'egreso_alimentacion' => ['economica', 'Gasto mensual en alimentación', 'moneda', 'economica', true, self::E],
        'egreso_renta' => ['economica', 'Gasto mensual en renta', 'moneda', 'economica', true, self::E],
        'egreso_agua' => ['economica', 'Gasto mensual en agua', 'moneda', 'economica', true, self::E],
        'egreso_luz' => ['economica', 'Gasto mensual en luz', 'moneda', 'economica', true, self::E],
        'egreso_telefono' => ['economica', 'Gasto mensual en teléfono', 'moneda', 'economica', true, self::E],
        'egreso_servicios_medicos' => ['economica', 'Gasto mensual en servicios médicos', 'moneda', 'economica', true, self::E],
        'egreso_vestido' => ['economica', 'Gasto mensual en vestido', 'moneda', 'economica', true, self::E],
        'egreso_diversion' => ['economica', 'Gasto mensual en diversión', 'moneda', 'economica', true, self::E],
        'egreso_predial' => ['economica', 'Gasto mensual en predial', 'moneda', 'economica', true, self::E],
        'egreso_hipoteca' => ['economica', 'Gasto mensual en hipoteca', 'moneda', 'economica', true, self::E],
        'egreso_transporte' => ['economica', 'Gasto mensual en transporte', 'moneda', 'economica', true, self::E],
        'egreso_gasolina' => ['economica', 'Gasto mensual en gasolina y mantenimiento del vehículo', 'moneda', 'economica', true, self::E],
        'egreso_gas' => ['economica', 'Gasto mensual en gas', 'moneda', 'economica', true, self::E],
        'egreso_otros' => ['economica', 'Otros gastos mensuales', 'moneda', 'economica', true, self::E],
        'egreso_otros_concepto' => ['economica', 'Concepto de otros gastos', 'texto', 'economica', true, self::E, ['requerido_si' => ['egreso_otros', 'positivo'], 'max' => 60]],
        'infonavit' => ['economica', '¿Tienes crédito INFONAVIT?', 'booleano', 'economica', true, self::S],
        'hoja_retencion' => ['economica', '¿Anexas hoja de retención INFONAVIT?', 'booleano', 'economica', true, self::S, ['requerido_si' => ['infonavit', 'igual', true]]],
        'tiene_inmueble' => ['economica', '¿Tienes bienes inmuebles (casa, terreno)?', 'booleano', 'economica', true, self::E],
        'inmueble_ubicacion' => ['economica', 'Ubicación del inmueble', 'texto', 'economica', true, self::E, ['requerido_si' => ['tiene_inmueble', 'igual', true], 'max' => 191]],
        'inmueble_metros' => ['economica', 'Metros cuadrados del inmueble', 'entero', 'economica', true, self::E, ['requerido_si' => ['tiene_inmueble', 'igual', true], 'min' => 1, 'max' => 1000000]],
        'inmueble_valor' => ['economica', 'Valor aproximado del inmueble', 'moneda', 'economica', true, self::E, ['requerido_si' => ['tiene_inmueble', 'igual', true]]],
        'inmueble_tipo_propiedad' => ['economica', 'Tipo de propiedad del inmueble', 'select', 'economica', true, self::E, ['requerido_si' => ['tiene_inmueble', 'igual', true], 'opciones' => TipoPropiedadInmueble::class]],
        'inmueble_zona' => ['economica', 'Zona del inmueble', 'select', 'economica', true, self::E, ['requerido_si' => ['tiene_inmueble', 'igual', true], 'opciones' => ZonaInmueble::class]],
        'inmueble_adquisicion' => ['economica', 'Forma de adquisición del inmueble', 'select', 'economica', true, self::E, ['requerido_si' => ['tiene_inmueble', 'igual', true], 'opciones' => FormaAdquisicionInmueble::class]],
        'inmueble_adquisicion_otro' => ['economica', 'Otra forma de adquisición (cuál)', 'texto', 'economica', true, self::E, ['requerido_si' => ['inmueble_adquisicion', 'igual', 'otro'], 'max' => 80]],
        'tiene_vehiculo' => ['economica', '¿Tienes vehículo o moto?', 'booleano', 'economica', true, self::E],
        'vehiculo_tipo' => ['economica', 'Tipo de vehículo', 'select', 'economica', true, self::E, ['requerido_si' => ['tiene_vehiculo', 'igual', true], 'opciones' => TipoVehiculoBien::class]],
        'vehiculo_otro' => ['economica', 'Otro vehículo (cuál)', 'texto', 'economica', true, self::E, ['requerido_si' => ['vehiculo_tipo', 'igual', 'otro'], 'max' => 80]],
        'vehiculo_modelo' => ['economica', 'Modelo del vehículo', 'texto', 'economica', true, self::E, ['requerido_si' => ['tiene_vehiculo', 'igual', true], 'max' => 80]],
        'vehiculo_tipo_detalle' => ['economica', 'Tipo (versión) del vehículo', 'texto', 'economica', true, self::E, ['requerido_si' => ['tiene_vehiculo', 'igual', true], 'max' => 80]],
        'vehiculo_marca' => ['economica', 'Marca del vehículo', 'texto', 'economica', true, self::E, ['requerido_si' => ['tiene_vehiculo', 'igual', true], 'max' => 80]],
        'vehiculo_valor' => ['economica', 'Valor aproximado del vehículo', 'moneda', 'economica', true, self::E, ['requerido_si' => ['tiene_vehiculo', 'igual', true]]],
        'bienes_muebles' => ['economica', 'Otros bienes muebles', 'multiselect', 'economica', true, self::E, ['opciones' => BienMueble::class, 'ayuda' => 'Marca los que tienes; si no tienes ninguno, déjalo vacío y guarda.']],
        'bienes_muebles_otro' => ['economica', 'Otro bien mueble (cuál)', 'texto', 'economica', true, self::E, ['requerido_si' => ['bienes_muebles', 'contiene', 'otro'], 'max' => 80]],
        'servicio_medico' => ['economica', 'Servicio médico con el que cuentas', 'select', 'economica', true, self::E, ['opciones' => ServicioMedico::class]],
        // ── Escolaridad y competencias ──
        'escolaridad' => ['escolaridad', 'Último grado de estudios', 'select', 'colaborador', true, self::S, ['opciones' => Escolaridad::class]],
        'area_cursos' => ['escolaridad', 'Área y cursos relevantes (ventas, atención a clientes, cobranza…)', 'texto', 'perfil', true, self::S, ['no_aplica' => true, 'max' => 200]],
        'licencia_moto' => ['escolaridad', '¿Cuentas con licencia y manejo de motocicleta?', 'booleano', 'perfil', true, self::S],
        'anios_manejo_moto' => ['escolaridad', 'Años de experiencia manejando moto', 'entero', 'perfil', true, self::S, ['requerido_si' => ['licencia_moto', 'igual', true], 'min' => 0, 'max' => 60]],
        'exp_ventas' => ['escolaridad', '¿Tienes experiencia en ventas?', 'booleano', 'perfil', true, self::S],
        'exp_ventas_anios' => ['escolaridad', 'Años de experiencia en ventas', 'entero', 'perfil', true, self::S, ['requerido_si' => ['exp_ventas', 'igual', true], 'min' => 0, 'max' => 60]],
        'exp_cobranza' => ['escolaridad', '¿Tienes experiencia en cobranza?', 'booleano', 'perfil', true, self::S],
        'exp_cobranza_anios' => ['escolaridad', 'Años de experiencia en cobranza', 'entero', 'perfil', true, self::S, ['requerido_si' => ['exp_cobranza', 'igual', true], 'min' => 0, 'max' => 60]],
        'exp_creditos' => ['escolaridad', '¿Tienes experiencia en créditos o financieras?', 'booleano', 'perfil', true, self::S],
        'exp_creditos_donde' => ['escolaridad', '¿Dónde (créditos o financiera)?', 'texto', 'perfil', true, self::S, ['requerido_si' => ['exp_creditos', 'igual', true], 'max' => 120]],
        'prospeccion_campo' => ['escolaridad', '¿Has hecho prospección en campo?', 'booleano', 'perfil', true, self::S],
        'apps_moviles' => ['escolaridad', '¿Manejas apps móviles?', 'booleano', 'perfil', true, self::S],
        'comisiones_objetivos' => ['escolaridad', '¿Has trabajado con comisiones y objetivos?', 'booleano', 'perfil', true, self::S],
        'estilos_trabajo' => ['escolaridad', 'Tu estilo de trabajo', 'multiselect', 'perfil', true, self::S, ['opciones' => EstiloTrabajo::class, 'min' => 1]],
        // ── Perfil humano y vacante ──
        'fuente_vacante' => ['perfil', '¿Cómo supiste de la vacante?', 'select', 'perfil', true, self::S, ['opciones' => FuenteVacante::class]],
        'fuente_vacante_otro' => ['perfil', '¿Por qué otro medio supiste de la vacante?', 'texto', 'perfil', true, self::S, ['requerido_si' => ['fuente_vacante', 'igual', 'otro'], 'max' => 80]],
        'aplico_antes' => ['perfil', '¿Habías aplicado con nosotros antes?', 'booleano', 'perfil', true, self::S],
        'familiares_empresa' => ['perfil', '¿Tienes familiares en la empresa?', 'booleano', 'perfil', true, self::S],
        'familiares_empresa_nombre' => ['perfil', 'Nombre del familiar en la empresa', 'texto', 'perfil', true, self::S, ['requerido_si' => ['familiares_empresa', 'igual', true], 'max' => 150]],
        'tres_palabras' => ['perfil', 'Descríbete en 3 palabras', 'texto', 'perfil', true, self::S, ['max' => 80]],
        'fortalezas' => ['perfil', 'Principales fortalezas personales y laborales', 'texto_largo', 'perfil', true, self::S, ['max' => 300]],
        'logro_profesional' => ['perfil', 'Un logro profesional del que estés orgulloso', 'texto_largo', 'perfil', true, self::S, ['max' => 300]],
        'situacion_dificil' => ['perfil', 'Una situación difícil con un cliente y cómo la resolviste', 'texto_largo', 'perfil', true, self::S, ['max' => 300]],
        'areas_oportunidad' => ['perfil', 'Áreas de oportunidad', 'texto_largo', 'perfil', true, self::S, ['max' => 200]],
        'como_te_describen' => ['perfil', '¿Cómo te describirían tus jefes anteriores?', 'texto_largo', 'perfil', true, self::S, ['max' => 200]],
    ];

    /**
     * Listas del formato (dependientes ×6, empleos ×3, referencias ×4).
     *
     * @var array<string, array{seccion: string, etiqueta: string, singular: string, minimo: int, maximo: int, bandera_vacia: string|null, etiqueta_vacia: string|null, documentos: list<string>, campos: array<string, array<string, mixed>>}>
     */
    private const LISTAS = [
        'dependientes' => [
            'seccion' => 'dependientes', 'etiqueta' => 'Dependientes económicos', 'singular' => 'Dependiente',
            'minimo' => 0, 'maximo' => 6, 'bandera_vacia' => 'sin_dependientes', 'etiqueta_vacia' => 'No tengo dependientes económicos',
            'documentos' => self::SE,
            'campos' => [
                'nombre' => ['etiqueta' => 'Nombre(s)', 'tipo' => 'texto', 'requerido' => true, 'max' => 120],
                'apellido_paterno' => ['etiqueta' => 'Apellido paterno', 'tipo' => 'texto', 'requerido' => true, 'max' => 80],
                'apellido_materno' => ['etiqueta' => 'Apellido materno', 'tipo' => 'texto', 'requerido' => false, 'max' => 80],
                'parentesco' => ['etiqueta' => 'Parentesco', 'tipo' => 'select', 'requerido' => true, 'opciones' => ParentescoDependiente::class],
                'parentesco_otro' => ['etiqueta' => 'Otro parentesco (cuál)', 'tipo' => 'texto', 'requerido' => false, 'max' => 60],
                'fecha_nacimiento' => ['etiqueta' => 'Fecha de nacimiento', 'tipo' => 'fecha', 'requerido' => false],
                'edad' => ['etiqueta' => 'Edad (si no sabes la fecha de nacimiento)', 'tipo' => 'entero', 'requerido' => false, 'min' => 0, 'max' => 120],
                'escolaridad_ocupacion' => ['etiqueta' => 'Escolaridad u ocupación', 'tipo' => 'texto', 'requerido' => true, 'max' => 120],
            ],
        ],
        'empleos' => [
            'seccion' => 'experiencia', 'etiqueta' => 'Empleos anteriores', 'singular' => 'Empleo',
            'minimo' => 0, 'maximo' => 3, 'bandera_vacia' => 'sin_experiencia_laboral', 'etiqueta_vacia' => 'Es mi primer empleo (no tengo experiencia laboral)',
            'documentos' => self::S,
            'campos' => [
                'empresa' => ['etiqueta' => 'Empresa', 'tipo' => 'texto', 'requerido' => true, 'max' => 150],
                'puesto' => ['etiqueta' => 'Puesto', 'tipo' => 'texto', 'requerido' => true, 'max' => 120],
                'desde' => ['etiqueta' => 'Desde (mes y año)', 'tipo' => 'mes', 'requerido' => true],
                'hasta' => ['etiqueta' => 'Hasta (mes y año; vacío si sigues ahí)', 'tipo' => 'mes', 'requerido' => false],
                'funciones' => ['etiqueta' => 'Funciones', 'tipo' => 'texto_largo', 'requerido' => true, 'max' => 300],
                'motivo_salida' => ['etiqueta' => 'Motivo de salida', 'tipo' => 'texto', 'requerido' => true, 'max' => 191],
                'telefono' => ['etiqueta' => 'Teléfono de la empresa', 'tipo' => 'texto', 'requerido' => true, 'max' => 20],
            ],
        ],
        'referencias' => [
            'seccion' => 'referencias', 'etiqueta' => 'Referencias personales', 'singular' => 'Referencia',
            'minimo' => self::MINIMO_REFERENCIAS, 'maximo' => 4, 'bandera_vacia' => null, 'etiqueta_vacia' => null,
            'documentos' => self::E,
            'campos' => [
                'nombre' => ['etiqueta' => 'Nombre completo', 'tipo' => 'texto', 'requerido' => true, 'max' => 150],
                'relacion' => ['etiqueta' => 'Relación (amigo, vecino, familiar…)', 'tipo' => 'texto', 'requerido' => true, 'max' => 60],
                'telefono' => ['etiqueta' => 'Teléfono', 'tipo' => 'texto', 'requerido' => true, 'max' => 20],
                'anios_conocerlo' => ['etiqueta' => 'Años de conocerte', 'tipo' => 'entero', 'requerido' => false, 'min' => 0, 'max' => 99],
            ],
        ],
    ];

    /**
     * @return array<string, Campo>
     */
    public static function campos(): array
    {
        $campos = [];

        foreach (self::CAMPOS as $clave => $definicion) {
            $campos[$clave] = self::campo($clave);
        }

        return $campos;
    }

    /**
     * @return Campo
     */
    public static function campo(string $clave): array
    {
        $d = self::crudo($clave);
        $extra = is_array($d[6] ?? null) ? $d[6] : [];
        $si = $extra['requerido_si'] ?? null;
        $requeridoSi = null;

        if (is_array($si) && isset($si[0], $si[1])) {
            $requeridoSi = array_key_exists(2, $si) ? [(string) $si[0], (string) $si[1], $si[2]] : [(string) $si[0], (string) $si[1]];
        }

        $opciones = $extra['opciones'] ?? null;

        return [
            'campo' => $clave,
            'seccion' => (string) $d[0],
            'etiqueta' => (string) $d[1],
            'tipo' => (string) $d[2],
            'almacen' => (string) $d[3],
            'columna' => (string) ($extra['columna'] ?? $clave),
            'requerido' => (bool) $d[4],
            'requerido_si' => $requeridoSi,
            'no_aplica' => (bool) ($extra['no_aplica'] ?? false),
            'documentos' => is_array($d[5]) ? array_values(array_map('strval', $d[5])) : [],
            'opciones' => is_string($opciones) && enum_exists($opciones) ? $opciones : null,
            'max' => isset($extra['max']) ? (int) $extra['max'] : null,
            'min' => isset($extra['min']) ? (int) $extra['min'] : null,
            'ayuda' => isset($extra['ayuda']) ? (string) $extra['ayuda'] : null,
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private static function crudo(string $clave): array
    {
        return self::CAMPOS[$clave];
    }

    public static function existe(string $clave): bool
    {
        return isset(self::CAMPOS[$clave]);
    }

    /**
     * @return array<string, Campo>
     */
    public static function camposDeSeccion(string $seccion): array
    {
        return array_filter(self::campos(), fn (array $c): bool => $c['seccion'] === $seccion);
    }

    /**
     * @return array<string, Lista>
     */
    public static function listas(): array
    {
        $listas = [];

        foreach (self::LISTAS as $clave => $lista) {
            $listas[$clave] = [...$lista, 'clave' => $clave];
        }

        return $listas;
    }

    /**
     * Lista de la sección (dependientes, experiencia, referencias) o null.
     *
     * @return Lista|null
     */
    public static function listaDeSeccion(string $seccion): ?array
    {
        foreach (self::listas() as $lista) {
            if ($lista['seccion'] === $seccion) {
                return $lista;
            }
        }

        return null;
    }

    public static function existeSeccion(string $seccion): bool
    {
        return isset(self::SECCIONES[$seccion]);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function opcionesDe(?string $enum): array
    {
        if ($enum === null || ! enum_exists($enum) || ! method_exists($enum, 'opciones')) {
            return [];
        }

        $resultado = [];

        foreach ((array) $enum::opciones() as $opcion) {
            if (is_array($opcion) && isset($opcion['value'], $opcion['label'])) {
                $resultado[] = ['value' => (string) $opcion['value'], 'label' => (string) $opcion['label']];
            }
        }

        return $resultado;
    }

    /**
     * @return list<string>
     */
    public static function valoresDe(?string $enum): array
    {
        return array_map(fn (array $o): string => (string) $o['value'], self::opcionesDe($enum));
    }
}
