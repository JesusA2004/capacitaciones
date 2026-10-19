<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Disco de almacenamiento de documentos de expediente
    |--------------------------------------------------------------------------
    |
    | Disco registrado en config/filesystems.php usado por
    | App\Services\Expedientes\DocumentoStorageService. Nunca se expone al
    | frontend la ruta fisica real de este disco; el navegador solo conoce
    | IDs de documento (employee_documents.id) y descarga a traves de una
    | ruta protegida por policy (ver routes/rh.php).
    |
    */
    'disk' => env('EXPEDIENTES_DISK', 'nas'),

    /*
    |--------------------------------------------------------------------------
    | Limite de tamano por archivo (MB)
    |--------------------------------------------------------------------------
    */
    'max_upload_mb' => (int) env('EXPEDIENTES_MAX_UPLOAD_MB', 20),

    /*
    |--------------------------------------------------------------------------
    | Extensiones permitidas para documentos de expediente
    |--------------------------------------------------------------------------
    */
    'extensiones_permitidas' => ['pdf', 'jpg', 'jpeg', 'png'],

    /*
    |--------------------------------------------------------------------------
    | Migración inicial de colaboradores y expedientes históricos
    |--------------------------------------------------------------------------
    |
    | docs/MIGRACION_INICIAL_EXPEDIENTES.md.
    |
    | origen = 'sitio' (por defecto): los expedientes YA están en su
    |   ubicación definitiva del disco de expedientes:
    |   expedientes/{empresa}/{SUCURSAL}/{CARPETA}/*.pdf → solo se calcula el
    |   hash y se registran en BD (no se copian, renombran ni borran).
    | origen = 'legacy': se copian desde {disco_origen}:{ruta_origen}
    |   (p. ej. RH/Martha/EXPEDIENTES DIGITALES) con verificación SHA-256.
    |
    */
    'migracion_inicial' => [
        'origen' => env('EXPEDIENTES_MIGRACION_ORIGEN', 'sitio'),
        'disco_origen' => env('EXPEDIENTES_LEGACY_DISK', 'nas_legacy'),
        'ruta_origen' => env('EXPEDIENTES_LEGACY_RUTA', 'RH/Martha/EXPEDIENTES DIGITALES'),
        'hoja' => 'BASE_GENERAL',

        // Formato NUEVO por sucursales («BASE DE DATOS MR. LANA PEOPLE
        // SEMANA 41»): hojas que el adaptador LectorBaseSemana41 concatena.
        // La detección de formato (FabricaLectorMigracion) elige este lector
        // cuando el .xlsx trae cualquiera de estas hojas y no la BASE_GENERAL.
        'hojas_semana41' => [
            'sucursales' => 'Sucursales',
            'corporativo' => 'Corporativo',
        ],

        'empresa' => env('EXPEDIENTES_MIGRACION_EMPRESA', 'Mr. Lana'),

        // WHITELIST: las únicas sucursales que existen (11 operativas +
        // Corporativo), todas de Mr. Lana. Cualquier otra carpeta/sucursal
        // queda en revisión manual, nunca se vincula sola.
        'sucursales_permitidas' => [
            'Atlacomulco', 'Ixtlahuaca', 'Tula', 'Cuernavaca', 'Miacatlán', 'Atlixco',
            'San Luis Potosí', 'Huamantla', 'Tlaxcala', 'Córdoba', 'Orizaba', 'Corporativo',
        ],

        // No se buscan, no se importan y su ausencia no es error.
        'sucursales_excluidas' => ['AGUASCALIENTES', 'TULANCINGO', 'COLOMBIA'],

        // Solo typos/variantes de sucursales de la whitelist (sin acentos,
        // mayúsculas) => nombre oficial. «MR. LANA» NO es Corporativo.
        'alias_sucursales' => [
            'ATLACOMULC' => 'Atlacomulco',
            'HUMANTLA' => 'Huamantla',
            'SAN LUIS P' => 'San Luis Potosí',
            'SLP' => 'San Luis Potosí',
            'TULA DE ALLENDE' => 'Tula',
        ],

        // Alias EXPLÍCITOS de puesto: nombre legacy de BASE_GENERAL → puesto
        // CANÓNICO del organigrama confirmado (SincronizadorOrganigramaService).
        // La comparación ignora mayúsculas/acentos/signos («gestor de Credito»
        // = «Gestor de Crédito»). Sin match = conflicto; nunca aproximado.
        // NO van aquí los ambiguos (dependen de sucursal/región/departamento):
        // «Regional de Operaciones», «Asistente de Dirección» y «Coordinadora
        // Administrativa» los resuelve ResolutorCatalogoMigracion.
        // Iguales sin alias: Gerente de Sucursal, Subgerente, Monitorista,
        // Reclutamiento, Gerente/Analista de Mesa de Control. Nuevos reales
        // (CatalogosBaseGeneralSeeder): Abogado, Auditora, Escolta, Limpieza, Jardinero.
        'alias_puestos' => [
            'Gestor de Credito' => 'Gestor',
            'Director General' => 'Dirección General',
            'Director Comercial' => 'Dirección Comercial',
            'Gerente R.H.' => 'Gerencia de Recursos Humanos',
            'Jefe de sistemas' => 'Responsable de Sistemas',
            'Admon de personal' => 'Administración de Personal',
            'Tesoreria' => 'Tesorero',
            'Contralora' => 'Gerente de Contraloría',
            'Regional Administrativa' => 'Coordinadora Regional',
        ],

        // Igual para «Departamento» (solo se valida y audita: el departamento
        // que se guarda es el del puesto canónico). Iguales sin alias:
        // Dirección, Sistemas, Mesa de Control, Operaciones, Contraloria
        // (= Contraloría), Juridico (= Jurídico) y Mantenimiento (estos dos,
        // de CatalogosBaseGeneralSeeder). Sin match = CONFLICTO, nunca se crea.
        'alias_departamentos' => [
            'R.H.' => 'Recursos Humanos',
            'Operación' => 'Operaciones',
            // En el organigrama la coordinación administrativa de sucursal
            // (Coordinadora de Sucursal / Regional) vive en Operaciones.
            'Administración' => 'Operaciones',
        ],

        // Personas de la hoja CONTACTOS_SIN_MATCH: nunca se importan solas.
        'personas_excluidas' => [
            'JESUS ENRIQUE OCAMPO PEREZ',
            'JONATHAN DAVID ZARATE MIRANDA',
            'ANOHARD SANCHEZ JIMENEZ',
            'LUIS GERARDO ROMERO ROMERO',
            'CARLOS ALEXIS ARAGON PONCE',
        ],

        // Match de carpeta: exacto (mismo nombre) / alto (≥ umbral_alto y
        // único) se aplican solos; revisión manual nunca.
        'umbral_alto' => 0.85,
        'umbral_revision' => 0.55,

        'carpeta_historico' => 'Historico',
        'nombre_pdf' => 'Expediente historico unificado',
        'carpeta_pendientes' => 'Pendientes de vincular',

        // Carpetas dentro de una sucursal que NO son una persona: nunca son
        // candidatas de nadie (se comparan sin acentos/mayúsculas). Además se
        // ignoran siempre «Pendientes de vincular» y las carpetas que crea el
        // propio sistema («EMP-0007 - Nombre», «SIN-NUMERO-22 - Nombre»).
        'carpetas_excluidas' => ['BAJAS', 'BAJA', 'FOTOS', 'FOTO', 'Historico'],
    ],

    /*
    |--------------------------------------------------------------------------
    | División del PDF histórico por checklist
    |--------------------------------------------------------------------------
    |
    | docs/DIVISION_EXPEDIENTE_HISTORICO.md. El PDF único de la migración
    | inicial se LEE página por página (texto embebido; OCR local como
    | respaldo para páginas escaneadas), cada página se clasifica contra los
    | tipos del checklist con estas reglas de palabras clave y se generan
    | COPIAS por documento. El original nunca se corta, mueve ni borra.
    |
    | Reglas por clave real de document_types (DocumentTypeSeeder):
    |  - fuertes: frases que por sí solas identifican el documento (3 pts;
    |    +2 si aparecen en el encabezado de la página).
    |  - debiles: frases de apoyo (1 pt cada una).
    |  - continuacion: una página CON texto pero sin coincidencias que sigue
    |    a una de este tipo se considera su continuación (confianza baja),
    |    p. ej. la hoja 2 de un contrato.
    |  - campos: datos personales que se aceptan de ESE documento para el
    |    reporte de datos faltantes (un NSS de 11 dígitos en un recibo de luz
    |    no es un NSS). Claves de DocumentExtractionService::CAMPO_COLUMNA.
    |
    | La comparación ignora mayúsculas, acentos y espacios repetidos. Sube
    | `version_reglas` cada vez que cambies una regla: una división ya hecha
    | con otra versión se puede repetir con --forzar.
    |
    */
    'division_historico' => [
        'version_reglas' => 1,

        // Puntaje mínimo para clasificar una página (menos = «sin clasificar»).
        'puntaje_minimo' => 2,

        // Menos caracteres útiles que esto = la página no tiene capa de texto.
        'min_caracteres_texto' => 25,

        // Tope de páginas por PDF (un PDF más largo se reporta y se corta aquí).
        'max_paginas' => 250,

        // Carpeta (dentro de «Historico») donde se guardan las copias por parte.
        'carpeta_partes' => 'Division por checklist',

        'ocr' => [
            // 'tesseract' (local) o 'ninguno'. Nunca un servicio externo.
            'driver' => env('EXPEDIENTES_OCR_DRIVER', 'tesseract'),
            'tesseract' => env('EXPEDIENTES_TESSERACT_PATH', env('DOCUMENTOS_TESSERACT_PATH', 'tesseract')),
            'pdftoppm' => env('EXPEDIENTES_PDFTOPPM_PATH', env('DOCUMENTOS_QA_PDFTOPPM_PATH', 'pdftoppm')),
            'idioma' => env('EXPEDIENTES_OCR_IDIOMA', 'spa'),
            'dpi' => (int) env('EXPEDIENTES_OCR_DPI', 200),
            'timeout_por_pagina' => (int) env('EXPEDIENTES_OCR_TIMEOUT', 60),
        ],

        'reglas' => [
            'solicitud_empleo' => [
                'fuertes' => ['SOLICITUD DE EMPLEO', 'SOLICITUD DE TRABAJO', 'SOLICITUD DE PERSONAL'],
                'debiles' => ['PUESTO SOLICITADO', 'SUELDO DESEADO', 'SUELDO MENSUAL DESEADO', 'EXPERIENCIA LABORAL', 'EMPLEO ACTUAL', 'EMPLEOS ANTERIORES', 'REFERENCIAS PERSONALES', 'ESCOLARIDAD', 'DATOS FAMILIARES'],
                'continuacion' => true,
                'campos' => ['curp', 'rfc', 'nss', 'fecha_nacimiento', 'codigo_postal', 'sexo', 'correo', 'telefono', 'nacionalidad', 'estado_civil', 'lugar_nacimiento', 'domicilio', 'colonia', 'municipio', 'estado_domicilio', 'contacto_emergencia_nombre', 'contacto_emergencia_telefono', 'contacto_emergencia_parentesco', 'beneficiario_nombre', 'beneficiario_parentesco'],
            ],
            'fotografia' => [
                // Una fotografía no trae texto: casi siempre queda «sin
                // clasificar» y RH la asigna a mano desde la pantalla.
                'fuertes' => [],
                'debiles' => [],
                'continuacion' => false,
                'campos' => [],
            ],
            'acta_nacimiento' => [
                'fuertes' => ['ACTA DE NACIMIENTO', 'CERTIFICACION DE NACIMIENTO', 'COPIA CERTIFICADA DEL ACTA', 'REGISTRO CIVIL'],
                'debiles' => ['OFICIALIA', 'LIBRO', 'FOJA', 'PROGENITORES', 'DATOS DE LOS PADRES', 'LUGAR DE NACIMIENTO', 'IDENTIFICADOR ELECTRONICO', 'CRIP'],
                'continuacion' => false,
                'campos' => ['curp', 'fecha_nacimiento', 'sexo', 'lugar_nacimiento'],
            ],
            'ine' => [
                'fuertes' => ['INSTITUTO NACIONAL ELECTORAL', 'INSTITUTO FEDERAL ELECTORAL', 'CREDENCIAL PARA VOTAR'],
                'debiles' => ['CLAVE DE ELECTOR', 'SECCION', 'VIGENCIA', 'ANO DE REGISTRO', 'EMISION', 'LOCALIDAD'],
                'continuacion' => false,
                'campos' => ['curp', 'fecha_nacimiento', 'sexo', 'clave_elector', 'domicilio', 'codigo_postal'],
            ],
            'nss' => [
                'fuertes' => ['NUMERO DE SEGURIDAD SOCIAL', 'ASIGNACION DE NUMERO DE SEGURIDAD SOCIAL', 'LOCALIZACION DEL NUMERO DE SEGURIDAD SOCIAL', 'CONSTANCIA DE VIGENCIA DE DERECHOS'],
                'debiles' => ['INSTITUTO MEXICANO DEL SEGURO SOCIAL', 'IMSS', 'NSS', 'SEGURO SOCIAL'],
                'continuacion' => false,
                'campos' => ['nss', 'curp'],
            ],
            'curp' => [
                'fuertes' => ['CLAVE UNICA DE REGISTRO DE POBLACION', 'CONSTANCIA DE LA CLAVE UNICA'],
                'debiles' => ['RENAPO', 'REGISTRO NACIONAL DE POBLACION', 'CURP', 'ENTIDAD DE REGISTRO'],
                'continuacion' => false,
                'campos' => ['curp', 'fecha_nacimiento', 'sexo'],
            ],
            'rfc' => [
                'fuertes' => ['CONSTANCIA DE SITUACION FISCAL', 'CEDULA DE IDENTIFICACION FISCAL'],
                'debiles' => ['SERVICIO DE ADMINISTRACION TRIBUTARIA', 'IDCIF', 'REGIMEN', 'REGIMENES', 'DATOS DE IDENTIFICACION DEL CONTRIBUYENTE', 'RFC'],
                'continuacion' => false,
                'campos' => ['rfc', 'curp', 'codigo_postal', 'colonia', 'municipio', 'estado_domicilio'],
            ],
            'comprobante_domicilio' => [
                'fuertes' => ['COMISION FEDERAL DE ELECTRICIDAD', 'AVISO RECIBO', 'RECIBO DE AGUA', 'RECIBO DE LUZ', 'TELEFONOS DE MEXICO', 'COMPROBANTE DE DOMICILIO'],
                'debiles' => ['CFE', 'TELMEX', 'IZZI', 'TOTALPLAY', 'MEGACABLE', 'PERIODO FACTURADO', 'TOTAL A PAGAR', 'FECHA LIMITE DE PAGO', 'KWH', 'CONSUMO', 'SERVICIO'],
                'continuacion' => false,
                'campos' => ['codigo_postal'],
            ],
            'comprobante_estudios' => [
                'fuertes' => ['CERTIFICADO DE ESTUDIOS', 'CERTIFICADO DE TERMINACION DE ESTUDIOS', 'CERTIFICADO DE BACHILLERATO', 'TITULO PROFESIONAL', 'CEDULA PROFESIONAL', 'CONSTANCIA DE ESTUDIOS', 'HISTORIAL ACADEMICO', 'KARDEX'],
                'debiles' => ['SECRETARIA DE EDUCACION PUBLICA', 'EDUCACION SECUNDARIA', 'EDUCACION MEDIA SUPERIOR', 'PREPARATORIA', 'LICENCIATURA', 'PROMEDIO', 'CALIFICACION', 'PLANTEL'],
                'continuacion' => false,
                'campos' => ['curp'],
            ],
            'cartas_recomendacion' => [
                'fuertes' => ['CARTA DE RECOMENDACION', 'CARTA DE REFERENCIA'],
                'debiles' => ['A QUIEN CORRESPONDA', 'RECOMIENDO', 'RECOMENDACION', 'PERSONA HONESTA', 'RESPONSABLE Y TRABAJADOR', 'TENGO EL GUSTO', 'CONOZCO'],
                'continuacion' => false,
                'campos' => [],
            ],
            'datos_bancarios' => [
                'fuertes' => ['CLABE INTERBANCARIA', 'DATOS BANCARIOS', 'CONTRATO DE APERTURA', 'NUMERO DE TARJETA'],
                'debiles' => ['CLABE', 'NUMERO DE CUENTA', 'BBVA', 'BANCOMER', 'BANORTE', 'SANTANDER', 'BANAMEX', 'BANCO AZTECA', 'HSBC', 'SCOTIABANK', 'BANCOPPEL', 'NOMINA'],
                'continuacion' => false,
                'campos' => [],
            ],
            'contrato' => [
                'fuertes' => ['CONTRATO INDIVIDUAL DE TRABAJO', 'CONTRATO DE TRABAJO POR TIEMPO', 'CONTRATO DE TRABAJO'],
                'debiles' => ['CLAUSULAS', 'DECLARACIONES', 'EL PATRON', 'EL TRABAJADOR', 'LEY FEDERAL DEL TRABAJO', 'JORNADA', 'SALARIO'],
                'continuacion' => true,
                'campos' => ['curp', 'rfc', 'nss', 'domicilio'],
            ],
            'carta_confidencialidad' => [
                'fuertes' => ['CONVENIO DE CONFIDENCIALIDAD', 'CARTA DE CONFIDENCIALIDAD', 'CONTRATO DE CONFIDENCIALIDAD', 'ACUERDO DE CONFIDENCIALIDAD'],
                'debiles' => ['INFORMACION CONFIDENCIAL', 'CONFIDENCIALIDAD', 'NO DIVULGACION', 'SECRETO INDUSTRIAL'],
                'continuacion' => true,
                'campos' => [],
            ],
            'contrato_no_competencia' => [
                'fuertes' => ['CONTRATO DE NO COMPETENCIA', 'CONVENIO DE NO COMPETENCIA', 'CLAUSULA DE NO COMPETENCIA'],
                'debiles' => ['NO COMPETENCIA', 'NO COMPETIR', 'ACTIVIDADES SIMILARES', 'EMPRESAS COMPETIDORAS'],
                'continuacion' => true,
                'campos' => [],
            ],
        ],
    ],

];
