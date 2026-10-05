<?php

/*
|--------------------------------------------------------------------------
| Documentos maestros jurídicos (motor documental)
|--------------------------------------------------------------------------
|
| Registro VERSIONADO de los formatos oficiales que entregó RH/Jurídico
| (docs/formatos_fuente/, binarios fuera del repo) y de cómo el sistema los
| prepara. Ver docs/MOTOR_DOCUMENTOS_MAESTROS.md,
| docs/INVENTARIO_FORMATOS_JURIDICOS.md y docs/MATRIZ_DOCUMENTOS_POR_FLUJO.md.
|
|   ORIGINAL  archivo exacto de Jurídico (se identifica por SHA-256, nunca
|             por nombre de archivo) → se guarda inmutable.
|   MASTER    copia técnica que el SISTEMA prepara aplicando `reglas`
|             (DOCX) o `campos` (PDF overlay). RH nunca escribe marcadores.
|   OUTPUT    documento de una persona (GeneratedDocument + snapshot).
|
| familia   = línea de un master (p. ej. contrato_capacitacion.gestor); las
|             versiones de una familia se numeran 1..n y solo una está activa.
| clave     = tipo de documento que pide el flujo (contrato_capacitacion,
|             carta_renuncia…); varias familias comparten clave y el motor
|             elige la más específica para el colaborador (puesto/grupo).
| fuentes   = SHA-256 conocidos de la familia → versión. Un archivo cuyo hash
|             no está aquí lo reporta el importador como "fuente desconocida"
|             (nunca se asigna por parecido de nombre).
|
| Reglas DOCX (App\Services\DocumentosMaestros\Docx\PreparadorMasterDocx):
|   blanco  {antes|despues}  blanco ___ / tabuladores junto a ese texto
|   entre   {desde, hasta}   todo lo que hay entre dos textos fijos
|   texto   {buscar}         dato de ejemplo / casilla ☐ ☒ / duración fija
|   celda   {etiqueta}       celda "valor" junto a la celda "Etiqueta:"
| + parrafo / fila (contexto), ocurrencia, en_parrafo, opcional.
|
| Las firmas, huellas y líneas de testigos NUNCA se mapean: se quedan en
| blanco para la firma física.
|
*/

$generales = [
    ['tipo' => 'celda', 'etiqueta' => 'Nacionalidad:', 'campo' => 'nacionalidad_mayusculas'],
    ['tipo' => 'celda', 'etiqueta' => 'Sexo:', 'campo' => 'sexo_mayusculas'],
    ['tipo' => 'celda', 'etiqueta' => 'Edad:', 'campo' => 'edad_mayusculas'],
    ['tipo' => 'celda', 'etiqueta' => 'Estado civil', 'campo' => 'estado_civil_mayusculas'],
    ['tipo' => 'celda', 'etiqueta' => 'CURP:', 'campo' => 'curp'],
    ['tipo' => 'celda', 'etiqueta' => 'RFC', 'campo' => 'rfc'],
    ['tipo' => 'celda', 'etiqueta' => 'Número de celular:', 'campo' => 'telefono'],
    ['tipo' => 'celda', 'etiqueta' => 'Correo electrónico:', 'campo' => 'correo'],
];
$nssCelda = ['tipo' => 'celda', 'etiqueta' => 'Número de seguridad social:', 'campo' => 'nss'];
$domicilioCelda = ['tipo' => 'celda', 'etiqueta' => 'Domicilio:', 'campo' => 'domicilio_mayusculas'];
$generalesCompletos = [...$generales, $nssCelda, $domicilioCelda];

$beneficiarios = [
    ['tipo' => 'blanco', 'antes' => 'se deberán cubrir a', 'campo' => 'beneficiario_nombre', 'opcional' => true],
    ['tipo' => 'blanco', 'antes' => 'quien guarda parentesco de', 'campo' => 'beneficiario_parentesco', 'opcional' => true],
];

// Contratos de capacitación inicial (39-B LFT) de puestos de sucursal.
$capacitacionSucursal = [
    ['tipo' => 'blanco', 'antes' => 'CAPITAL VARIABLE, Y', 'parrafo' => 'CONTRATO INDIVIDUAL DE TRABAJO', 'campo' => 'nombre_completo_mayusculas'],
    ['tipo' => 'blanco', 'despues' => ', en lo sucesivo, el trabajador', 'campo' => 'nombre_completo_mayusculas'],
    ...$generalesCompletos,
    ['tipo' => 'blanco', 'antes' => 'con esmero y eficiencia en el domicilio ubicado en', 'campo' => 'sucursal_domicilio'],
    ['tipo' => 'blanco', 'antes' => 'en el cual está establecida la sucursal', 'campo' => 'sucursal_nombre'],
    ['tipo' => 'blanco', 'antes' => 'salario mensual bruto de $', 'campo' => 'sueldo_mensual_numero'],
    ['tipo' => 'entre', 'desde' => 'y culminará el día', 'hasta' => '.', 'parrafo' => 'DE LA VIGENCIA DEL CONTRATO', 'campo' => 'fecha_fin_contrato_larga'],
    ...$beneficiarios,
    ['tipo' => 'entre', 'desde' => 'lo firman en', 'hasta' => '.', 'campo' => 'lugar_y_fecha_inicio_contrato'],
    ['tipo' => 'blanco', 'antes' => 'de fecha', 'parrafo' => 'La presente hoja de firmas', 'campo' => 'fecha_inicio_contrato_larga'],
    ['tipo' => 'blanco', 'antes' => 'Capital Variable y', 'parrafo' => 'La presente hoja de firmas', 'campo' => 'nombre_completo_mayusculas'],
];

// Contratos por tiempo indeterminado de puestos de sucursal.
$indeterminadoSucursal = [
    ['tipo' => 'entre', 'desde' => 'CAPITAL VARIABLE Y', 'hasta' => '.', 'parrafo' => 'POR TIEMPO INDETERMINADO CELEBRADO', 'campo' => 'nombre_completo_mayusculas'],
    ['tipo' => 'entre', 'desde' => 'II)', 'hasta' => ', en lo sucesivo, el trabajador', 'campo' => 'nombre_completo_mayusculas'],
    ...$generales,
    ['tipo' => 'celda', 'etiqueta' => 'Número de Seguridad social:', 'campo' => 'nss'],
    $domicilioCelda,
    ['tipo' => 'entre', 'desde' => 'se computará a partir del día', 'hasta' => ', fecha en la cual', 'campo' => 'fecha_ingreso_larga'],
    ['tipo' => 'blanco', 'antes' => 'Las partes reconocen que el puesto de', 'campo' => 'puesto'],
    ['tipo' => 'entre', 'desde' => 'con esmero y eficiencia en el domicilio ubicado en', 'hasta' => ', en el cual está establecida', 'campo' => 'sucursal_domicilio'],
    ['tipo' => 'entre', 'desde' => 'en el cual está establecida la sucursal', 'hasta' => ', perteneciente', 'campo' => 'sucursal_nombre'],
    ['tipo' => 'blanco', 'antes' => 'salario mensual bruto de $', 'campo' => 'sueldo_mensual_numero'],
    ...$beneficiarios,
    ['tipo' => 'entre', 'desde' => 'lo firman en', 'hasta' => '.', 'campo' => 'lugar_y_fecha_inicio_contrato'],
    ['tipo' => 'blanco', 'antes' => 'C.', 'fila' => 'POR EL TRABAJADOR', 'campo' => 'nombre_completo_mayusculas'],
    ['tipo' => 'entre', 'desde' => 'de fecha', 'hasta' => ', celebrado', 'parrafo' => 'La presente hoja de firmas', 'campo' => 'fecha_inicio_contrato_larga'],
    ['tipo' => 'entre', 'desde' => 'Capital Variable y', 'hasta' => '.', 'parrafo' => 'La presente hoja de firmas', 'campo' => 'nombre_completo_mayusculas'],
];

// Convenios de confidencialidad 2026 (misma estructura para todos los puestos).
$declaracionReceptor = [
    ['tipo' => 'blanco', 'antes' => 'de nacionalidad:', 'campo' => 'nacionalidad'],
    ['tipo' => 'blanco', 'antes' => 'haber nacido; en:', 'campo' => 'lugar_nacimiento'],
    ['tipo' => 'blanco', 'antes' => 'sexo:', 'parrafo' => 'Ser una persona física', 'campo' => 'sexo'],
    ['tipo' => 'blanco', 'antes' => 'con Clavel de Elector:', 'campo' => 'clave_elector'],
    ['tipo' => 'blanco', 'antes' => 'Registro Federal de Contribuyentes:', 'campo' => 'rfc'],
    ['tipo' => 'blanco', 'antes' => 'Clave Única de Registro de Población:', 'campo' => 'curp'],
    ['tipo' => 'blanco', 'antes' => ', de', 'parrafo' => 'Ser una persona física', 'campo' => 'edad'],
    ['tipo' => 'blanco', 'antes' => 'estado civil', 'parrafo' => 'Ser una persona física', 'campo' => 'estado_civil'],
    ['tipo' => 'blanco', 'antes' => 'con domicilio en el ubicado en', 'parrafo' => 'Ser una persona física', 'campo' => 'domicilio'],
    ['tipo' => 'blanco', 'antes' => 'y de profesión:', 'campo' => 'profesion'],
    ['tipo' => 'blanco', 'antes' => 'número celular', 'campo' => 'telefono'],
    ['tipo' => 'blanco', 'antes' => 'y correo electrónico:', 'campo' => 'correo'],
];
$confidencialidad2026 = [
    ['tipo' => 'blanco', 'antes' => 'EL C.', 'parrafo' => 'QUE CELEBRAN POR UNA PARTE', 'campo' => 'nombre_completo_mayusculas'],
    ['tipo' => 'texto', 'buscar' => 'dos meses', 'parrafo' => 'relación laboral por capacitación inicial de', 'campo' => 'duracion_capacitacion_letra'],
    ...$declaracionReceptor,
    ['tipo' => 'texto', 'buscar' => 'DOS MESES', 'parrafo' => 'No padecer enfermedad', 'campo' => 'duracion_capacitacion_letra_mayusculas'],
    ['tipo' => 'blanco', 'antes' => 'EL RECEPTOR" en el ubicado en:', 'campo' => 'domicilio'],
    ['tipo' => 'blanco', 'antes' => 'C.', 'fila' => 'EL RECEPTOR', 'campo' => 'nombre_completo_mayusculas'],
];

// Acuerdos de no competencia (anexo 1 del contrato de capacitación).
$noCompetenciaDeclaracion = [
    ['tipo' => 'blanco', 'antes' => 'de nacionalidad', 'parrafo' => 'Ser una persona física', 'campo' => 'nacionalidad'],
    ['tipo' => 'entre', 'desde' => 'haber nacido en', 'hasta' => ', con Clavel', 'campo' => 'lugar_nacimiento'],
    ['tipo' => 'blanco', 'antes' => 'con Clavel de Elector', 'campo' => 'clave_elector'],
    ['tipo' => 'blanco', 'antes' => 'Registro Federal de Contribuyentes', 'campo' => 'rfc'],
    ['tipo' => 'blanco', 'antes' => 'Clave Única de Registro de Población', 'campo' => 'curp'],
    // El único "de" seguido de blanco que queda en la declaración es la edad.
    ['tipo' => 'blanco', 'antes' => ' de', 'parrafo' => 'años de edad', 'campo' => 'edad_numero'],
    ['tipo' => 'blanco', 'antes' => 'estado civil', 'parrafo' => 'Ser una persona física', 'campo' => 'estado_civil'],
];

$fechasFijas = ['28 de noviembre de 2023'];

return [

    // Disco privado (NAS) donde viven originales, masters y outputs. Nunca
    // se expone: toda descarga pasa por un endpoint autorizado.
    'disk' => env('DOCUMENTOS_MAESTROS_DISK', 'nas'),
    'carpeta' => 'documentos-maestros',

    // Carpeta de la que lee php artisan people:importar-formatos-juridicos.
    'carpeta_fuente' => env('DOCUMENTOS_MAESTROS_FUENTE', base_path('docs/formatos_fuente')),

    /*
    | QA visual por VERSIÓN del master (App\Services\DocumentosMaestros\
    | Calidad\ValidacionVisualMaestroService). Corre al importar/cargar una
    | versión, cuando RH pulsa "Probar diseño" y antes de activar — nunca en
    | cada generación de un colaborador.
    |
    |   umbral_identidad  ORIGINAL vs master rellenado con el texto original:
    |                     debe verse idéntico (mide la preparación del master).
    |   umbral_bandas     encabezado/pie (logo, membrete) con datos largos.
    |   umbral_overlay    PDF original vs overlay, fuera de las cajas de campo.
    |   reflow_minimo     similitud mínima de cuerpo con datos largos (debajo
    |                     de esto se considera "reflow fuerte").
    */
    'validacion_visual' => [
        'al_importar' => (bool) env('DOCUMENTOS_QA_AL_IMPORTAR', true),
        'dpi' => 40,
        'umbral_identidad' => 0.985,
        'umbral_bandas' => 0.97,
        'umbral_overlay' => 0.985,
        'reflow_minimo' => 0.35,
        'pdftoppm' => env('DOCUMENTOS_QA_PDFTOPPM_PATH'),
        'script_windows' => resource_path('scripts/pdf-a-png-windows.ps1'),
        // Solo pruebas: lista fija de familias instaladas (null = detectar).
        'fuentes_disponibles' => null,
    ],

    /*
    | Valores de empresa usados SOLO cuando la empresa no los tiene
    | capturados (Empresas → domicilio fiscal / representante). Salen de los
    | propios formatos de Jurídico 2026 (no se inventan). Ver
    | docs/MOTOR_DOCUMENTOS_MAESTROS.md § Representante.
    */
    'empresa_defecto' => [
        'domicilio' => 'Subida al Club, número 114, colonia Reforma, C.P. 62260, Cuernavaca, Morelos',
        'representante_legal_nombre' => 'LESLI MARIBEL RODRÍGUEZ HERRERA',
        'representante_legal_cargo' => 'Representante Legal',
    ],

    /*
    | Domicilio del patrón que se imprime donde el formato dice «…el ubicado
    | en ____»: 'fiscal' (empresa) o 'sucursal' (la del colaborador; si no
    | tiene domicilio capturado se usa el fiscal). RH lo cambia en
    | Administración → Configuración → Parámetros de RH.
    */
    'domicilio_patron' => 'fiscal',

    /*
    | Grupo documental del puesto: decide qué variante de contrato le toca.
    | Se carga en puestos.grupo_documental (editable en el catálogo de
    | puestos); aquí solo el valor inicial por nombre de puesto.
    */
    'grupos' => [
        'gestor' => 'Gestor',
        'gerente' => 'Gerente de sucursal',
        'subgerente' => 'Subgerente',
        'regional' => 'Gerente regional',
        'coordinadora' => 'Coordinadora',
        'administrativo_confianza' => 'Administrativo (confianza)',
        'administrativo_no_confianza' => 'Administrativo (no confianza)',
    ],

    'grupos_por_puesto' => [
        'Gestor' => 'gestor',
        'Gestor grupal' => 'gestor',
        'Gestor Volante' => 'gestor',
        'Gerente de Sucursal' => 'gerente',
        'Subgerente' => 'subgerente',
        'Gerente Regional Q1' => 'regional',
        'Gerente Regional Q3' => 'regional',
        'Coordinadora de Sucursal' => 'coordinadora',
        'Coordinadora Regional' => 'coordinadora',
        // Confianza: "manejo de información confidencial, apoyo directo a la
        // Dirección General y funciones de coordinación administrativa".
        'Dirección General' => 'administrativo_confianza',
        'Dirección Comercial' => 'administrativo_confianza',
        'Asistente de Dirección General' => 'administrativo_confianza',
        'Asistente de Dirección Comercial' => 'administrativo_confianza',
        'Gerencia de Recursos Humanos' => 'administrativo_confianza',
        'Gerente de Contraloría' => 'administrativo_confianza',
        'Gerente de Mesa de Control' => 'administrativo_confianza',
        'Contador' => 'administrativo_confianza',
        'Tesorero' => 'administrativo_confianza',
        'Responsable de Sistemas' => 'administrativo_confianza',
        'Administración de Personal' => 'administrativo_confianza',
        'Analista de Mesa de Control' => 'administrativo_no_confianza',
        'Monitorista' => 'administrativo_no_confianza',
        'Reclutamiento' => 'administrativo_confianza',
    ],

    'procesos' => [
        'alta' => 'Contratación',
        'renovacion' => 'Renovación / continuidad',
        'evaluacion' => 'Evaluación de capacitación',
        'baja' => 'Baja / cierre laboral',
        'negativa_firma' => 'Negativa de firma',
        'permiso' => 'Permiso',
        'prestamo' => 'Préstamo',
        'activos' => 'Entrega de activos',
        'referencia' => 'Referencia (no se genera)',
    ],

    /*
    | Paquete de documentos por proceso y grupo de puesto. El primer
    | elemento del paquete de alta es el contrato principal.
    */
    'paquetes' => [
        'alta' => [
            'capacitacion_inicial' => [
                'gestor' => ['contrato_capacitacion', 'contrato_confidencialidad', 'contrato_no_competencia'],
                'gerente' => ['contrato_capacitacion', 'contrato_confidencialidad', 'contrato_no_competencia'],
                'subgerente' => ['contrato_capacitacion', 'contrato_confidencialidad'],
                'regional' => ['contrato_capacitacion', 'contrato_confidencialidad'],
                'coordinadora' => ['contrato_capacitacion', 'contrato_confidencialidad'],
                'administrativo_confianza' => ['contrato_capacitacion', 'contrato_confidencialidad'],
                'administrativo_no_confianza' => ['contrato_capacitacion', 'contrato_confidencialidad'],
                // Puestos sin grupo documental (Sistemas, Dirección…): la
                // confidencialidad general siempre; el contrato de
                // capacitación aparece como "formato no cargado" hasta que
                // Jurídico entregue uno para ellos.
                '*' => ['contrato_capacitacion', 'contrato_confidencialidad'],
            ],
            // Contratación directa por tiempo indeterminado (sin capacitación).
            // La confidencialidad es para todos; la no competencia, para los
            // grupos que la firman (Gestor, Gerente) en cualquier modalidad.
            'indeterminado' => [
                'gestor' => ['contrato_indeterminado', 'contrato_confidencialidad', 'contrato_no_competencia'],
                'gerente' => ['contrato_indeterminado', 'contrato_confidencialidad', 'contrato_no_competencia'],
                '*' => ['contrato_indeterminado', 'contrato_confidencialidad'],
            ],
            // Periodo de prueba (39-A) y tiempo determinado: Jurídico no ha
            // entregado el contrato. Se muestra como "formato no cargado";
            // los convenios sí se generan.
            'periodo_prueba' => [
                'gestor' => ['contrato_periodo_prueba', 'contrato_confidencialidad', 'contrato_no_competencia'],
                'gerente' => ['contrato_periodo_prueba', 'contrato_confidencialidad', 'contrato_no_competencia'],
                '*' => ['contrato_periodo_prueba', 'contrato_confidencialidad'],
            ],
            'tiempo_determinado' => [
                'gestor' => ['contrato_tiempo_determinado', 'contrato_confidencialidad', 'contrato_no_competencia'],
                'gerente' => ['contrato_tiempo_determinado', 'contrato_confidencialidad', 'contrato_no_competencia'],
                '*' => ['contrato_tiempo_determinado', 'contrato_confidencialidad'],
            ],
        ],
        'renovacion' => ['*' => ['contrato_indeterminado']],
    ],

    /*
    | Fallback a la variante GENERAL (sin puesto/grupo) por tipo de
    | documento: solo para los grupos listados ("*" = cualquier puesto). Sin
    | entrada = nunca se usa la general ("formato no cargado").
    */
    'fallback_general' => [
        // RH: el convenio de confidencialidad es para TODOS los puestos; los
        // que no tienen variante propia (Regional, Sistemas, Dirección…) firman
        // el general.
        'contrato_confidencialidad' => ['*'],
    ],

    /*
    | Documentos del cierre laboral según la causa (tipo_baja). El finiquito
    | lo produce el módulo de finiquitos (cálculo + respaldo) y aparece en
    | la misma tarjeta "Documentos de la baja".
    */
    'documentos_por_causa' => [
        'renuncia' => ['carta_renuncia'],
        'no_renovacion' => ['evaluacion_capacitacion', 'aviso_terminacion'],
        'fin_contrato' => ['evaluacion_capacitacion', 'aviso_terminacion'],
    ],

    /*
    | Quién puede GENERAR cada documento (además del alcance sobre la
    | persona, que siempre se valida). RH (documentos_laborales.generar)
    | genera todo; el gerente genera en su ámbito la renuncia (con el
    | colaborador presente) y el formato de permiso aprobado.
    */
    'permisos_generacion' => [
        '*' => ['documentos_laborales.generar'],
        'carta_renuncia' => ['documentos_laborales.generar', 'cierres.solicitar'],
        'formato_permiso' => ['documentos_laborales.generar', 'documentos_laborales.operar_fisico'],
        // Quien autoriza el préstamo (p. ej. Dirección) genera sus documentos
        // al autorizarlo: contrato de crédito, pagaré y carta de retención.
        'prestamo_contrato' => ['documentos_laborales.generar', 'prestamos.autorizar'],
        'prestamo_pagare' => ['documentos_laborales.generar', 'prestamos.autorizar'],
        'prestamo_consentimiento_retencion' => ['documentos_laborales.generar', 'prestamos.autorizar'],
    ],

    /*
    | Cobertura documental por puesto (Administración → Documentos maestros
    | → Cobertura). Columnas que se muestran por puesto y cuáles cuentan
    | para marcar un puesto como "incompleto": solo las modalidades que la
    | empresa usa hoy (capacitación inicial 39-B y su renovación por tiempo
    | indeterminado). Periodo de prueba y tiempo determinado se muestran,
    | pero Jurídico no ha entregado esos contratos: no cuentan como faltante
    | hasta que se agreguen aquí.
    */
    'cobertura' => [
        'columnas' => [
            'capacitacion' => ['etiqueta' => 'Capacitación inicial', 'clave' => 'contrato_capacitacion', 'modalidades' => ['alta.capacitacion_inicial']],
            'periodo_prueba' => ['etiqueta' => 'Periodo de prueba', 'clave' => 'contrato_periodo_prueba', 'modalidades' => ['alta.periodo_prueba']],
            'tiempo_determinado' => ['etiqueta' => 'Tiempo determinado', 'clave' => 'contrato_tiempo_determinado', 'modalidades' => ['alta.tiempo_determinado']],
            'indeterminado' => ['etiqueta' => 'Indeterminado', 'clave' => 'contrato_indeterminado', 'modalidades' => ['alta.indeterminado', 'renovacion']],
            'confidencialidad' => ['etiqueta' => 'Confidencialidad', 'clave' => 'contrato_confidencialidad', 'modalidades' => ['alta.capacitacion_inicial', 'alta.indeterminado']],
            'no_competencia' => ['etiqueta' => 'No competencia', 'clave' => 'contrato_no_competencia', 'modalidades' => ['alta.capacitacion_inicial', 'alta.indeterminado']],
            'responsiva' => ['etiqueta' => 'Responsivas', 'clave' => 'carta_responsiva', 'modalidades' => []],
        ],
        'requeridas' => ['capacitacion', 'indeterminado', 'confidencialidad', 'no_competencia'],
    ],

    // false: el paquete de contratación se genera desde la ficha del
    // colaborador ("Generar paquete de contratación") cuando el expediente
    // queda completo. true: se genera solo al completarse el expediente.
    'alta_generacion_automatica' => (bool) env('DOCUMENTOS_ALTA_GENERACION_AUTOMATICA', false),

    // Rama de la Fase 3 escenario B del procedimiento integral de baja.
    'documentos_negativa' => ['acta_negativa_firma'],

    'documentos_prestamo' => ['prestamo_contrato', 'prestamo_pagare', 'prestamo_consentimiento_retencion'],

    // Tipos de solicitud que imprimen el FORMATO PERMISO MR. LANA.
    'tipos_solicitud_permiso' => ['permiso_con_goce', 'permiso_sin_goce', 'permiso_tiempo', 'salida_temprano', 'llegada_tarde', 'permiso_especial_cumpleanos', 'permiso_especial_paternidad', 'permiso_especial_fallecimiento'],

    /*
    |--------------------------------------------------------------------------
    | Masters
    |--------------------------------------------------------------------------
    */
    'documentos' => [

        // ───────────────────────── CONTRATOS DE CAPACITACIÓN INICIAL ─────────────────────────
        'contrato_capacitacion.gestor' => [
            'clave' => 'contrato_capacitacion',
            'nombre' => 'Contrato de capacitación inicial — Gestor',
            'proceso' => 'alta', 'evento' => 'contratacion', 'grupos' => ['gestor'], 'motor' => 'docx', 'categoria' => 'contratos',
            'fuentes' => ['0717b1b00fedfef61480a0a8371650f1a766887a7cd2ab475640684e9eca2711' => ['version' => 1, 'activa' => true]],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_huella' => true, 'requiere_envio_corporativo' => true],
            'representante' => 'fijo_juridico',
            'reglas' => [
                ['tipo' => 'blanco', 'antes' => 'CAPITAL VARIABLE, Y', 'parrafo' => 'CONTRATO INDIVIDUAL DE TRABAJO', 'campo' => 'nombre_completo_mayusculas'],
                ['tipo' => 'blanco', 'antes' => 'notificaciones el ubicado en', 'campo' => 'empresa_domicilio'],
                ['tipo' => 'blanco', 'despues' => ', en lo sucesivo, el trabajador', 'campo' => 'nombre_completo_mayusculas'],
                ...$generalesCompletos,
                ['tipo' => 'blanco', 'antes' => 'con esmero y eficiencia en el domicilio ubicado en', 'campo' => 'sucursal_domicilio_con_nombre'],
                ['tipo' => 'blanco', 'antes' => 'salario mensual bruto de $', 'campo' => 'sueldo_mensual_numero'],
                ['tipo' => 'texto', 'buscar' => 'dos meses', 'parrafo' => 'DE LA VIGENCIA DEL CONTRATO', 'campo' => 'duracion_letra'],
                ['tipo' => 'blanco', 'antes' => 'y culminará el día', 'campo' => 'fecha_fin_contrato_larga'],
                ...$beneficiarios,
                ['tipo' => 'blanco', 'antes' => 'lo firman en', 'campo' => 'lugar_firma'],
                ['tipo' => 'blanco', 'antes' => ' A', 'parrafo' => 'lo firman en', 'campo' => 'fecha_inicio_contrato_larga'],
                ['tipo' => 'blanco', 'antes' => 'de fecha', 'parrafo' => 'La presente hoja de firmas', 'campo' => 'fecha_inicio_contrato_larga'],
                ['tipo' => 'blanco', 'antes' => 'Capital Variable y', 'parrafo' => 'La presente hoja de firmas', 'campo' => 'nombre_completo_mayusculas'],
            ],
            'fechas_fijas' => $fechasFijas,
            'observaciones' => [
                'Cláusula DÉCIMA CUARTA: la duración ("dos meses") se toma de la configuración del puesto (puestos.meses_periodo_prueba), no del texto del ejemplo.',
                'El original contiene resaltado verde (marcas de revisión); se conserva tal cual porque forma parte del archivo entregado.',
                'Bloque de firmas: la línea bajo "EL TRABAJADOR" es línea de firma; el formato no trae renglón para el nombre impreso.',
            ],
        ],

        'contrato_capacitacion.gerente' => [
            'clave' => 'contrato_capacitacion',
            'nombre' => 'Contrato de capacitación inicial — Gerente de sucursal',
            'proceso' => 'alta', 'evento' => 'contratacion', 'grupos' => ['gerente'], 'motor' => 'docx', 'categoria' => 'contratos',
            'fuentes' => [
                '19407fad47d1ac593eded0785c3efe9c18f8c46caf44207c0d9ea6c685d6ffb5' => ['version' => 1, 'activa' => false, 'nota' => 'Versión RH 07/01/2026: domicilio del patrón ya escrito, vigencia "Dos meses" (no corresponde a gerente) y sin el párrafo de finalidad de la capacitación. Sustituida por la versión de Jurídico del 15/01/2026.'],
                '1cb161c0419c06a9d8f7bc9b70540bbd383b2f84b50ea27f125c58423196fefd' => ['version' => 2, 'activa' => true],
            ],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_huella' => true, 'requiere_envio_corporativo' => true],
            'representante' => 'fijo_juridico',
            'reglas_por_version' => [
                1 => [
                    ...$capacitacionSucursal,
                    ['tipo' => 'texto', 'buscar' => 'Dos meses', 'parrafo' => 'DE LA VIGENCIA DEL CONTRATO', 'campo' => 'duracion_letra'],
                ],
            ],
            'reglas' => [
                ['tipo' => 'blanco', 'antes' => 'notificaciones el ubicado', 'campo' => 'empresa_domicilio'],
                ...$capacitacionSucursal,
                ['tipo' => 'texto', 'buscar' => 'tres meses', 'parrafo' => 'DE LA VIGENCIA DEL CONTRATO', 'campo' => 'duracion_letra'],
            ],
            'fechas_fijas' => $fechasFijas,
            'observaciones' => [
                'v2: la cláusula PRIMERA inicia con "DEL OBJETO. .  Las partes acuerdan…" (punto duplicado y el texto del artículo 39-B quedó después del párrafo de finalidad). Se conserva tal cual; revisar redacción con Jurídico.',
                'Cláusula "lo firman en ___ de ___ de 20__": el sistema llena el bloque completo con lugar y fecha ("Cuernavaca, Morelos, el 4 de octubre de 2026").',
            ],
        ],

        'contrato_capacitacion.subgerente' => [
            'clave' => 'contrato_capacitacion',
            'nombre' => 'Contrato de capacitación inicial — Subgerente',
            'proceso' => 'alta', 'evento' => 'contratacion', 'grupos' => ['subgerente'], 'motor' => 'docx', 'categoria' => 'contratos',
            'fuentes' => ['bc6a3a2e10e822343afc2da818f234bd410f5f54301661537d60229c90831503' => ['version' => 1, 'activa' => true]],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_huella' => true, 'requiere_envio_corporativo' => true],
            'representante' => 'fijo_juridico',
            'reglas' => [
                ['tipo' => 'blanco', 'antes' => 'notificaciones el ubicado en', 'campo' => 'empresa_domicilio'],
                ...$capacitacionSucursal,
                ['tipo' => 'texto', 'buscar' => 'tres meses', 'parrafo' => 'DE LA VIGENCIA DEL CONTRATO', 'campo' => 'duracion_letra'],
            ],
            'fechas_fijas' => $fechasFijas,
        ],

        'contrato_capacitacion.regional' => [
            'clave' => 'contrato_capacitacion',
            'nombre' => 'Contrato de capacitación inicial — Gerente regional',
            'proceso' => 'alta', 'evento' => 'contratacion', 'grupos' => ['regional'], 'motor' => 'docx', 'categoria' => 'contratos',
            'fuentes' => ['14d25f64da0c962eb3a8b0ebda6ea5a4de00ef51cda856d8df34ed25db8784ee' => ['version' => 1, 'activa' => true]],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_huella' => true, 'requiere_envio_corporativo' => true],
            'representante' => 'fijo_juridico',
            'reglas' => [
                ...$capacitacionSucursal,
                ['tipo' => 'texto', 'buscar' => 'tres meses', 'parrafo' => 'DE LA VIGENCIA DEL CONTRATO', 'campo' => 'duracion_letra'],
            ],
            'fechas_fijas' => $fechasFijas,
            'observaciones' => [
                'Formato de 2024 (autor J. L. Rosales): el domicilio legal del patrón está escrito como "avenida Domingo Diez, número 1003, piso 3, colonia el Empleado" (domicilio anterior); los formatos 2026 usan Subida al Club 114. Se conserva tal cual: confirmar con Jurídico.',
            ],
        ],

        'contrato_capacitacion.coordinadora' => [
            'clave' => 'contrato_capacitacion',
            'nombre' => 'Contrato de capacitación inicial — Coordinadora administrativa',
            'proceso' => 'alta', 'evento' => 'contratacion', 'grupos' => ['coordinadora'], 'motor' => 'docx', 'categoria' => 'contratos',
            'fuentes' => ['98d05bc162d2e02fe15804a14bb79d0d3af7d3d2fe6327be97aaae40535c2527' => ['version' => 1, 'activa' => true]],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_huella' => true, 'requiere_envio_corporativo' => true],
            'representante' => 'fijo_juridico',
            'reglas' => [
                ['tipo' => 'blanco', 'antes' => 'CAPITAL VARIABLE, Y', 'parrafo' => 'CONTRATO INDIVIDUAL DE TRABAJO', 'campo' => 'nombre_completo_mayusculas'],
                ['tipo' => 'blanco', 'antes' => 'notificaciones el ubicado en', 'campo' => 'empresa_domicilio'],
                ['tipo' => 'blanco', 'despues' => ', en lo sucesivo, el trabajador', 'campo' => 'nombre_completo_mayusculas'],
                ...$generalesCompletos,
                ['tipo' => 'blanco', 'antes' => 'con esmero y eficiencia en el domicilio ubicado en', 'campo' => 'sucursal_domicilio'],
                ['tipo' => 'blanco', 'antes' => 'en el cual está establecida la sucursal', 'campo' => 'sucursal_nombre'],
                ['tipo' => 'texto', 'buscar' => 'tres meses', 'parrafo' => 'DE LA VIGENCIA DEL CONTRATO', 'campo' => 'duracion_letra'],
                ['tipo' => 'blanco', 'antes' => 'y culminará el día', 'campo' => 'fecha_fin_contrato_larga'],
                ...$beneficiarios,
                ['tipo' => 'blanco', 'antes' => 'lo firman el', 'campo' => 'fecha_inicio_contrato_larga'],
                ['tipo' => 'blanco', 'antes' => 'C.', 'campo' => 'nombre_completo_mayusculas'],
                ['tipo' => 'blanco', 'antes' => 'de fecha', 'parrafo' => 'La presente hoja de firmas', 'campo' => 'fecha_inicio_contrato_larga'],
                ['tipo' => 'blanco', 'antes' => 'Capital Variable y', 'parrafo' => 'La presente hoja de firmas', 'campo' => 'nombre_completo_mayusculas'],
            ],
            'fechas_fijas' => $fechasFijas,
            'observaciones' => [
                'El nombre del archivo ("CONTRATO CAPACITACION INICIAL") no indica el puesto; el contenido es para "Coordinadora Administrativa". PEOPLE lo asocia al grupo coordinadora (Coordinadora de Sucursal / Coordinadora Regional): confirmar con RH.',
                'La cláusula NOVENA de este formato no trae blanco de salario: se imprime tal cual.',
            ],
        ],

        'contrato_capacitacion.administrativo' => [
            'clave' => 'contrato_capacitacion',
            'nombre' => 'Contrato de capacitación inicial — Administrativos',
            'proceso' => 'alta', 'evento' => 'contratacion', 'grupos' => ['administrativo_confianza', 'administrativo_no_confianza'], 'motor' => 'docx', 'categoria' => 'contratos',
            'fuentes' => ['2358611f5f3cd6a3c7b1b223acdbb58dea8f9e3801f248f8d517d299a4bb9f76' => ['version' => 1, 'activa' => true]],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_huella' => true, 'requiere_envio_corporativo' => true],
            'representante' => 'fijo_juridico',
            'reglas' => [
                ['tipo' => 'entre', 'desde' => 'CAPITAL VARIABLE, Y', 'hasta' => '.', 'parrafo' => 'CONTRATO INDIVIDUAL DE TRABAJO', 'campo' => 'nombre_completo_mayusculas'],
                ['tipo' => 'blanco', 'antes' => 'cubrir el puesto de', 'campo' => 'puesto'],
                ['tipo' => 'blanco', 'despues' => ', en lo sucesivo, el trabajador', 'campo' => 'nombre_completo_mayusculas'],
                ...$generalesCompletos,
                ['tipo' => 'blanco', 'antes' => 'asigna al trabajador el puesto de', 'campo' => 'puesto'],
                ['tipo' => 'blanco', 'antes' => 'salario mensual bruto de $', 'campo' => 'sueldo_mensual_numero'],
                ['tipo' => 'texto', 'buscar' => 'dos meses', 'parrafo' => 'DE LA VIGENCIA DEL CONTRATO', 'campo' => 'duracion_letra'],
                ['tipo' => 'blanco', 'antes' => 'y culminará el día', 'campo' => 'fecha_fin_contrato_larga'],
                ...$beneficiarios,
                ['tipo' => 'blanco', 'antes' => 'el día', 'parrafo' => 'lo firman en', 'campo' => 'fecha_inicio_contrato_larga'],
                ['tipo' => 'blanco', 'antes' => 'C.', 'campo' => 'nombre_completo_mayusculas'],
                ['tipo' => 'blanco', 'antes' => 'de fecha', 'parrafo' => 'La presente hoja de firmas', 'campo' => 'fecha_inicio_contrato_larga'],
                ['tipo' => 'blanco', 'antes' => 'Capital Variable y', 'parrafo' => 'La presente hoja de firmas', 'campo' => 'nombre_completo_mayusculas'],
            ],
            'ignorar' => ['Subida al Club 114, Col. Reforma', 'CORPORATIVO'],
            'fechas_fijas' => $fechasFijas,
            'observaciones' => [
                'Lugar de trabajo y lugar de firma vienen escritos para el CORPORATIVO (Subida al Club 114) con guiones bajos alrededor ("CP. 62260_", "__CORPORATIVO__"); se conservan como texto fijo.',
            ],
        ],

        // ───────────────────────── CONTRATOS POR TIEMPO INDETERMINADO ─────────────────────────
        'contrato_indeterminado.gestor' => [
            'clave' => 'contrato_indeterminado',
            'nombre' => 'Contrato por tiempo indeterminado — Gestor',
            'proceso' => 'renovacion', 'evento' => 'renovacion', 'grupos' => ['gestor'], 'motor' => 'docx', 'categoria' => 'contratos',
            'fuentes' => ['e1e276d2f3bb4484a25835442508f99a78fadc094ecc568eb81556e870f20c44' => ['version' => 1, 'activa' => true]],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_huella' => true, 'requiere_envio_corporativo' => true],
            'representante' => 'fijo_juridico',
            'reglas' => [
                ['tipo' => 'blanco', 'antes' => 'notificaciones el ubicado en', 'campo' => 'empresa_domicilio'],
                ...$indeterminadoSucursal,
            ],
            'fechas_fijas' => $fechasFijas,
        ],

        'contrato_indeterminado.gerente' => [
            'clave' => 'contrato_indeterminado',
            'nombre' => 'Contrato por tiempo indeterminado — Gerente de sucursal',
            'proceso' => 'renovacion', 'evento' => 'renovacion', 'grupos' => ['gerente'], 'motor' => 'docx', 'categoria' => 'contratos',
            'fuentes' => ['35f0bb7b100c5a59b3b5a37e87da73c451774def77aed1d6a13edaf4ca994eed' => ['version' => 1, 'activa' => true]],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_huella' => true, 'requiere_envio_corporativo' => true],
            'representante' => 'fijo_juridico',
            'reglas' => [
                ['tipo' => 'blanco', 'antes' => 'notificaciones el ubicado en', 'campo' => 'empresa_domicilio'],
                ...$indeterminadoSucursal,
            ],
            'fechas_fijas' => $fechasFijas,
        ],

        'contrato_indeterminado.subgerente' => [
            'clave' => 'contrato_indeterminado',
            'nombre' => 'Contrato por tiempo indeterminado — Subgerente',
            'proceso' => 'renovacion', 'evento' => 'renovacion', 'grupos' => ['subgerente'], 'motor' => 'docx', 'categoria' => 'contratos',
            'fuentes' => ['c0249cf8a6e94d2c8143df39a229f44fe59d2f4cc35110ae630b5fe3184f7b69' => ['version' => 1, 'activa' => true]],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_huella' => true, 'requiere_envio_corporativo' => true],
            'representante' => 'fijo_juridico',
            'reglas' => $indeterminadoSucursal,
            'fechas_fijas' => $fechasFijas,
        ],

        'contrato_indeterminado.regional' => [
            'clave' => 'contrato_indeterminado',
            'nombre' => 'Contrato por tiempo indeterminado — Gerente regional',
            'proceso' => 'renovacion', 'evento' => 'renovacion', 'grupos' => ['regional'], 'motor' => 'docx', 'categoria' => 'contratos',
            'fuentes' => ['65bf402064f47a9ce9ddd6b405ae6d19b6ade57da99ccc39606e3ca4e0ca2d2d' => ['version' => 1, 'activa' => true]],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_huella' => true, 'requiere_envio_corporativo' => true],
            'representante' => 'fijo_juridico',
            'reglas' => [
                ...array_values(array_filter($indeterminadoSucursal, fn (array $r): bool => ! in_array($r['campo'], ['fecha_ingreso_larga', 'puesto'], true))),
                ['tipo' => 'entre', 'desde' => 'fecha de inicio de la relación laboral el día', 'hasta' => '.', 'campo' => 'fecha_ingreso_dia_mes_anio'],
            ],
            'fechas_fijas' => $fechasFijas,
            'observaciones' => [
                'Formato de 2024: el domicilio legal del patrón está escrito con el domicilio anterior (Domingo Diez 1003). Se conserva; confirmar con Jurídico.',
                'A diferencia de los demás indeterminados, este no declara si el puesto es o no de confianza.',
            ],
        ],

        'contrato_indeterminado.coordinadora' => [
            'clave' => 'contrato_indeterminado',
            'nombre' => 'Contrato por tiempo indeterminado — Coordinadora administrativa',
            'proceso' => 'renovacion', 'evento' => 'renovacion', 'grupos' => ['coordinadora'], 'motor' => 'docx', 'categoria' => 'contratos',
            'fuentes' => ['eae966d82eefb3db651c39058f130df4dd218319fe02f9e2c75d48413e7b695b' => ['version' => 1, 'activa' => true]],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_huella' => true, 'requiere_envio_corporativo' => true],
            'representante' => 'fijo_juridico',
            'reglas' => [
                ['tipo' => 'blanco', 'antes' => 'CAPITAL VARIABLE Y', 'parrafo' => 'POR TIEMPO INDETERMINADO CELEBRADO', 'campo' => 'nombre_completo_mayusculas'],
                ['tipo' => 'blanco', 'antes' => 'notificaciones el ubicado en', 'campo' => 'empresa_domicilio'],
                ['tipo' => 'entre', 'desde' => 'II)', 'hasta' => ' en lo sucesivo, el trabajador', 'campo' => 'nombre_completo_mayusculas'],
                ...$generales,
                ['tipo' => 'celda', 'etiqueta' => 'Número de Seguridad social:', 'campo' => 'nss'],
                $domicilioCelda,
                ['tipo' => 'entre', 'desde' => 'se computará a partir del día', 'hasta' => ', fecha en la cual', 'campo' => 'fecha_ingreso_larga'],
                ['tipo' => 'blanco', 'antes' => 'Las partes reconocen que el puesto de', 'campo' => 'puesto'],
                ['tipo' => 'blanco', 'antes' => 'con esmero y eficiencia en el domicilio ubicado en', 'campo' => 'sucursal_domicilio'],
                ['tipo' => 'blanco', 'antes' => 'en el cual está establecida la sucursal', 'campo' => 'sucursal_nombre'],
                ['tipo' => 'blanco', 'antes' => 'salario mensual bruto de $', 'campo' => 'sueldo_mensual_numero_letra'],
                ...$beneficiarios,
                ['tipo' => 'entre', 'desde' => 'lo firman en', 'hasta' => '.', 'campo' => 'lugar_y_fecha_inicio_contrato'],
                ['tipo' => 'blanco', 'antes' => 'C.', 'fila' => 'POR EL TRABAJADOR', 'campo' => 'nombre_completo_mayusculas'],
                ['tipo' => 'blanco', 'antes' => 'de fecha', 'parrafo' => 'La presente hoja de firmas', 'campo' => 'fecha_inicio_contrato_larga'],
                ['tipo' => 'blanco', 'antes' => 'Capital Variable y', 'parrafo' => 'La presente hoja de firmas', 'campo' => 'nombre_completo_mayusculas'],
            ],
            'fechas_fijas' => $fechasFijas,
            'observaciones' => [
                'Salario: el formato dice "$ ______ 00/100 m.n."; el sistema escribe el importe con número y letra antes de "00/100 m.n.".',
                'Hoja de firmas: "celebrado entre celebrado entre" (palabra duplicada en el original).',
            ],
        ],

        'contrato_indeterminado.administrativo_confianza' => [
            'clave' => 'contrato_indeterminado',
            'nombre' => 'Contrato por tiempo indeterminado — Administrativo de confianza',
            'proceso' => 'renovacion', 'evento' => 'renovacion', 'grupos' => ['administrativo_confianza'], 'motor' => 'docx', 'categoria' => 'contratos',
            'fuentes' => ['4f8d9b3617f38c1454fe8ef7cd2789d3d539caae82ed6bc6b58135f8fd1ca2db' => ['version' => 1, 'activa' => true]],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_huella' => true, 'requiere_envio_corporativo' => true],
            'representante' => 'fijo_juridico',
            'reglas' => [
                ['tipo' => 'blanco', 'antes' => 'CAPITAL VARIABLE Y', 'parrafo' => 'POR TIEMPO INDETERMINADO CELEBRADO', 'campo' => 'nombre_completo_mayusculas'],
                ['tipo' => 'blanco', 'antes' => 'cubrir el puesto de', 'campo' => 'puesto'],
                ['tipo' => 'entre', 'desde' => 'II)', 'hasta' => ', en lo sucesivo, el trabajador', 'campo' => 'nombre_completo_mayusculas'],
                ...$generales,
                ['tipo' => 'celda', 'etiqueta' => 'Número de Seguridad social:', 'campo' => 'nss'],
                $domicilioCelda,
                ['tipo' => 'entre', 'desde' => 'se computará a partir del día', 'hasta' => ', fecha en la cual', 'campo' => 'fecha_ingreso_larga'],
                ['tipo' => 'blanco', 'antes' => 'Las partes reconocen que el puesto de', 'campo' => 'puesto'],
                ['tipo' => 'blanco', 'antes' => 'asigna al trabajador el puesto de', 'campo' => 'puesto'],
                ['tipo' => 'blanco', 'antes' => 'salario mensual bruto de $', 'campo' => 'sueldo_mensual_numero_letra'],
                ['tipo' => 'entre', 'desde' => 'Cuernavaca, Morelos a', 'hasta' => '.', 'parrafo' => 'lo firman en', 'campo' => 'fecha_inicio_contrato_larga_mayusculas'],
                ['tipo' => 'blanco', 'antes' => 'C.', 'fila' => 'POR EL TRABAJADOR', 'campo' => 'nombre_completo_mayusculas'],
                ['tipo' => 'entre', 'desde' => 'de fecha', 'hasta' => ' celebrado', 'parrafo' => 'La presente hoja de firmas', 'campo' => 'fecha_inicio_contrato_larga_mayusculas'],
                ['tipo' => 'entre', 'desde' => 'Capital Variable y', 'hasta' => '.', 'parrafo' => 'La presente hoja de firmas', 'campo' => 'nombre_completo_mayusculas'],
            ],
            'ignorar' => ['Subida del Club, numero 114', 'CORPORATIVO'],
            'fechas_fijas' => $fechasFijas,
            'observaciones' => [
                'Firma y hoja de firmas traían el año 2026 escrito ("DE 2026"); el sistema escribe la fecha completa real.',
                'Este formato no trae cláusula de beneficiarios con blanco.',
            ],
        ],

        'contrato_indeterminado.administrativo_no_confianza' => [
            'clave' => 'contrato_indeterminado',
            'nombre' => 'Contrato por tiempo indeterminado — Administrativo (no confianza)',
            'proceso' => 'renovacion', 'evento' => 'renovacion', 'grupos' => ['administrativo_no_confianza'], 'motor' => 'docx', 'categoria' => 'contratos',
            'fuentes' => ['3b3ae245bd325c3fb0f15dce075053cc1e3437eba73938c5c94dcf64f3a2dd39' => ['version' => 1, 'activa' => true]],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_huella' => true, 'requiere_envio_corporativo' => true],
            'representante' => 'fijo_juridico',
            'reglas' => [
                ['tipo' => 'blanco', 'antes' => 'CAPITAL VARIABLE', 'parrafo' => 'POR TIEMPO INDETERMINADO CELEBRADO', 'campo' => 'nombre_completo_mayusculas'],
                ['tipo' => 'entre', 'desde' => 'cubrir el puesto de', 'hasta' => '.', 'campo' => 'puesto'],
                ['tipo' => 'entre', 'desde' => 'II)', 'hasta' => ', en lo sucesivo, el trabajador', 'campo' => 'nombre_completo_mayusculas'],
                ...$generales,
                ['tipo' => 'celda', 'etiqueta' => 'Número de Seguridad social:', 'campo' => 'nss'],
                $domicilioCelda,
                ['tipo' => 'entre', 'desde' => 'se computará a partir del día', 'hasta' => ', fecha en la cual', 'campo' => 'fecha_ingreso_larga'],
                ['tipo' => 'blanco', 'antes' => 'Las partes reconocen que el puesto de', 'campo' => 'puesto'],
                ['tipo' => 'blanco', 'antes' => 'asigna al trabajador el puesto de', 'campo' => 'puesto'],
                ['tipo' => 'blanco', 'antes' => 'salario mensual bruto de $', 'campo' => 'sueldo_mensual_numero_letra'],
                ['tipo' => 'entre', 'desde' => 'Cuernavaca, Morelos a', 'hasta' => '.', 'parrafo' => 'lo firman en', 'campo' => 'fecha_inicio_contrato_larga_mayusculas'],
                ['tipo' => 'blanco', 'antes' => 'C.', 'fila' => 'POR EL TRABAJADOR', 'campo' => 'nombre_completo_mayusculas'],
                ['tipo' => 'entre', 'desde' => 'de fecha', 'hasta' => ' celebrado', 'parrafo' => 'La presente hoja de firmas', 'campo' => 'fecha_inicio_contrato_larga_mayusculas'],
                ['tipo' => 'entre', 'desde' => 'Capital Variable y', 'hasta' => '.', 'parrafo' => 'La presente hoja de firmas', 'campo' => 'nombre_completo_mayusculas'],
            ],
            'ignorar' => ['Subida del Club, numero 114', 'CORPORATIVO'],
            'fechas_fijas' => $fechasFijas,
        ],

        // ───────────────────────── CONFIDENCIALIDAD ─────────────────────────
        'contrato_confidencialidad.gestor' => [
            'clave' => 'contrato_confidencialidad',
            'nombre' => 'Contrato de confidencialidad — Gestor',
            'proceso' => 'alta', 'evento' => 'contratacion', 'grupos' => ['gestor'], 'motor' => 'docx', 'categoria' => 'contratos',
            'fuentes' => ['49cc5ac3d8854d1986794a23bc5371005db3bf54945b0493d2a3382e2ccda2f6' => ['version' => 1, 'activa' => true]],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_huella' => true, 'requiere_envio_corporativo' => true],
            'representante' => 'fijo_juridico',
            'reglas' => [
                ...$confidencialidad2026,
                ['tipo' => 'blanco', 'antes' => '"EL TITULAR" en el ubicado en:', 'campo' => 'empresa_domicilio'],
                ['tipo' => 'blanco', 'antes' => 'Cuernavaca, Morelos a los', 'campo' => 'fecha_firma_a_los_inicio_contrato'],
            ],
            'fechas_fijas' => $fechasFijas,
        ],

        'contrato_confidencialidad.gerente' => [
            'clave' => 'contrato_confidencialidad',
            'nombre' => 'Contrato de confidencialidad — Gerente de sucursal',
            'proceso' => 'alta', 'evento' => 'contratacion', 'grupos' => ['gerente'], 'motor' => 'docx', 'categoria' => 'contratos',
            'fuentes' => [
                '5110bc540852669f6d72257135d7de4da02c570aebb66abcbc513c0612f00889' => ['version' => 1, 'activa' => true],
                'eb946a2b10c4be41435c386d1adba9bd1c477ad72a3121a5fcc7867d5eaff1b4' => ['version' => 2, 'activa' => false, 'bloqueada' => true, 'nota' => 'Borrador (usuario "Usuario", 09/09/2026): contiene DOS convenios concatenados, cláusula SEXTA sobre "giro restaurantero" y hoja de firmas a nombre de "Lerom Innovaciones Gastronómicas, S.A. de C.V." (otra empresa). No se activa: requiere versión limpia de Jurídico.'],
            ],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_huella' => true, 'requiere_envio_corporativo' => true],
            'representante' => 'fijo_juridico',
            'reglas' => [
                ['tipo' => 'blanco', 'antes' => 'notificaciones el ubicado en', 'campo' => 'empresa_domicilio'],
                ...$confidencialidad2026,
                ['tipo' => 'blanco', 'antes' => '"EL TITULAR" en el ubicado en:', 'campo' => 'empresa_domicilio'],
                ['tipo' => 'entre', 'desde' => 'en la ciudad de', 'hasta' => '.', 'parrafo' => 'lo firmaron al calce', 'campo' => 'ciudad_y_fecha_firma_a_los_inicio_contrato'],
            ],
            'ignorar' => ['las leyes del estado de'],
            'fechas_fijas' => $fechasFijas,
            'observaciones' => [
                'La declaración y la cláusula "No padecer enfermedad…" dicen "capacitación inicial de dos meses"; un gerente tiene 3 meses (puestos.meses_periodo_prueba). El sistema imprime la duración real del puesto. Confirmar con Jurídico.',
                'La jurisdicción (estado / ciudad de tribunales) viene en blanco en el original y se imprime en blanco: no es dato del colaborador.',
            ],
        ],

        'contrato_confidencialidad.subgerente' => [
            'clave' => 'contrato_confidencialidad',
            'nombre' => 'Contrato de confidencialidad — Subgerente',
            'proceso' => 'alta', 'evento' => 'contratacion', 'grupos' => ['subgerente'], 'motor' => 'docx', 'categoria' => 'contratos',
            'fuentes' => ['cbc53bc4f305c087441aaa840b39ad66d3154a844515f43b9f64708663b8a992' => ['version' => 1, 'activa' => true]],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_huella' => true, 'requiere_envio_corporativo' => true],
            'representante' => 'fijo_juridico',
            'reglas' => [
                ['tipo' => 'blanco', 'antes' => 'notificaciones el ubicado en', 'campo' => 'empresa_domicilio'],
                ...$confidencialidad2026,
                ['tipo' => 'blanco', 'antes' => '"EL TITULAR" en el ubicado en:', 'campo' => 'empresa_domicilio'],
                ['tipo' => 'entre', 'desde' => 'en la ciudad de', 'hasta' => '.', 'parrafo' => 'lo firmaron al calce', 'campo' => 'ciudad_y_fecha_firma_a_los_inicio_contrato'],
            ],
            'ignorar' => ['las leyes del estado de'],
            'fechas_fijas' => $fechasFijas,
            'observaciones' => ['Duración "dos meses" en la declaración: se imprime la duración real del puesto (subgerente 3 meses). La jurisdicción viene en blanco y se imprime en blanco.'],
        ],

        'contrato_confidencialidad.coordinadora' => [
            'clave' => 'contrato_confidencialidad',
            'nombre' => 'Contrato de confidencialidad — Coordinadoras',
            'proceso' => 'alta', 'evento' => 'contratacion', 'grupos' => ['coordinadora'], 'motor' => 'docx', 'categoria' => 'contratos',
            'fuentes' => ['1bc28f7b1cc0085b210e49b01e171f971ad264a96f0acd4ddbf117444567a123' => ['version' => 1, 'activa' => true]],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_huella' => true, 'requiere_envio_corporativo' => true],
            'representante' => 'fijo_juridico',
            'reglas' => [
                ['tipo' => 'blanco', 'antes' => 'notificaciones el ubicado en', 'campo' => 'empresa_domicilio'],
                ...$confidencialidad2026,
                ['tipo' => 'blanco', 'antes' => '"EL TITULAR" en el ubicado en:', 'campo' => 'empresa_domicilio'],
                ['tipo' => 'entre', 'desde' => 'en la ciudad de', 'hasta' => '.', 'parrafo' => 'lo firmaron al calce', 'campo' => 'ciudad_y_fecha_firma_a_los_inicio_contrato'],
            ],
            'ignorar' => ['las leyes del estado de'],
            'fechas_fijas' => $fechasFijas,
        ],

        'contrato_confidencialidad.general' => [
            'clave' => 'contrato_confidencialidad',
            'nombre' => 'Contrato de confidencialidad — General MR. LANA',
            'proceso' => 'alta', 'evento' => 'contratacion', 'grupos' => null, 'motor' => 'docx', 'categoria' => 'contratos',
            'fuentes' => [
                '5e4f9d1e6c36ee6705e7a8eeba242ac72982051cff26e4f5738f878bd28168a2' => ['version' => 1, 'activa' => false, 'nota' => 'Formato 2024 ("FORMATO CONTRATO DE CONFIDENCIALIDAD PARA MR LANA"): representante escrita "LESLI MARIBEL RODRÍGUEZ JIMÉNEZ" y fecha de firma con "febrero de 202" fijo. Sustituido por el convenio general de Jurídico 2026.'],
                '9b6cda25c2d588782691566df230f86cab20b9ef7dc51911663200cf690623a6' => ['version' => 2, 'activa' => true],
            ],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_huella' => true, 'requiere_envio_corporativo' => true],
            'representante' => 'fijo_juridico',
            'reglas_por_version' => [
                1 => [
                    ['tipo' => 'blanco', 'antes' => 'CAPITAL VARIABLE Y', 'parrafo' => 'CONTRATO DE CONFIDENCIALIDAD', 'campo' => 'nombre_completo_mayusculas'],
                    ['tipo' => 'blanco', 'despues' => ', en sucesivo, el receptor', 'campo' => 'nombre_completo_mayusculas'],
                    ...$generales,
                    $domicilioCelda,
                    ['tipo' => 'blanco', 'antes' => 'Cuernavaca, Morelos, el día', 'campo' => 'fecha_inicio_contrato_dia'],
                ],
            ],
            'reglas' => [
                ...$confidencialidad2026,
                ['tipo' => 'blanco', 'antes' => 'Cuernavaca, Morelos a los', 'campo' => 'fecha_firma_a_los_inicio_contrato'],
            ],
            'fechas_fijas' => $fechasFijas,
            'observaciones' => ['Convenio general: lo firman todos los puestos que no tienen convenio propio (documentos_maestros.fallback_general).'],
        ],

        // ───────────────────────── NO COMPETENCIA ─────────────────────────
        'contrato_no_competencia.gestor' => [
            'clave' => 'contrato_no_competencia',
            'nombre' => 'Acuerdo de no competencia — Gestores',
            'proceso' => 'alta', 'evento' => 'contratacion', 'grupos' => ['gestor'], 'motor' => 'docx', 'categoria' => 'contratos',
            'fuentes' => ['48b6c234e47c6c253b63685af13d5b2f2abebb106167f85f3f948b936319596b' => ['version' => 1, 'activa' => true]],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_huella' => true, 'requiere_envio_corporativo' => true],
            'representante' => 'fijo_juridico',
            'reglas' => [
                ['tipo' => 'texto', 'buscar' => 'Dos Meses', 'parrafo' => 'Anexo 1 del Contrato Laboral', 'campo' => 'duracion_capacitacion_letra_titulo'],
                ['tipo' => 'blanco', 'antes' => 'LA C.', 'parrafo' => 'Anexo 1 del Contrato Laboral', 'campo' => 'nombre_completo_mayusculas'],
                ['tipo' => 'blanco', 'antes' => 'POR LA OTRA EL C.', 'campo' => 'nombre_completo_mayusculas'],
                ...$noCompetenciaDeclaracion,
                ['tipo' => 'blanco', 'antes' => 'con domicilio en', 'parrafo' => 'Ser una persona física', 'campo' => 'domicilio'],
                ['tipo' => 'texto', 'buscar' => 'DOS MESES', 'ocurrencia' => 'todas', 'parrafo' => 'CAPACITACIÓN INICIAL DE PERIODO DE', 'campo' => 'duracion_capacitacion_letra_mayusculas'],
                ['tipo' => 'blanco', 'antes' => 'con fecha', 'ocurrencia' => 'todas', 'parrafo' => 'CAPACITACIÓN INICIAL DE PERIODO', 'campo' => 'fecha_inicio_contrato_larga'],
                ['tipo' => 'blanco', 'antes' => 'en la ciudad de', 'parrafo' => 'lo firmaron al calce', 'campo' => 'ciudad_firma'],
                ['tipo' => 'entre', 'desde' => 'a los', 'hasta' => '.', 'parrafo' => 'lo firmaron al calce', 'campo' => 'fecha_firma_dias_del_mes_inicio_contrato'],
                ['tipo' => 'blanco', 'antes' => 'EL C.', 'fila' => 'EL SUJETO B', 'campo' => 'nombre_completo_mayusculas'],
            ],
            'fechas_fijas' => $fechasFijas,
            'observaciones' => [
                'Encabezado fijo "LA C." (femenino) aunque el titular sea hombre: se conserva tal cual.',
                'Pena convencional $500,000.00 y obligación "vitalicia": texto jurídico fijo, no se modifica.',
            ],
        ],

        'contrato_no_competencia.gerente' => [
            'clave' => 'contrato_no_competencia',
            'nombre' => 'Acuerdo de no competencia — Gerentes',
            'proceso' => 'alta', 'evento' => 'contratacion', 'grupos' => ['gerente'], 'motor' => 'docx', 'categoria' => 'contratos',
            'fuentes' => ['2f85df2b0fa39404df18dbc28269fc423e9ed71c78886ce9a0c7c67f684679a0' => ['version' => 1, 'activa' => true]],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_huella' => true, 'requiere_envio_corporativo' => true],
            'representante' => 'fijo_juridico',
            'reglas' => [
                ['tipo' => 'texto', 'buscar' => 'Dos Meses', 'parrafo' => 'Anexo 1 del Contrato Laboral', 'campo' => 'duracion_capacitacion_letra_titulo'],
                ['tipo' => 'entre', 'desde' => 'Y EL C.', 'hasta' => '.', 'parrafo' => 'Anexo 1 del Contrato Laboral', 'campo' => 'nombre_completo_mayusculas'],
                ['tipo' => 'entre', 'desde' => 'POR LA OTRA EL C.', 'hasta' => 'A QUIÉN', 'campo' => 'nombre_completo_mayusculas'],
                ...$noCompetenciaDeclaracion,
                ['tipo' => 'blanco', 'antes' => 'con domicilio en el ubicado en', 'parrafo' => 'Ser una persona física', 'campo' => 'domicilio'],
                ['tipo' => 'blanco', 'antes' => 'y de profesión:', 'campo' => 'profesion'],
                ['tipo' => 'texto', 'buscar' => 'DOS MESES', 'ocurrencia' => 'todas', 'parrafo' => 'CAPACITACIÓN INICIAL DE PERIODO DE', 'campo' => 'duracion_capacitacion_letra_mayusculas'],
                ['tipo' => 'blanco', 'antes' => 'con fecha', 'ocurrencia' => 'todas', 'parrafo' => 'CAPACITACIÓN INICIAL DE PERIODO', 'campo' => 'fecha_inicio_contrato_larga'],
                ['tipo' => 'blanco', 'antes' => 'en la ciudad de', 'parrafo' => 'lo firmaron al calce', 'campo' => 'ciudad_firma'],
                ['tipo' => 'entre', 'desde' => 'a los', 'hasta' => '.', 'parrafo' => 'lo firmaron al calce', 'campo' => 'fecha_firma_dias_del_mes_inicio_contrato'],
                ['tipo' => 'entre', 'desde' => 'EL C.', 'hasta' => '.', 'fila' => 'EL SUJETO B', 'campo' => 'nombre_completo_mayusculas'],
            ],
            'fechas_fijas' => $fechasFijas,
            'observaciones' => [
                'El encabezado y las cláusulas citan "CONTRATO LABORAL POR CAPACITACIÓN INICIAL DE PERIODO DE DOS MESES"; un gerente tiene 3 meses. El sistema imprime la duración real del puesto. Confirmar con Jurídico.',
                'El título "ACUERDO DE NO COMPETENCIA" aparece dos veces (encabezado y párrafo inicial).',
            ],
        ],

        // ───────────────────────── BAJA / CIERRE ─────────────────────────
        'carta_renuncia.general' => [
            'clave' => 'carta_renuncia',
            'nombre' => 'Formato de renuncia MR. LANA',
            'proceso' => 'baja', 'evento' => 'renuncia', 'causa' => 'renuncia', 'grupos' => null, 'motor' => 'docx', 'categoria' => 'baja_finiquito',
            'fuentes' => ['d229cd0563aee9b1835f27d943252421463c5707cb282289a2a2ce6e58289c22' => ['version' => 1, 'activa' => true]],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_huella' => true, 'requiere_envio_corporativo' => true],
            'representante' => 'no_aplica',
            'reglas' => [
                ['tipo' => 'texto', 'buscar' => 'Pachuca de Soto , Hidalgo', 'campo' => 'lugar_documento'],
                ['tipo' => 'texto', 'buscar' => '01 Abril de 2025', 'campo' => 'fecha_documento_larga'],
                ['tipo' => 'texto', 'buscar' => 'JUAN ANTONIO GUTIERREZ COVARRUBIAS', 'ocurrencia' => 'todas', 'campo' => 'nombre_completo_mayusculas'],
                ['tipo' => 'texto', 'buscar' => 'Gestor de crédito', 'campo' => 'puesto'],
                ['tipo' => 'texto', 'buscar' => 'PACHUCA DE SOTO', 'campo' => 'sucursal_municipio_mayusculas'],
                ['tipo' => 'texto', 'buscar' => 'PACHUCA', 'parrafo' => 'de la sucursal', 'campo' => 'sucursal_nombre_mayusculas'],
                ['tipo' => 'texto', 'buscar' => 'CAMPESTRE VILLAS DEL ALAMO', 'campo' => 'sucursal_direccion_mayusculas'],
                ['tipo' => 'texto', 'buscar' => 'Hidalgo', 'parrafo' => 'Estado de', 'campo' => 'sucursal_estado'],
                ['tipo' => 'texto', 'buscar' => '42184', 'campo' => 'sucursal_cp'],
            ],
            'ejemplos' => ['JUAN ANTONIO', 'GUTIERREZ COVARRUBIAS', 'PACHUCA', 'CAMPESTRE VILLAS'],
            'observaciones' => ['"FORMATO DE RENUNCIA DE MR LANA (1).docx" es idéntico byte a byte (mismo SHA-256): se registra como fuente duplicada del mismo master.'],
        ],

        'aviso_terminacion.general' => [
            'clave' => 'aviso_terminacion',
            'nombre' => 'Aviso de terminación de la relación laboral (vencimiento de capacitación inicial)',
            'proceso' => 'baja', 'evento' => 'no_renovacion', 'causa' => 'no_renovacion', 'grupos' => null, 'motor' => 'docx', 'categoria' => 'baja_finiquito',
            'fuentes' => ['66698e60815482c987d89fb5d286cc3001c92c2fc171e112f3de4a9b52b33c02' => ['version' => 1, 'activa' => true]],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_envio_corporativo' => true],
            'representante' => 'empresa',
            'reglas' => [
                ['tipo' => 'texto', 'buscar' => 'Cuernavaca, Morelos', 'parrafo' => ', a 16 de septiembre de 2026', 'campo' => 'lugar_documento'],
                ['tipo' => 'texto', 'buscar' => '16 de septiembre de 2026', 'parrafo' => ', a 16 de septiembre', 'campo' => 'fecha_documento_larga'],
                ['tipo' => 'texto', 'buscar' => 'JESUS ENRIQUE OCAMPO PEREZ', 'ocurrencia' => 'todas', 'campo' => 'nombre_completo_mayusculas'],
                ['tipo' => 'texto', 'buscar' => '16 de Julio de 2026', 'campo' => 'fecha_inicio_contrato_larga'],
                ['tipo' => 'texto', 'buscar' => 'dos meses', 'parrafo' => 'vigencia de', 'campo' => 'duracion_letra'],
                ['tipo' => 'texto', 'buscar' => '16 de septiembre de 2026', 'parrafo' => 'concluye el día', 'campo' => 'fecha_fin_contrato_larga'],
                ['tipo' => 'texto', 'buscar' => 'LESLI MARIBEL RODRÍGUEZ HERRERA', 'campo' => 'representante_legal_mayusculas'],
            ],
            'ejemplos' => ['JESUS ENRIQUE', 'OCAMPO PEREZ', '16 de Julio'],
            'observaciones' => [
                'El ejemplo traía una persona, fechas y duración concretas (Jesús Enrique Ocampo Pérez, 16/07/2026, dos meses): todo se vuelve dato del colaborador y de su contrato.',
                'Representante: el aviso es una comunicación operativa; el nombre se toma de la empresa (Empresas → representante legal) con respaldo en documentos_maestros.empresa_defecto.',
                'En el original falta un espacio en "C.JESUS…": el sistema conserva "C." pegado al nombre como en el formato.',
            ],
        ],

        'evaluacion_capacitacion.general' => [
            'clave' => 'evaluacion_capacitacion',
            'nombre' => 'Formato de evaluación de capacitación inicial',
            'proceso' => 'baja', 'evento' => 'evaluacion', 'causa' => 'no_renovacion', 'grupos' => null, 'motor' => 'docx', 'categoria' => 'baja_finiquito',
            'fuentes' => ['d6f08e3afa2033ceb49aed30a06e02a117c26d49c24e03033a0d14c0867c1e01' => ['version' => 1, 'activa' => true]],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_envio_corporativo' => true],
            'representante' => 'no_aplica',
            // Este formato SOLO contempla la determinación de NO acreditación
            // (sección VI): con resultado ACREDITA no se genera.
            'solo_resultado' => 'no_acredita',
            'reglas' => [
                ['tipo' => 'texto', 'buscar' => 'PRODUCTOS Y SERVICIOS MR. LANA, S.A.P.I. DE C.V.', 'parrafo' => 'EMPRESA:', 'campo' => 'empresa_razon_social_mayusculas'],
                ['tipo' => 'texto', 'buscar' => 'Gerente de Sucursal', 'parrafo' => 'PUESTO EVALUADO', 'campo' => 'puesto'],
                ['tipo' => 'blanco', 'antes' => 'Nombre del trabajador:', 'campo' => 'nombre_completo_mayusculas'],
                ['tipo' => 'blanco', 'antes' => 'CURP:', 'campo' => 'curp'],
                ['tipo' => 'blanco', 'antes' => 'RFC:', 'campo' => 'rfc'],
                ['tipo' => 'blanco', 'antes' => 'Sucursal:', 'campo' => 'sucursal_nombre'],
                ['tipo' => 'entre', 'desde' => 'Fecha de inicio del contrato:', 'hasta' => '.', 'campo' => 'fecha_inicio_contrato_larga'],
                ['tipo' => 'entre', 'desde' => 'Fecha de conclusión del contrato:', 'hasta' => '.', 'campo' => 'fecha_fin_contrato_larga'],
                ['tipo' => 'texto', 'buscar' => 'Tres meses', 'parrafo' => 'Duración:', 'campo' => 'duracion_letra_capital'],
                ['tipo' => 'texto', 'buscar' => 'Gerente de Sucursal', 'parrafo' => 'Que el colaborador adquiriera', 'campo' => 'puesto'],
                // Criterios (la 2ª casilla primero: al sustituir la 1ª, la 2ª pasaría a ser la 1ª).
                ['tipo' => 'texto', 'buscar' => '☐', 'fila' => 'Gestión operativa de la sucursal', 'en_parrafo' => 2, 'campo' => 'criterio_1_no_acredita'],
                ['tipo' => 'texto', 'buscar' => '☐', 'fila' => 'Gestión operativa de la sucursal', 'en_parrafo' => 1, 'campo' => 'criterio_1_acredita'],
                ['tipo' => 'texto', 'buscar' => '☐', 'fila' => 'Cumplimiento de KPI', 'en_parrafo' => 2, 'campo' => 'criterio_2_no_acredita'],
                ['tipo' => 'texto', 'buscar' => '☐', 'fila' => 'Cumplimiento de KPI', 'en_parrafo' => 1, 'campo' => 'criterio_2_acredita'],
                ['tipo' => 'texto', 'buscar' => '☐', 'fila' => 'Liderazgo y supervisión', 'en_parrafo' => 2, 'campo' => 'criterio_3_no_acredita'],
                ['tipo' => 'texto', 'buscar' => '☐', 'fila' => 'Liderazgo y supervisión', 'en_parrafo' => 1, 'campo' => 'criterio_3_acredita'],
                ['tipo' => 'texto', 'buscar' => '☐', 'fila' => 'Reportes y controles administrativos', 'en_parrafo' => 2, 'campo' => 'criterio_4_no_acredita'],
                ['tipo' => 'texto', 'buscar' => '☐', 'fila' => 'Reportes y controles administrativos', 'en_parrafo' => 1, 'campo' => 'criterio_4_acredita'],
                ['tipo' => 'texto', 'buscar' => '☐', 'fila' => 'Toma de decisiones', 'en_parrafo' => 2, 'campo' => 'criterio_5_no_acredita'],
                ['tipo' => 'texto', 'buscar' => '☐', 'fila' => 'Toma de decisiones', 'en_parrafo' => 1, 'campo' => 'criterio_5_acredita'],
                ['tipo' => 'texto', 'buscar' => '☐', 'fila' => 'Apego a políticas internas', 'en_parrafo' => 2, 'campo' => 'criterio_6_no_acredita'],
                ['tipo' => 'texto', 'buscar' => '☐', 'fila' => 'Apego a políticas internas', 'en_parrafo' => 1, 'campo' => 'criterio_6_acredita'],
                ['tipo' => 'texto', 'buscar' => '☐', 'fila' => 'Responsabilidad y seguimiento', 'en_parrafo' => 2, 'campo' => 'criterio_7_no_acredita'],
                ['tipo' => 'texto', 'buscar' => '☐', 'fila' => 'Responsabilidad y seguimiento', 'en_parrafo' => 1, 'campo' => 'criterio_7_acredita'],
                // V. Resultado: el ejemplo trae marcado ☒ NO ACREDITA.
                ['tipo' => 'texto', 'buscar' => '☒', 'parrafo' => 'NO ACREDITA satisfactoriamente', 'campo' => 'resultado_no_acredita'],
                ['tipo' => 'texto', 'buscar' => '☐', 'parrafo' => 'ACREDITA satisfactoriamente', 'campo' => 'resultado_acredita'],
                ['tipo' => 'texto', 'buscar' => 'Gerente de Sucursal', 'parrafo' => 'NO ACREDITA satisfactoriamente', 'campo' => 'puesto'],
                ['tipo' => 'blanco', 'antes' => 'Nombre y cargo:', 'parrafo' => 'ELABORÓ', 'campo' => 'elaboro_nombre_cargo'],
                ['tipo' => 'entre', 'desde' => 'Fecha:', 'hasta' => '', 'parrafo' => 'ELABORÓ', 'campo' => 'fecha_elaboro_larga'],
                ['tipo' => 'blanco', 'antes' => 'Nombre y cargo:', 'parrafo' => 'VALIDÓ', 'campo' => 'valido_nombre_cargo'],
                ['tipo' => 'entre', 'desde' => 'Fecha:', 'hasta' => '', 'parrafo' => 'VALIDÓ', 'campo' => 'fecha_valido_larga'],
                ['tipo' => 'blanco', 'antes' => 'Nombre:', 'parrafo' => 'Firma:', 'campo' => 'nombre_completo_mayusculas'],
                ['tipo' => 'entre', 'desde' => 'Fecha:', 'hasta' => '', 'parrafo' => 'Firma:', 'campo' => 'fecha_entrega_larga'],
            ],
            'ejemplos' => ['Tres meses', 'de enero de 2026'],
            'observaciones' => [
                'El formato es de NO acreditación: la sección VI ("DETERMINACIÓN") solo aplica cuando el colaborador no acredita. Con resultado ACREDITA PEOPLE no lo genera (no se altera el texto jurídico). Si RH requiere constancia de acreditación, Jurídico debe entregar la variante.',
                'Los criterios y su descripción están redactados para Gerente de sucursal ("dirección y coordinación del subgerente y asesores de crédito"); se usan tal cual para cualquier puesto. Confirmar con Jurídico si se requieren variantes por puesto.',
                'El ejemplo traía "Gerente de Sucursal", "Tres meses" y "NO ACREDITA" marcado: todo se vuelve dato real (puesto, duración del contrato, resultado capturado).',
            ],
        ],

        'acta_negativa_firma.general' => [
            'clave' => 'acta_negativa_firma',
            'nombre' => 'Acta administrativa por negativa de firma y recepción de documentos',
            'proceso' => 'negativa_firma', 'evento' => 'negativa_firma', 'causa' => null, 'grupos' => null, 'motor' => 'docx', 'categoria' => 'actas',
            'fuentes' => ['4e7faa4f79942996b1c0d25597a0dab7e2c60d25c74b4aabd0324edc2610c884' => ['version' => 1, 'activa' => true]],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_testigos' => true, 'cantidad_testigos' => 2, 'requiere_envio_corporativo' => true],
            'representante' => 'empresa',
            'reglas' => [
                ['tipo' => 'texto', 'buscar' => 'Cuernavaca, Morelos', 'parrafo' => 'En la ciudad de', 'campo' => 'lugar_acta'],
                ['tipo' => 'blanco', 'antes' => 'siendo las', 'campo' => 'hora_acta'],
                ['tipo' => 'entre', 'desde' => 'horas del día', 'hasta' => ', reunidos', 'campo' => 'fecha_acta_larga'],
                ['tipo' => 'texto', 'buscar' => 'Subida al Club número 114, Colonia Reforma, C.P. 62260', 'campo' => 'domicilio_acta'],
                ['tipo' => 'blanco', 'antes' => 'Nombre:', 'parrafo' => '(Representante del área de Recursos Humanos)', 'campo' => 'rh_nombre'],
                ['tipo' => 'blanco', 'antes' => 'Cargo:', 'parrafo' => '(Representante del área de Recursos Humanos)', 'campo' => 'rh_cargo'],
                ['tipo' => 'blanco', 'antes' => 'Nombre:', 'parrafo' => '(Jefe inmediato / Administrativo)', 'campo' => 'jefe_nombre'],
                ['tipo' => 'blanco', 'antes' => 'Cargo:', 'parrafo' => '(Jefe inmediato / Administrativo)', 'campo' => 'jefe_cargo'],
                ['tipo' => 'blanco', 'antes' => 'Nombre del trabajador:', 'campo' => 'nombre_completo_mayusculas'],
                ['tipo' => 'texto', 'buscar' => 'Gerente de Sucursal', 'ocurrencia' => 'todas', 'campo' => 'puesto'],
                ['tipo' => 'blanco', 'antes' => 'Nombre:', 'parrafo' => 'Nombre:', 'ocurrencia' => 1, 'campo' => 'nombre_completo_mayusculas'],
                ['tipo' => 'entre', 'desde' => 'Fecha de inicio:', 'hasta' => '', 'campo' => 'fecha_inicio_contrato_larga'],
                ['tipo' => 'entre', 'desde' => 'Fecha de conclusión:', 'hasta' => '', 'campo' => 'fecha_fin_contrato_larga'],
                ['tipo' => 'entre', 'desde' => 'Que el día', 'hasta' => ', se hizo', 'campo' => 'fecha_notificacion_larga'],
                ['tipo' => 'blanco', 'antes' => 'se hizo del conocimiento del C.', 'campo' => 'nombre_completo_mayusculas'],
                ['tipo' => 'texto', 'buscar' => '08 de enero de 2026', 'campo' => 'fecha_fin_contrato_larga'],
                ['tipo' => 'blanco', 'antes' => 'Que el C.', 'campo' => 'nombre_completo_mayusculas'],
                ['tipo' => 'blanco', 'antes' => 'Nombre:', 'parrafo' => 'TESTIGO 1:', 'campo' => 'testigo_1_nombre'],
                ['tipo' => 'blanco', 'antes' => 'Cargo:', 'parrafo' => 'TESTIGO 1:', 'campo' => 'testigo_1_cargo'],
                ['tipo' => 'blanco', 'antes' => 'Nombre:', 'parrafo' => 'TESTIGO 2:', 'campo' => 'testigo_2_nombre'],
                ['tipo' => 'blanco', 'antes' => 'Cargo:', 'parrafo' => 'TESTIGO 2:', 'campo' => 'testigo_2_cargo'],
                ['tipo' => 'texto', 'buscar' => 'LESLI MARIBEL RODRÍGUEZ HERRERA', 'campo' => 'representante_legal_mayusculas'],
                ['tipo' => 'blanco', 'antes' => 'C.', 'parrafo' => 'C. ', 'campo' => 'nombre_completo_mayusculas'],
            ],
            'ejemplos' => ['Gerente de Sucursal', 'de enero de 2026', 'de 2025'],
            'observaciones' => [
                'Solo se genera dentro del cierre laboral, cuando se registró que el colaborador se negó a firmar/recibir (Fase 3, escenario B del procedimiento integral de baja). Nunca como acta genérica.',
                'Lugar y domicilio: el ejemplo traía Cuernavaca y el domicilio corporativo; PEOPLE propone la ciudad y domicilio de la sucursal donde ocurre la entrega y RH puede corregirlos al registrar la negativa.',
                '"Tipo de contrato: Contrato Individual de Trabajo de Capacitación Inicial" es texto fijo: el acta está redactada solo para vencimiento de capacitación inicial.',
            ],
        ],

        'procedimiento_baja.referencia' => [
            'clave' => 'procedimiento_integral_baja',
            'nombre' => 'Procedimiento integral de baja de colaborador (referencia de negocio)',
            'proceso' => 'referencia', 'evento' => null, 'grupos' => null, 'motor' => 'docx', 'categoria' => null,
            'operativo' => false,
            'fuentes' => ['58c0b1b63fd9b4ee9bdd143cb343c38f3f91db9eae36dc1500b070e2af5b5661' => ['version' => 1, 'activa' => true]],
            'reglas' => [],
            'observaciones' => ['NO es plantilla: es la especificación del proceso de baja por vencimiento de capacitación inicial. Se usó para auditar el flujo de cierre (docs/MATRIZ_DOCUMENTOS_POR_FLUJO.md). Nunca se genera para un colaborador ni se archiva en expedientes.'],
        ],

        // ───────────────────────── PERMISOS ─────────────────────────
        'permiso_extraordinario.maternidad' => [
            'clave' => 'permiso_extraordinario_goce',
            'nombre' => 'Permiso extraordinario con goce de sueldo (protección a la maternidad)',
            'proceso' => 'permiso', 'evento' => 'permiso_extraordinario', 'grupos' => null, 'motor' => 'docx', 'categoria' => 'permisos',
            'fuentes' => ['ea108fbcc7422776d986b67b32adf61ed74adf0b882320ec1a2d0e7e9e081312' => ['version' => 1, 'activa' => true]],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_testigos' => true, 'cantidad_testigos' => 2, 'requiere_envio_corporativo' => true],
            'representante' => 'no_aplica',
            'reglas' => [
                ['tipo' => 'texto', 'buscar' => 'EVA CRISTINA SÁNCHEZ VELASCO', 'ocurrencia' => 'todas', 'campo' => 'nombre_completo_mayusculas'],
                ['tipo' => 'texto', 'buscar' => '31 de agosto de 2026', 'campo' => 'fecha_inicio_permiso_larga'],
                ['tipo' => 'texto', 'buscar' => 'Orizaba Veracruz a 28-08-2026', 'campo' => 'lugar_y_fecha_documento_corta'],
                ['tipo' => 'texto', 'buscar' => 'Eva Cristina Sánchez Velasco', 'campo' => 'nombre_completo'],
                ['tipo' => 'texto', 'buscar' => '28 de agosto de 2026', 'campo' => 'fecha_documento_larga'],
            ],
            'ejemplos' => ['EVA CRISTINA', 'Orizaba', 'Sánchez Velasco'],
            'observaciones' => [
                'Formato específico para colaboradoras embarazadas (redacción en femenino, "protección a la maternidad"). Solo se ofrece en solicitudes de permiso con goce marcadas por RH como permiso extraordinario de maternidad.',
            ],
        ],

        // FORMATO PERMISO MR. LANA: PDF oficial, overlay sobre el original.
        // La hoja trae DOS copias idénticas (superior e inferior): se llenan
        // ambas. Coordenadas en mm sobre carta (215.9 × 279.4).
        'formato_permiso.general' => [
            'clave' => 'formato_permiso',
            'nombre' => 'Formato de permiso MR. LANA',
            'proceso' => 'permiso', 'evento' => 'permiso_aprobado', 'grupos' => null, 'motor' => 'pdf_overlay', 'categoria' => 'permisos',
            'fuentes' => ['300fc897e2bdf13787c261b6320bcbbe32f42e53d61695eb08ac2b6fb218acee' => ['version' => 1, 'activa' => true]],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true],
            'representante' => 'no_aplica',
            'copias_offset_y' => [0.0, 140.0],
            'campos' => [
                ['campo' => 'nombre_completo_mayusculas', 'pagina' => 1, 'x' => 66, 'y' => 25.4, 'ancho' => 140, 'alto' => 4, 'tamano' => 9.5],
                ['campo' => 'departamento_mayusculas', 'pagina' => 1, 'x' => 43, 'y' => 31.4, 'ancho' => 160, 'alto' => 4, 'tamano' => 9.5],
                ['campo' => 'fecha_permiso_texto', 'pagina' => 1, 'x' => 50, 'y' => 37.5, 'ancho' => 150, 'alto' => 4, 'tamano' => 9.5],
                ['campo' => 'marca_faltar', 'pagina' => 1, 'x' => 53.5, 'y' => 51.0, 'ancho' => 41, 'alto' => 5, 'tamano' => 11, 'alineacion' => 'C'],
                ['campo' => 'marca_salir', 'pagina' => 1, 'x' => 104.5, 'y' => 51.0, 'ancho' => 41, 'alto' => 5, 'tamano' => 11, 'alineacion' => 'C'],
                ['campo' => 'marca_llegar_tarde', 'pagina' => 1, 'x' => 155, 'y' => 51.0, 'ancho' => 41, 'alto' => 5, 'tamano' => 11, 'alineacion' => 'C'],
                ['campo' => 'hora_salida', 'pagina' => 1, 'x' => 67, 'y' => 73.1, 'ancho' => 29, 'alto' => 5, 'tamano' => 11, 'alineacion' => 'C'],
                ['campo' => 'hora_entrada', 'pagina' => 1, 'x' => 153, 'y' => 73.1, 'ancho' => 29, 'alto' => 5, 'tamano' => 11, 'alineacion' => 'C'],
                ['campo' => 'marca_tiempo_por_tiempo', 'pagina' => 1, 'x' => 24.2, 'y' => 93.4, 'ancho' => 25, 'alto' => 4.8, 'tamano' => 10, 'alineacion' => 'C'],
                ['campo' => 'marca_descuento_nomina', 'pagina' => 1, 'x' => 94.8, 'y' => 93.4, 'ancho' => 25.5, 'alto' => 4.8, 'tamano' => 10, 'alineacion' => 'C'],
                ['campo' => 'marca_permiso_especial', 'pagina' => 1, 'x' => 162.8, 'y' => 93.4, 'ancho' => 25, 'alto' => 4.8, 'tamano' => 10, 'alineacion' => 'C'],
                ['campo' => 'motivo_permiso', 'pagina' => 1, 'x' => 29, 'y' => 102.6, 'ancho' => 177, 'alto' => 12.6, 'tamano' => 9, 'multilinea' => true, 'interlineado' => 6.3],
            ],
            'requeridos' => ['nombre_completo_mayusculas', 'departamento_mayusculas', 'fecha_permiso_texto', 'motivo_permiso'],
            'observaciones' => [
                'Overlay sobre el PDF original (sin rediseñar). Firmas de jefe inmediato, RH y colaborador quedan en blanco para firma física.',
                'La hoja trae dos copias idénticas del formato; el sistema llena ambas.',
            ],
        ],

        // ───────────────────────── PRÉSTAMOS ─────────────────────────
        // "Contrato de crédito para colaboradores.pdf": un solo original con
        // carta de consentimiento/retención (p1), contrato (p2-3), pagaré
        // (p4) y solicitud de crédito (p5-6). Cada documento operativo es
        // un master sobre su rango de páginas del MISMO original.
        'prestamo_consentimiento_retencion.general' => [
            'clave' => 'prestamo_consentimiento_retencion',
            'nombre' => 'Carta consentimiento de no adeudo y retención en nómina',
            'proceso' => 'prestamo', 'evento' => 'prestamo_autorizado', 'grupos' => null, 'motor' => 'pdf_overlay', 'categoria' => 'prestamos',
            'fuentes' => ['2fcbe22255e187dfda0d70d7aaa747d20322ed189884d206ea5d9fbb11b93679' => ['version' => 1, 'activa' => true]],
            'paginas' => [1],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_envio_corporativo' => true],
            'representante' => 'no_aplica',
            'campos' => [
                ['campo' => 'nombre_completo_mayusculas', 'pagina' => 1, 'x' => 76.6, 'y' => 91.4, 'ancho' => 72, 'alto' => 4.4, 'tamano' => 9],
                ['campo' => 'puesto_mayusculas', 'pagina' => 1, 'x' => 123.6, 'y' => 103.0, 'ancho' => 60, 'alto' => 4.4, 'tamano' => 8.5],
                ['campo' => 'clave_elector', 'pagina' => 1, 'x' => 12.5, 'y' => 111.4, 'ancho' => 67, 'alto' => 4.4, 'tamano' => 9],
                ['campo' => 'rfc', 'pagina' => 1, 'x' => 93, 'y' => 111.4, 'ancho' => 39.7, 'alto' => 4.4, 'tamano' => 9],
                ['campo' => 'monto_prestamo', 'pagina' => 1, 'x' => 150.8, 'y' => 116.4, 'ancho' => 36.5, 'alto' => 4.4, 'tamano' => 9],
                ['campo' => 'fecha_solicitud_prestamo_corta', 'pagina' => 1, 'x' => 24.3, 'y' => 126.4, 'ancho' => 58.5, 'alto' => 4.4, 'tamano' => 9],
                ['campo' => 'nombre_completo_mayusculas', 'pagina' => 1, 'x' => 12.5, 'y' => 219.6, 'ancho' => 71.4, 'alto' => 4, 'tamano' => 8, 'alineacion' => 'C'],
                ['campo' => 'gerente_nombre_mayusculas', 'pagina' => 1, 'x' => 131.8, 'y' => 219.6, 'ancho' => 71.4, 'alto' => 4, 'tamano' => 8, 'alineacion' => 'C'],
                ['campo' => 'lugar_y_fecha_documento_corta', 'pagina' => 1, 'x' => 72.1, 'y' => 248.6, 'ancho' => 71.4, 'alto' => 4, 'tamano' => 8, 'alineacion' => 'C'],
            ],
            'requeridos' => ['nombre_completo_mayusculas', 'puesto_mayusculas', 'clave_elector', 'rfc', 'monto_prestamo', 'fecha_solicitud_prestamo_corta'],
        ],

        'prestamo_contrato.general' => [
            'clave' => 'prestamo_contrato',
            'nombre' => 'Contrato de crédito para trabajadores',
            'proceso' => 'prestamo', 'evento' => 'prestamo_autorizado', 'grupos' => null, 'motor' => 'pdf_overlay', 'categoria' => 'prestamos',
            'fuentes' => ['2fcbe22255e187dfda0d70d7aaa747d20322ed189884d206ea5d9fbb11b93679' => ['version' => 1, 'activa' => true]],
            'paginas' => [2, 3],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_huella' => true, 'requiere_envio_corporativo' => true],
            'representante' => 'empresa',
            'campos' => [
                ['campo' => 'fecha_otorgamiento_corta', 'pagina' => 2, 'x' => 13, 'y' => 72.6, 'ancho' => 36, 'alto' => 4.4, 'tamano' => 9],
                ['campo' => 'sucursal_numero', 'pagina' => 2, 'x' => 51.5, 'y' => 72.6, 'ancho' => 36, 'alto' => 4.4, 'tamano' => 9],
                ['campo' => 'prestamo_folio', 'pagina' => 2, 'x' => 148, 'y' => 72.6, 'ancho' => 55, 'alto' => 4.4, 'tamano' => 9],
                ['campo' => 'puesto_mayusculas', 'pagina' => 2, 'x' => 13, 'y' => 84.8, 'ancho' => 190, 'alto' => 4.4, 'tamano' => 9],
                ['campo' => 'nombre_mayusculas', 'pagina' => 2, 'x' => 13, 'y' => 96.9, 'ancho' => 36, 'alto' => 4.4, 'tamano' => 8.5],
                ['campo' => 'apellido_paterno_mayusculas', 'pagina' => 2, 'x' => 51.5, 'y' => 96.9, 'ancho' => 30, 'alto' => 4.4, 'tamano' => 8.5],
                ['campo' => 'apellido_materno_mayusculas', 'pagina' => 2, 'x' => 83.7, 'y' => 96.9, 'ancho' => 38, 'alto' => 4.4, 'tamano' => 8.5],
                ['campo' => 'rfc', 'pagina' => 2, 'x' => 123.8, 'y' => 96.9, 'ancho' => 50, 'alto' => 4.4, 'tamano' => 9],
                ['campo' => 'domicilio_cp', 'pagina' => 2, 'x' => 176.5, 'y' => 96.9, 'ancho' => 27, 'alto' => 4.4, 'tamano' => 9],
                ['campo' => 'domicilio_colonia_mayusculas', 'pagina' => 2, 'x' => 13, 'y' => 108.9, 'ancho' => 36, 'alto' => 4.4, 'tamano' => 8],
                ['campo' => 'domicilio_municipio_mayusculas', 'pagina' => 2, 'x' => 51.5, 'y' => 108.9, 'ancho' => 30, 'alto' => 4.4, 'tamano' => 8],
                ['campo' => 'domicilio_estado_mayusculas', 'pagina' => 2, 'x' => 83.7, 'y' => 108.9, 'ancho' => 38, 'alto' => 4.4, 'tamano' => 8],
                ['campo' => 'telefono', 'pagina' => 2, 'x' => 176.5, 'y' => 108.9, 'ancho' => 27, 'alto' => 4.4, 'tamano' => 9],
                // Celda "Calle": el domicilio se captura completo en un solo campo;
                // hasta 3 renglones dentro de la celda (nunca encima de Nacionalidad).
                ['campo' => 'domicilio_mayusculas', 'pagina' => 2, 'x' => 13, 'y' => 119.2, 'ancho' => 68, 'alto' => 6.6, 'tamano' => 7, 'tamano_minimo' => 5, 'multilinea' => true, 'interlineado' => 3.1],
                ['campo' => 'nacionalidad_mayusculas', 'pagina' => 2, 'x' => 84, 'y' => 120.8, 'ancho' => 63, 'alto' => 4.4, 'tamano' => 9],
                ['campo' => 'curp', 'pagina' => 2, 'x' => 149, 'y' => 120.8, 'ancho' => 54, 'alto' => 4.4, 'tamano' => 9],
                ['campo' => 'monto_prestamo', 'pagina' => 2, 'x' => 13, 'y' => 133, 'ancho' => 34, 'alto' => 4.4, 'tamano' => 9],
                ['campo' => 'plazo_prestamo_texto', 'pagina' => 2, 'x' => 149, 'y' => 131.5, 'ancho' => 24, 'alto' => 6, 'tamano' => 9, 'fondo' => '#E3E3E3'],
                ['campo' => 'plazo_prestamo', 'pagina' => 2, 'x' => 176.5, 'y' => 133, 'ancho' => 27, 'alto' => 4.4, 'tamano' => 9],
                ['campo' => 'fecha_primer_pago_corta', 'pagina' => 2, 'x' => 13, 'y' => 145.2, 'ancho' => 95, 'alto' => 4.4, 'tamano' => 9],
                ['campo' => 'fecha_ultimo_pago_corta', 'pagina' => 2, 'x' => 110.5, 'y' => 145.2, 'ancho' => 93, 'alto' => 4.4, 'tamano' => 9],
                ['campo' => 'nombre_completo_mayusculas', 'pagina' => 3, 'x' => 32, 'y' => 217.6, 'ancho' => 58, 'alto' => 4, 'tamano' => 7.5, 'alineacion' => 'C'],
                ['campo' => 'nombre_completo_mayusculas', 'pagina' => 3, 'x' => 32, 'y' => 252.3, 'ancho' => 58, 'alto' => 4, 'tamano' => 7.5, 'alineacion' => 'C'],
                ['campo' => 'representante_legal_mayusculas', 'pagina' => 3, 'x' => 124, 'y' => 252.3, 'ancho' => 61, 'alto' => 4, 'tamano' => 7.5, 'alineacion' => 'C'],
            ],
        ],

        'prestamo_pagare.general' => [
            'clave' => 'prestamo_pagare',
            'nombre' => 'Pagaré para trabajadores',
            'proceso' => 'prestamo', 'evento' => 'prestamo_autorizado', 'grupos' => null, 'motor' => 'pdf_overlay', 'categoria' => 'prestamos',
            'fuentes' => ['2fcbe22255e187dfda0d70d7aaa747d20322ed189884d206ea5d9fbb11b93679' => ['version' => 1, 'activa' => true]],
            'paginas' => [4],
            'banderas' => ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_huella' => true, 'requiere_envio_corporativo' => true],
            'representante' => 'no_aplica',
            'campos' => [
                ['campo' => 'monto_prestamo_numero', 'pagina' => 4, 'x' => 169, 'y' => 31.6, 'ancho' => 34, 'alto' => 4.4, 'tamano' => 10],
                ['campo' => 'monto_prestamo_numero', 'pagina' => 4, 'x' => 17, 'y' => 63.8, 'ancho' => 24, 'alto' => 4.4, 'tamano' => 10],
                ['campo' => 'monto_prestamo_letra', 'pagina' => 4, 'x' => 56, 'y' => 63.8, 'ancho' => 120, 'alto' => 4.4, 'tamano' => 8],
                ['campo' => 'plazo_prestamo', 'pagina' => 4, 'x' => 17, 'y' => 73.3, 'ancho' => 9.8, 'alto' => 4.4, 'tamano' => 10, 'alineacion' => 'C'],
                ['campo' => 'marca_pagos_semanales', 'pagina' => 4, 'x' => 41.5, 'y' => 72, 'ancho' => 3, 'alto' => 3, 'tamano' => 8, 'alineacion' => 'C'],
                ['campo' => 'marca_pagos_diarios', 'pagina' => 4, 'x' => 41.5, 'y' => 76.5, 'ancho' => 3, 'alto' => 3, 'tamano' => 8, 'alineacion' => 'C'],
                ['campo' => 'pago_prestamo_numero', 'pagina' => 4, 'x' => 81.5, 'y' => 73.3, 'ancho' => 36, 'alto' => 4.4, 'tamano' => 10],
                // Termina antes de la leyenda impresa "Moneda Nacional" (≈178 mm).
                ['campo' => 'pago_prestamo_letra', 'pagina' => 4, 'x' => 117, 'y' => 72.6, 'ancho' => 60, 'alto' => 6.2, 'tamano' => 7.5, 'tamano_minimo' => 5.5, 'multilinea' => true, 'interlineado' => 2.9],
                ['campo' => 'primer_pago_dia', 'pagina' => 4, 'x' => 105.7, 'y' => 90.3, 'ancho' => 8.4, 'alto' => 4.4, 'tamano' => 9, 'alineacion' => 'C'],
                ['campo' => 'primer_pago_mes', 'pagina' => 4, 'x' => 119, 'y' => 90.3, 'ancho' => 15.4, 'alto' => 4.4, 'tamano' => 8, 'alineacion' => 'C'],
                ['campo' => 'primer_pago_anio_corto', 'pagina' => 4, 'x' => 144.7, 'y' => 90.3, 'ancho' => 7, 'alto' => 4.4, 'tamano' => 9, 'alineacion' => 'C'],
                ['campo' => 'ultimo_pago_dia', 'pagina' => 4, 'x' => 156, 'y' => 90.3, 'ancho' => 7.5, 'alto' => 4.4, 'tamano' => 9, 'alineacion' => 'C'],
                ['campo' => 'ultimo_pago_mes', 'pagina' => 4, 'x' => 169, 'y' => 90.3, 'ancho' => 15.4, 'alto' => 4.4, 'tamano' => 8, 'alineacion' => 'C'],
                ['campo' => 'ultimo_pago_anio_corto', 'pagina' => 4, 'x' => 195, 'y' => 90.3, 'ancho' => 7, 'alto' => 4.4, 'tamano' => 9, 'alineacion' => 'C'],
                ['campo' => 'suscripcion_dia', 'pagina' => 4, 'x' => 73.7, 'y' => 158.8, 'ancho' => 8.4, 'alto' => 4.4, 'tamano' => 9, 'alineacion' => 'C'],
                ['campo' => 'suscripcion_mes', 'pagina' => 4, 'x' => 89.1, 'y' => 158.8, 'ancho' => 15.4, 'alto' => 4.4, 'tamano' => 8, 'alineacion' => 'C'],
                ['campo' => 'suscripcion_anio', 'pagina' => 4, 'x' => 113.8, 'y' => 158.8, 'ancho' => 12, 'alto' => 4.4, 'tamano' => 9, 'alineacion' => 'C'],
                ['campo' => 'nombre_mayusculas', 'pagina' => 4, 'x' => 13, 'y' => 188, 'ancho' => 60, 'alto' => 4.4, 'tamano' => 9],
                ['campo' => 'apellido_paterno_mayusculas', 'pagina' => 4, 'x' => 75.6, 'y' => 188, 'ancho' => 60, 'alto' => 4.4, 'tamano' => 9],
                ['campo' => 'apellido_materno_mayusculas', 'pagina' => 4, 'x' => 139, 'y' => 188, 'ancho' => 60, 'alto' => 4.4, 'tamano' => 9],
                ['campo' => 'domicilio_mayusculas', 'pagina' => 4, 'x' => 12.5, 'y' => 199.8, 'ancho' => 56, 'alto' => 6.0, 'tamano' => 7, 'tamano_minimo' => 5, 'multilinea' => true, 'interlineado' => 3.1],
                ['campo' => 'domicilio_colonia_mayusculas', 'pagina' => 4, 'x' => 70.6, 'y' => 200.5, 'ancho' => 42, 'alto' => 4.4, 'tamano' => 8],
                ['campo' => 'domicilio_municipio_mayusculas', 'pagina' => 4, 'x' => 116.2, 'y' => 200.5, 'ancho' => 37, 'alto' => 4.4, 'tamano' => 8],
                ['campo' => 'domicilio_estado_mayusculas', 'pagina' => 4, 'x' => 156, 'y' => 200.5, 'ancho' => 25, 'alto' => 4.4, 'tamano' => 8],
                ['campo' => 'domicilio_cp', 'pagina' => 4, 'x' => 184, 'y' => 200.5, 'ancho' => 16, 'alto' => 4.4, 'tamano' => 8],
                ['campo' => 'fecha_otorgamiento_corta', 'pagina' => 4, 'x' => 13, 'y' => 213.4, 'ancho' => 55, 'alto' => 4.4, 'tamano' => 9],
                ['campo' => 'tipo_identificacion', 'pagina' => 4, 'x' => 70.6, 'y' => 213.4, 'ancho' => 42, 'alto' => 4.4, 'tamano' => 9],
                ['campo' => 'clave_elector', 'pagina' => 4, 'x' => 116.2, 'y' => 213.4, 'ancho' => 83, 'alto' => 4.4, 'tamano' => 9],
                ['campo' => 'nombre_completo_mayusculas', 'pagina' => 4, 'x' => 77.6, 'y' => 246, 'ancho' => 61, 'alto' => 4, 'tamano' => 8, 'alineacion' => 'C'],
            ],
            'requeridos' => ['nombre_mayusculas', 'monto_prestamo_numero', 'pago_prestamo_numero', 'plazo_prestamo', 'primer_pago_dia', 'domicilio_mayusculas'],
            'observaciones' => [
                'Intereses moratorios: el pagaré deja la cantidad en blanco y PEOPLE no la tiene definida; se deja en blanco para llenado de RH/Jurídico.',
                'Domicilio del beneficiario (Av. Domingo Diez 1003) viene impreso en el original: se conserva.',
            ],
        ],

        'contrato_credito_clientes.referencia' => [
            'clave' => 'contrato_credito_clientes',
            'nombre' => 'Contrato de crédito V2026 (clientes)',
            'proceso' => 'referencia', 'evento' => null, 'grupos' => null, 'motor' => 'pdf_overlay', 'categoria' => null,
            'operativo' => false,
            'fuentes' => ['2ae1ac4fbac5b697f6f8cdc00d12516767d13efa1babf08b4d6db1fe7a6092ce' => ['version' => 1, 'activa' => true]],
            'campos' => [],
            'observaciones' => [
                'Es el contrato de crédito comercial para CLIENTES de MR. LANA (el deudor declara ser "comerciante", lleva aval, producto y CAT): no corresponde al préstamo interno a colaboradores, que usa "Contrato de crédito para colaboradores". Se inventaría como referencia; no se asigna a ningún flujo de PEOPLE.',
                'Este V2026 usa el domicilio Subida del Club #114 y atencionclientes@mr-lana.com; el de colaboradores conserva Av. Domingo Diez 1003 y atencion@mr-lana.com. Confirmar con Jurídico si el de colaboradores debe actualizarse.',
            ],
        ],
    ],

];
