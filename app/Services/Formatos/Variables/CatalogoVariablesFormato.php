<?php

namespace App\Services\Formatos\Variables;

/**
 * Catálogo CENTRAL de variables que una plantilla oficial puede usar
 * (docs/FORMATOS_OFICIALES.md → "Variables"). Única fuente de verdad para:
 *  - el panel "Campos del sistema" del editor de plantillas;
 *  - la validación de los campos al guardar una versión;
 *  - la detección automática (sinónimos de etiquetas del documento);
 *  - el resolvedor (ResolvedorVariablesFormato), que calcula el valor de
 *    cada clave aquí declarada.
 *
 * Solo se exponen datos que EXISTEN en el modelo (Colaborador, Sucursal,
 * Empresa, SolicitudInterna, Prestamo, ContratoLaboral) o derivados
 * deterministas de ellos (edad, antigüedad, fechas/montos en letra via
 * formatos). Nada inventado: por ejemplo no hay "estado civil" ni
 * "apellido paterno/materno" porque el sistema no los guarda por separado
 * — si un formato los necesita, se declaran como campo manual.
 *
 * Agregar una variable: declararla aquí y calcular su valor en
 * ResolvedorVariablesFormato::resolver() (un test verifica que ambas
 * listas coinciden).
 *
 * @phpstan-type DefinicionVariable array{
 *     clave: string,
 *     etiqueta: string,
 *     grupo: string,
 *     tipo: 'texto'|'fecha'|'moneda'|'numero'|'imagen',
 *     contexto: 'sujeto'|'colaborador'|'solicitud'|'prestamo'|'contrato'|'finiquito'|'siempre',
 *     sensible: bool,
 *     sinonimos: list<string>,
 *     ejemplo: string,
 *     completar: 'expediente'|'empresa'|'sucursal'|null,
 * }
 */
class CatalogoVariablesFormato
{
    /**
     * @var array<string, string>
     */
    public const GRUPOS = [
        'personales' => 'Datos personales',
        'laborales' => 'Datos laborales',
        'empresa' => 'Empresa',
        'solicitud' => 'Solicitud',
        'prestamo' => 'Préstamo',
        'contrato' => 'Contrato',
        'finiquito' => 'Finiquito',
        'fechas' => 'Fechas',
        'otros' => 'Otros',
    ];

    /**
     * Formatos aplicables por tipo de variable (opción "Formato" del campo
     * en el editor). El primero es el predeterminado.
     *
     * @var array<string, array<string, string>>
     */
    public const FORMATOS = [
        'texto' => [
            'normal' => 'Tal cual',
            'mayusculas' => 'MAYÚSCULAS',
            'minusculas' => 'minúsculas',
            'titulo' => 'Tipo Título',
        ],
        'fecha' => [
            'corta' => '25/09/2026',
            'larga' => '25 de septiembre de 2026',
            'letra' => 'veinticinco de septiembre de dos mil veintiséis',
            'dia' => 'Solo día (25)',
            'mes' => 'Solo mes (septiembre)',
            'anio' => 'Solo año (2026)',
        ],
        'moneda' => [
            'moneda' => '$1,234.56',
            'moneda_letra' => 'MIL DOSCIENTOS TREINTA Y CUATRO PESOS 56/100 M.N.',
            'numero' => '1,234.56',
        ],
        'numero' => [
            'numero' => '12',
            'letra' => 'doce',
        ],
        'imagen' => [
            'ajustar' => 'Ajustar a la caja',
        ],
    ];

    /**
     * Placeholders históricos de las plantillas Word ({{curp}}, {{puesto}}…,
     * claude/formatos/placeholders/PLACEHOLDERS.md) → variable del catálogo.
     * Un DOCX puede usar cualquiera de las dos formas.
     *
     * @var array<string, string>
     */
    public const ALIAS_LEGACY = [
        'nombre_completo' => 'colaborador.nombre_completo',
        'nombre_colaborador' => 'colaborador.nombre',
        'apellidos_colaborador' => 'colaborador.apellidos',
        'numero_empleado' => 'laboral.numero_empleado',
        'curp' => 'colaborador.curp',
        'rfc' => 'colaborador.rfc',
        'nss' => 'colaborador.nss',
        'domicilio' => 'colaborador.domicilio',
        'telefono' => 'colaborador.telefono',
        'correo' => 'colaborador.correo',
        'correo_personal' => 'colaborador.correo_personal',
        'empresa' => 'empresa.nombre',
        'empresa_razon_social' => 'empresa.razon_social',
        'empresa_rfc' => 'empresa.rfc',
        'sucursal' => 'laboral.sucursal',
        'departamento' => 'laboral.departamento',
        'puesto' => 'laboral.puesto',
        'jefe_directo' => 'laboral.jefe',
        'gerente' => 'laboral.gerente',
        'fecha_ingreso' => 'laboral.fecha_ingreso',
        'fecha_nacimiento' => 'colaborador.fecha_nacimiento',
        'fecha_actual' => 'fecha.actual',
        'sueldo_mensual' => 'laboral.sueldo_mensual',
        'sueldo_diario' => 'laboral.sueldo_diario',
        'tipo_contratacion' => 'laboral.tipo_contratacion',
        'periodo_prueba_inicio' => 'laboral.periodo_prueba_inicio',
        'periodo_prueba_fin' => 'laboral.periodo_prueba_fin',
        'folio_solicitud' => 'solicitud.folio',
        'tipo_solicitud' => 'solicitud.tipo',
        'motivo_solicitud' => 'solicitud.motivo',
        'motivo_permiso' => 'solicitud.motivo',
        'observaciones' => 'solicitud.observaciones',
        'dias_vacaciones' => 'solicitud.dias',
        'fecha_inicio_permiso' => 'solicitud.fecha_inicio',
        'fecha_fin_permiso' => 'solicitud.fecha_fin',
        'monto_prestamo' => 'prestamo.monto_autorizado',
        'monto_solicitado_prestamo' => 'prestamo.monto_solicitado',
        'plazo_prestamo' => 'prestamo.plazo',
        'pago_prestamo' => 'prestamo.pago',
        'saldo_prestamo' => 'prestamo.saldo',
        'periodicidad_prestamo' => 'prestamo.periodicidad',
        'motivo_prestamo' => 'prestamo.motivo',
        'fecha_inicio_contrato' => 'contrato.fecha_inicio',
        'fecha_fin_contrato' => 'contrato.fecha_fin',
        'finiquito_fecha_baja' => 'finiquito.fecha_baja',
        'finiquito_antiguedad' => 'finiquito.antiguedad',
        'finiquito_sueldo_diario' => 'finiquito.sueldo_diario',
        'finiquito_sueldo_mensual' => 'finiquito.sueldo_mensual',
        'finiquito_sueldo_pendiente' => 'finiquito.sueldo_pendiente',
        'finiquito_vacaciones_pendientes' => 'finiquito.vacaciones_pendientes',
        'finiquito_prima_vacacional' => 'finiquito.prima_vacacional',
        'finiquito_aguinaldo_proporcional' => 'finiquito.aguinaldo_proporcional',
        'finiquito_indemnizacion' => 'finiquito.indemnizacion',
        'finiquito_bonos_extra' => 'finiquito.bonos_extra',
        'finiquito_descuentos' => 'finiquito.descuentos',
        'finiquito_adeudos' => 'finiquito.adeudos',
        'finiquito_otros_conceptos' => 'finiquito.otros_conceptos',
        'finiquito_total_ajustado' => 'finiquito.total',
        'finiquito_fecha_generacion' => 'fecha.actual',
    ];

    /**
     * @var list<DefinicionVariable>|null
     */
    private ?array $definiciones = null;

    /**
     * Clave del catálogo para un placeholder de DOCX: acepta la clave nueva
     * ("colaborador.curp") o el alias legacy ("curp").
     */
    public function claveDePlaceholder(string $placeholder): ?string
    {
        $placeholder = trim($placeholder);

        if ($this->existe($placeholder)) {
            return $placeholder;
        }

        return self::ALIAS_LEGACY[$placeholder] ?? null;
    }

    /**
     * @return list<DefinicionVariable>
     */
    public function definiciones(): array
    {
        return $this->definiciones ??= [
            // --- Datos personales ---
            $this->v('colaborador.nombre_completo', 'Nombre completo', 'personales', ejemplo: 'Juan Pérez García', sinonimos: ['nombre', 'nombre completo', 'nombre del colaborador', 'nombre del trabajador', 'nombre del empleado', 'nombre del solicitante', 'nombre del acreditado', 'nombre y apellidos', 'nombre del interesado'], completar: 'expediente'),
            $this->v('colaborador.nombre', 'Nombre(s)', 'personales', ejemplo: 'Juan', sinonimos: ['nombre(s)', 'nombres'], completar: 'expediente'),
            $this->v('colaborador.apellidos', 'Apellidos', 'personales', ejemplo: 'Pérez García', sinonimos: ['apellidos'], completar: 'expediente'),
            $this->v('colaborador.curp', 'CURP', 'personales', ejemplo: 'PEGJ800101HDFRRN01', sinonimos: ['curp', 'c.u.r.p.', 'clave unica de registro de poblacion'], completar: 'expediente', contexto: 'colaborador'),
            $this->v('colaborador.rfc', 'RFC', 'personales', ejemplo: 'PEGJ800101AB1', sinonimos: ['rfc', 'r.f.c.', 'registro federal de contribuyentes'], completar: 'expediente', contexto: 'colaborador'),
            $this->v('colaborador.nss', 'NSS (IMSS)', 'personales', ejemplo: '12345678901', sinonimos: ['nss', 'n.s.s.', 'numero de seguro social', 'no. de seguro social', 'no. seguro social', 'numero de afiliacion', 'imss'], completar: 'expediente', contexto: 'colaborador'),
            $this->v('colaborador.fecha_nacimiento', 'Fecha de nacimiento', 'personales', tipo: 'fecha', ejemplo: '01/01/1990', sinonimos: ['fecha de nacimiento', 'fecha nacimiento', 'f. de nacimiento'], completar: 'expediente', contexto: 'colaborador'),
            $this->v('colaborador.edad', 'Edad', 'personales', tipo: 'numero', ejemplo: '36', sinonimos: ['edad'], completar: 'expediente', contexto: 'colaborador'),
            $this->v('colaborador.genero', 'Género', 'personales', ejemplo: 'Masculino', sinonimos: ['genero', 'sexo'], completar: 'expediente', contexto: 'colaborador'),
            $this->v('colaborador.domicilio', 'Domicilio', 'personales', ejemplo: 'Calle Ejemplo 123, Col. Centro', sinonimos: ['domicilio', 'direccion', 'domicilio particular', 'domicilio actual', 'con domicilio en'], completar: 'expediente', contexto: 'colaborador'),
            $this->v('colaborador.telefono', 'Teléfono', 'personales', ejemplo: '5512345678', sinonimos: ['telefono', 'tel.', 'celular', 'telefono celular', 'numero de telefono'], completar: 'expediente'),
            $this->v('colaborador.correo', 'Correo (corporativo o personal)', 'personales', ejemplo: 'juan.perez@ejemplo.com', sinonimos: ['correo', 'correo electronico', 'e-mail', 'email'], completar: 'expediente'),
            $this->v('colaborador.correo_personal', 'Correo personal', 'personales', ejemplo: 'juan@correo.com', sinonimos: ['correo personal'], completar: 'expediente', contexto: 'colaborador'),
            $this->v('colaborador.contacto_emergencia', 'Contacto de emergencia', 'personales', ejemplo: 'María García', sinonimos: ['contacto de emergencia', 'en caso de emergencia avisar a'], completar: 'expediente', contexto: 'colaborador'),
            $this->v('colaborador.contacto_emergencia_telefono', 'Teléfono de emergencia', 'personales', ejemplo: '5587654321', sinonimos: ['telefono de emergencia'], completar: 'expediente', contexto: 'colaborador'),
            $this->v('colaborador.foto', 'Fotografía', 'personales', tipo: 'imagen', ejemplo: '', sinonimos: ['foto', 'fotografia'], completar: 'expediente', contexto: 'colaborador'),

            // --- Datos laborales ---
            $this->v('laboral.numero_empleado', 'Número de empleado', 'laborales', ejemplo: 'EMP-00123', sinonimos: ['numero de empleado', 'no. de empleado', 'no. empleado', 'num. empleado', 'clave de empleado', 'numero de nomina', 'no. de nomina'], completar: 'expediente', contexto: 'colaborador'),
            $this->v('laboral.fecha_ingreso', 'Fecha de ingreso', 'laborales', tipo: 'fecha', ejemplo: '01/03/2022', sinonimos: ['fecha de ingreso', 'fecha ingreso', 'fecha de alta', 'ingreso'], completar: 'expediente', contexto: 'colaborador'),
            $this->v('laboral.antiguedad', 'Antigüedad', 'laborales', ejemplo: '4 años, 6 meses', sinonimos: ['antiguedad'], completar: 'expediente', contexto: 'colaborador'),
            $this->v('laboral.puesto', 'Puesto', 'laborales', ejemplo: 'Gestor', sinonimos: ['puesto', 'cargo', 'puesto que desempena', 'categoria', 'puesto solicitado'], completar: 'expediente'),
            $this->v('laboral.departamento', 'Departamento', 'laborales', ejemplo: 'Ventas', sinonimos: ['departamento', 'depto.', 'area', 'area de trabajo'], completar: 'expediente'),
            $this->v('laboral.sucursal', 'Sucursal', 'laborales', ejemplo: 'Córdoba', sinonimos: ['sucursal', 'centro de trabajo', 'oficina', 'lugar de trabajo', 'plaza'], completar: 'expediente'),
            $this->v('laboral.sucursal_domicilio', 'Domicilio de la sucursal', 'laborales', ejemplo: 'Av. 1 No. 100, Centro', sinonimos: ['domicilio de la sucursal', 'domicilio del centro de trabajo'], completar: 'sucursal'),
            $this->v('laboral.sucursal_ciudad', 'Ciudad de la sucursal', 'laborales', ejemplo: 'Córdoba', sinonimos: ['ciudad', 'municipio'], completar: 'sucursal'),
            $this->v('laboral.sucursal_estado', 'Estado de la sucursal', 'laborales', ejemplo: 'Veracruz', sinonimos: ['estado', 'entidad federativa'], completar: 'sucursal'),
            $this->v('laboral.sucursal_ciudad_estado', 'Ciudad y estado de la sucursal', 'laborales', ejemplo: 'Córdoba, Veracruz', sinonimos: ['lugar', 'lugar y fecha', 'lugar de expedicion'], completar: 'sucursal'),
            $this->v('laboral.jefe', 'Jefe inmediato', 'laborales', ejemplo: 'Ana López Ruiz', sinonimos: ['jefe inmediato', 'jefe directo', 'nombre del jefe inmediato', 'nombre del jefe', 'supervisor'], completar: 'expediente', contexto: 'colaborador'),
            $this->v('laboral.gerente', 'Gerente', 'laborales', ejemplo: 'Carlos Díaz Mora', sinonimos: ['gerente', 'nombre del gerente', 'gerente de sucursal'], completar: 'expediente', contexto: 'colaborador'),
            $this->v('laboral.tipo_contratacion', 'Tipo de contratación', 'laborales', ejemplo: 'Indeterminado', sinonimos: ['tipo de contratacion', 'tipo de contrato'], completar: 'expediente', contexto: 'colaborador'),
            $this->v('laboral.periodo_prueba_inicio', 'Inicio del periodo de prueba', 'laborales', tipo: 'fecha', ejemplo: '01/03/2022', sinonimos: ['inicio del periodo de prueba'], completar: 'expediente', contexto: 'colaborador'),
            $this->v('laboral.periodo_prueba_fin', 'Fin del periodo de prueba', 'laborales', tipo: 'fecha', ejemplo: '30/05/2022', sinonimos: ['fin del periodo de prueba'], completar: 'expediente', contexto: 'colaborador'),
            $this->v('laboral.fecha_alta_imss', 'Fecha de alta en el IMSS', 'laborales', tipo: 'fecha', ejemplo: '01/03/2022', sinonimos: ['fecha de alta imss', 'alta imss'], completar: 'expediente', contexto: 'colaborador'),
            $this->v('laboral.sueldo_mensual', 'Sueldo mensual', 'laborales', tipo: 'moneda', ejemplo: '15000', sinonimos: ['sueldo mensual', 'salario mensual', 'sueldo'], completar: 'expediente', contexto: 'colaborador', sensible: true),
            $this->v('laboral.sueldo_diario', 'Sueldo diario', 'laborales', tipo: 'moneda', ejemplo: '500', sinonimos: ['sueldo diario', 'salario diario'], completar: 'expediente', contexto: 'colaborador', sensible: true),

            // --- Empresa ---
            $this->v('empresa.nombre', 'Empresa (nombre comercial)', 'empresa', ejemplo: 'MR. LANA', sinonimos: ['empresa', 'nombre de la empresa'], completar: 'empresa'),
            $this->v('empresa.razon_social', 'Razón social', 'empresa', ejemplo: 'PRODUCTOS Y SERVICIOS MR. LANA, S.A.P.I. DE C.V.', sinonimos: ['razon social', 'patron', 'el patron', 'la empresa'], completar: 'empresa'),
            $this->v('empresa.rfc', 'RFC de la empresa', 'empresa', ejemplo: 'PSM010101AB1', sinonimos: ['rfc de la empresa', 'rfc patronal'], completar: 'empresa'),
            $this->v('empresa.logo', 'Logotipo de la empresa', 'empresa', tipo: 'imagen', ejemplo: '', sinonimos: ['logo', 'logotipo'], completar: 'empresa'),

            // --- Solicitud ---
            $this->v('solicitud.folio', 'Folio de la solicitud', 'solicitud', ejemplo: 'SOL-2026-000123', sinonimos: ['folio', 'no. de folio'], contexto: 'solicitud'),
            $this->v('solicitud.tipo', 'Tipo de solicitud', 'solicitud', ejemplo: 'Permiso', sinonimos: ['tipo de solicitud', 'tipo de permiso'], contexto: 'solicitud'),
            $this->v('solicitud.estado', 'Estado de la solicitud', 'solicitud', ejemplo: 'Aprobada', sinonimos: ['estatus'], contexto: 'solicitud'),
            $this->v('solicitud.fecha', 'Fecha de la solicitud', 'solicitud', tipo: 'fecha', ejemplo: '20/09/2026', sinonimos: ['fecha de solicitud', 'fecha de elaboracion'], contexto: 'solicitud'),
            $this->v('solicitud.fecha_inicio', 'Fecha de inicio', 'solicitud', tipo: 'fecha', ejemplo: '01/10/2026', sinonimos: ['fecha de inicio', 'fecha de permiso', 'a partir del', 'del dia', 'fecha de salida', 'fecha inicio'], contexto: 'solicitud'),
            $this->v('solicitud.fecha_fin', 'Fecha de término', 'solicitud', tipo: 'fecha', ejemplo: '05/10/2026', sinonimos: ['fecha de termino', 'fecha fin', 'fecha de regreso', 'al dia', 'hasta el dia', 'fecha de reincorporacion'], contexto: 'solicitud'),
            $this->v('solicitud.dias', 'Días solicitados', 'solicitud', tipo: 'numero', ejemplo: '5', sinonimos: ['dias', 'dias solicitados', 'numero de dias', 'no. de dias', 'dias a disfrutar', 'total de dias'], contexto: 'solicitud'),
            $this->v('solicitud.motivo', 'Motivo', 'solicitud', ejemplo: 'Asunto personal', sinonimos: ['motivo', 'motivo del permiso', 'causa', 'razon'], contexto: 'solicitud'),
            $this->v('solicitud.observaciones', 'Observaciones', 'solicitud', ejemplo: 'Sin observaciones', sinonimos: ['observaciones', 'comentarios'], contexto: 'solicitud'),
            $this->v('solicitud.monto', 'Monto solicitado (solicitud)', 'solicitud', tipo: 'moneda', ejemplo: '5000', sinonimos: [], contexto: 'solicitud'),
            $this->v('solicitud.plazo_meses', 'Plazo en meses (solicitud)', 'solicitud', tipo: 'numero', ejemplo: '6', sinonimos: [], contexto: 'solicitud'),
            $this->v('solicitud.fecha_resolucion', 'Fecha de resolución', 'solicitud', tipo: 'fecha', ejemplo: '21/09/2026', sinonimos: ['fecha de autorizacion', 'fecha de aprobacion'], contexto: 'solicitud'),
            $this->v('solicitud.autorizo', 'Autorizó (revisor)', 'solicitud', ejemplo: 'Ana López Ruiz', sinonimos: ['autorizo', 'autorizado por', 'vo. bo.'], contexto: 'solicitud'),

            // --- Préstamo ---
            $this->v('prestamo.monto_solicitado', 'Monto solicitado', 'prestamo', tipo: 'moneda', ejemplo: '10000', sinonimos: ['monto solicitado', 'cantidad solicitada'], contexto: 'prestamo'),
            $this->v('prestamo.monto_autorizado', 'Monto autorizado', 'prestamo', tipo: 'moneda', ejemplo: '8000', sinonimos: ['monto autorizado', 'monto del prestamo', 'monto del credito', 'cantidad de', 'importe', 'la cantidad de', 'por la cantidad de', 'monto'], contexto: 'prestamo'),
            $this->v('prestamo.plazo', 'Plazo (número de pagos)', 'prestamo', tipo: 'numero', ejemplo: '12', sinonimos: ['plazo', 'numero de pagos', 'no. de pagos'], contexto: 'prestamo'),
            $this->v('prestamo.periodicidad', 'Periodicidad de pago', 'prestamo', ejemplo: 'Quincenal', sinonimos: ['periodicidad', 'forma de pago'], contexto: 'prestamo'),
            $this->v('prestamo.pago', 'Pago por periodo', 'prestamo', tipo: 'moneda', ejemplo: '700', sinonimos: ['pago', 'descuento', 'pago quincenal', 'pago semanal', 'abono'], contexto: 'prestamo'),
            $this->v('prestamo.saldo', 'Saldo del préstamo', 'prestamo', tipo: 'moneda', ejemplo: '8000', sinonimos: ['saldo'], contexto: 'prestamo'),
            $this->v('prestamo.fecha_solicitud', 'Fecha de solicitud del préstamo', 'prestamo', tipo: 'fecha', ejemplo: '15/09/2026', sinonimos: [], contexto: 'prestamo'),
            $this->v('prestamo.fecha_otorgamiento', 'Fecha de otorgamiento', 'prestamo', tipo: 'fecha', ejemplo: '20/09/2026', sinonimos: ['fecha de otorgamiento', 'fecha de entrega'], contexto: 'prestamo'),
            $this->v('prestamo.fecha_primer_descuento', 'Fecha del primer descuento', 'prestamo', tipo: 'fecha', ejemplo: '30/09/2026', sinonimos: ['primer descuento', 'fecha del primer pago'], contexto: 'prestamo'),
            $this->v('prestamo.motivo', 'Motivo del préstamo', 'prestamo', ejemplo: 'Gastos médicos', sinonimos: ['motivo del prestamo', 'destino del prestamo'], contexto: 'prestamo'),

            // --- Contrato ---
            $this->v('contrato.tipo', 'Tipo de contrato', 'contrato', ejemplo: 'Tiempo indeterminado', sinonimos: ['tipo de contrato', 'duracion del contrato'], contexto: 'contrato'),
            $this->v('contrato.fecha_inicio', 'Inicio del contrato', 'contrato', tipo: 'fecha', ejemplo: '01/10/2026', sinonimos: ['inicio del contrato', 'fecha de inicio del contrato', 'vigencia a partir de'], contexto: 'contrato'),
            $this->v('contrato.fecha_fin', 'Término del contrato', 'contrato', tipo: 'fecha', ejemplo: '31/12/2026', sinonimos: ['termino del contrato', 'fecha de termino del contrato', 'vigencia hasta'], contexto: 'contrato'),
            $this->v('contrato.puesto', 'Puesto del contrato', 'contrato', ejemplo: 'Gestor', sinonimos: [], contexto: 'contrato'),
            $this->v('contrato.sucursal', 'Sucursal del contrato', 'contrato', ejemplo: 'Córdoba', sinonimos: [], contexto: 'contrato'),
            $this->v('contrato.sueldo_mensual', 'Sueldo mensual del contrato', 'contrato', tipo: 'moneda', ejemplo: '15000', sinonimos: ['salario', 'salario pactado'], contexto: 'contrato', sensible: true),

            // --- Finiquito (App\Models\FiniquitoCalculo) ---
            $this->v('finiquito.fecha_baja', 'Fecha de baja', 'finiquito', tipo: 'fecha', ejemplo: '30/09/2026', sinonimos: ['fecha de baja', 'fecha de separacion'], contexto: 'finiquito'),
            $this->v('finiquito.antiguedad', 'Antigüedad al finiquito', 'finiquito', ejemplo: '2 años, 3 meses', sinonimos: [], contexto: 'finiquito'),
            $this->v('finiquito.sueldo_diario', 'Finiquito: sueldo diario', 'finiquito', tipo: 'moneda', ejemplo: '500', contexto: 'finiquito', sensible: true),
            $this->v('finiquito.sueldo_mensual', 'Finiquito: sueldo mensual', 'finiquito', tipo: 'moneda', ejemplo: '15000', contexto: 'finiquito', sensible: true),
            $this->v('finiquito.sueldo_pendiente', 'Finiquito: sueldo pendiente', 'finiquito', tipo: 'moneda', ejemplo: '0', contexto: 'finiquito', sensible: true),
            $this->v('finiquito.vacaciones_pendientes', 'Finiquito: días de vacaciones pendientes', 'finiquito', tipo: 'numero', ejemplo: '12', contexto: 'finiquito'),
            $this->v('finiquito.prima_vacacional', 'Finiquito: prima vacacional', 'finiquito', tipo: 'moneda', ejemplo: '1500', contexto: 'finiquito', sensible: true),
            $this->v('finiquito.aguinaldo_proporcional', 'Finiquito: aguinaldo proporcional', 'finiquito', tipo: 'moneda', ejemplo: '1850', contexto: 'finiquito', sensible: true),
            $this->v('finiquito.indemnizacion', 'Finiquito: indemnización', 'finiquito', tipo: 'moneda', ejemplo: '0', contexto: 'finiquito', sensible: true),
            $this->v('finiquito.bonos_extra', 'Finiquito: bonos extra', 'finiquito', tipo: 'moneda', ejemplo: '0', contexto: 'finiquito', sensible: true),
            $this->v('finiquito.descuentos', 'Finiquito: descuentos', 'finiquito', tipo: 'moneda', ejemplo: '0', contexto: 'finiquito', sensible: true),
            $this->v('finiquito.adeudos', 'Finiquito: adeudos', 'finiquito', tipo: 'moneda', ejemplo: '0', contexto: 'finiquito', sensible: true),
            $this->v('finiquito.otros_conceptos', 'Finiquito: otros conceptos', 'finiquito', tipo: 'moneda', ejemplo: '0', contexto: 'finiquito', sensible: true),
            $this->v('finiquito.total_percepciones', 'Finiquito: total percepciones', 'finiquito', tipo: 'moneda', ejemplo: '3350', contexto: 'finiquito', sensible: true),
            $this->v('finiquito.total_deducciones', 'Finiquito: total deducciones', 'finiquito', tipo: 'moneda', ejemplo: '0', contexto: 'finiquito', sensible: true),
            $this->v('finiquito.total', 'Finiquito: total a pagar', 'finiquito', tipo: 'moneda', ejemplo: '3350', sinonimos: ['total a pagar', 'neto a pagar'], contexto: 'finiquito', sensible: true),

            // --- Fechas ---
            $this->v('fecha.actual', 'Fecha de hoy', 'fechas', tipo: 'fecha', ejemplo: now()->format('d/m/Y'), sinonimos: ['fecha', 'fecha actual', 'fecha de expedicion', 'a los'], contexto: 'siempre'),

            // --- Otros ---
            $this->v('otros.generado_por', 'Nombre de quien genera', 'otros', ejemplo: 'Laura Martínez', sinonimos: ['elaboro', 'elaborado por'], contexto: 'siempre'),
            $this->v('otros.referencia', 'Referencia del documento', 'otros', ejemplo: 'FO-8K2J4M', sinonimos: ['referencia'], contexto: 'siempre'),
        ];
    }

    /**
     * @return DefinicionVariable|null
     */
    public function definicion(string $clave): ?array
    {
        foreach ($this->definiciones() as $definicion) {
            if ($definicion['clave'] === $clave) {
                return $definicion;
            }
        }

        return null;
    }

    public function existe(string $clave): bool
    {
        return $this->definicion($clave) !== null;
    }

    /**
     * @return list<string>
     */
    public function claves(): array
    {
        return array_column($this->definiciones(), 'clave');
    }

    public function formatoValido(string $tipo, ?string $formato): bool
    {
        return $formato === null || array_key_exists($formato, self::FORMATOS[$tipo] ?? []);
    }

    /**
     * Para el panel del editor y la pantalla "Variables": agrupado y sin las
     * sensibles si quien pregunta no tiene permiso de verlas.
     *
     * @return list<array{clave: string, etiqueta: string, variables: list<DefinicionVariable>}>
     */
    public function agrupadas(bool $incluirSensibles): array
    {
        $grupos = [];

        foreach (self::GRUPOS as $clave => $etiqueta) {
            $variables = array_values(array_filter(
                $this->definiciones(),
                fn (array $v) => $v['grupo'] === $clave && ($incluirSensibles || ! $v['sensible']),
            ));

            if ($variables !== []) {
                $grupos[] = ['clave' => $clave, 'etiqueta' => $etiqueta, 'variables' => $variables];
            }
        }

        return $grupos;
    }

    /**
     * @param  list<string>  $sinonimos
     * @param  'texto'|'fecha'|'moneda'|'numero'|'imagen'  $tipo
     * @param  'sujeto'|'colaborador'|'solicitud'|'prestamo'|'contrato'|'finiquito'|'siempre'  $contexto
     * @param  'expediente'|'empresa'|'sucursal'|null  $completar
     * @return DefinicionVariable
     */
    private function v(
        string $clave,
        string $etiqueta,
        string $grupo,
        string $tipo = 'texto',
        string $ejemplo = '',
        array $sinonimos = [],
        ?string $completar = null,
        string $contexto = 'sujeto',
        bool $sensible = false,
    ): array {
        return [
            'clave' => $clave,
            'etiqueta' => $etiqueta,
            'grupo' => $grupo,
            'tipo' => $tipo,
            'contexto' => $contexto,
            'sensible' => $sensible,
            'sinonimos' => $sinonimos,
            'ejemplo' => $ejemplo,
            'completar' => $completar,
        ];
    }
}
