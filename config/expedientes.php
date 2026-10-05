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

        // Alias EXPLÍCITOS de puesto (Excel → nombre del catálogo). Nunca
        // se hace match aproximado: sin match = conflicto.
        'alias_puestos' => [],

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
    ],

];
