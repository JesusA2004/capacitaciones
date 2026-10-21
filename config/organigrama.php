<?php

/*
|--------------------------------------------------------------------------
| Organigrama por personas
|--------------------------------------------------------------------------
|
| El organigrama "por personas" (App\Services\Organigrama\OrganigramaPersonasService)
| arma una tarjeta por colaborador siguiendo el árbol de puestos. Estos
| puestos, y todos los que cuelgan de ellos, son "de sucursal": cada
| sucursal tiene su propia rama (Gerente de Sucursal → Subgerente → Gestor →
| Volante) y un colaborador solo cuelga de alguien de SU sucursal. El resto
| (dirección, gerencias corporativas, regionales, sistemas, RH, mesa de
| control…) es corporativo y no se parte por sucursal.
|
| Son los nombres que siembra database/seeders/PuestoJerarquiaSeeder.php.
|
*/

return [
    'puestos_raiz_sucursal' => ['Gerente de Sucursal', 'Coordinadora de Sucursal'],

    // Puestos "de región" ADEMÁS de los que la matriz comercial liga a una
    // región (nodos_comerciales.puesto_id: Región Q1 → "Gerente Regional
    // Q1", Región Q3 → "Gerente Regional Q3"). El gerente de una sucursal
    // cuelga del puesto regional de SU región; si nadie lo ocupa, se ve
    // quien lo cubre (CoberturaPuesto) o VACANTE. "Gerente regional" es el
    // puesto genérico anterior (solo mientras alguien lo siga ocupando).
    'puestos_de_region' => ['Gerente regional'],

    /*
    | Regional de RESPALDO (App\Services\Organigrama\RegionalRespaldoService).
    | El personal de sucursal (Gestor, Subgerente, Gerente de Sucursal…)
    | NUNCA reporta a Dirección Comercial: si la gerencia de su sucursal y el
    | Gerente Regional de su región están vacantes (sin titular ni cobertura),
    | su jefe y responsable de aprobaciones es esta regional. Orden:
    |  1. `colaborador` (id o número de empleado) si está vigente;
    |  2. titulares vigentes de puestos regionales (los ligados a una región
    |     de la matriz + `puestos`), por id;
    |  3. quien cubre temporalmente una plaza regional.
    | Sin ningún regional: queda «sin jefe asignable» (pendiente para RH),
    | nunca Dirección Comercial.
    */
    'regional_respaldo' => [
        'puestos' => ['Gerente Regional Q1', 'Gerente Regional Q3', 'Gerente regional', 'Regional de Operaciones'],
        'colaborador' => env('ORGANIGRAMA_REGIONAL_RESPALDO'),
    ],

    /*
    | Mini-organigramas (inducción capítulo 4 y pantalla de Organigrama):
    | slug => nombre EXACTO del puesto raíz en el catálogo real (seeders
    | PuestoJerarquiaSeeder). MiniOrganigramaService::areas() ignora en
    | silencio cualquier entrada cuyo nombre ya no exista — nunca inventa un
    | puesto. Un área real sin puesto dedicado (p. ej. Jurídico Empresarial,
    | que en el catálogo actual es el puesto "Abogado") usa el nombre real
    | del puesto, no el nombre del área del PDF de inducción.
    */
    'areas' => [
        'direccion_comercial' => 'Dirección Comercial',
        'recursos_humanos' => 'Gerencia de Recursos Humanos',
        'contraloria' => 'Gerente de Contraloría',
        'sistemas' => 'Responsable de Sistemas',
        'juridico_empresarial' => 'Abogado',
        'mesa_de_control' => 'Gerente de Mesa de Control',
        'sucursal' => 'Gerente de Sucursal',
    ],

    // Capítulo 4 de Inducción Mr. Lana (InduccionMrLanaModuloSeeder, bloque
    // 'organigrama_dinamico'): las 3 vistas del PDF rediseño, resueltas al
    // puesto raíz real — nunca una imagen fija.
    'vistas_induccion' => [
        'corporativo' => 'Dirección General',
        'comercial' => 'Dirección Comercial',
        'sucursal' => 'Gerente de Sucursal',
    ],
    /*
    | Láminas del organigrama como el PDF oficial de Inducción (cap. 4, hojas
    | 17, 18 y 19): estructura y textos tal cual el documento; las PERSONAS
    | salen del sistema (colaboradores activos de cada puesto). `puestos`
    | acepta nombres exactos o patrones con % (p. ej. «Gerente Regional%»).
    */
    'laminas' => [
        'corporativo' => [
            'direccion_general' => [
                'puestos' => ['Dirección General'],
                // Titular que nombra el PDF oficial; se usa solo si nadie
                // ocupa el puesto en el sistema.
                'titular_documento' => 'LIC. ZURIEL ÁVILA MARTÍNEZ',
            ],
            'areas' => [
                ['sigla' => 'DC', 'titulo' => 'Dirección Comercial', 'icono' => 'briefcase', 'puestos' => ['Dirección Comercial', 'Director Comercial', 'Directora Comercial'], 'departamentos' => []],
                ['sigla' => 'RH', 'titulo' => 'Recursos Humanos', 'icono' => 'users', 'puestos' => ['Gerencia de Recursos Humanos', 'Gerente de Recursos Humanos%', 'Gerente de RH%', 'Jef% de Recursos Humanos%', 'Encargad% de Recursos Humanos%', 'Director% de Recursos Humanos%'], 'departamentos' => ['Recursos Humanos', 'RH']],
                ['sigla' => 'CT', 'titulo' => 'Contraloría', 'icono' => 'calculator', 'puestos' => ['Gerente de Contraloría', 'Contralor%', 'Encargad% de Contraloría%', 'Jef% de Contraloría%'], 'departamentos' => ['Contraloría']],
                ['sigla' => 'JE', 'titulo' => 'Jurídico Empresarial', 'icono' => 'gavel', 'puestos' => ['Abogad%', 'Jurídic%', 'Gerente Jurídic%', 'Encargad% Jurídic%'], 'departamentos' => ['Jurídico', 'Jurídico Empresarial']],
                ['sigla' => 'SI', 'titulo' => 'Sistemas', 'icono' => 'monitor', 'puestos' => ['Responsable de Sistemas', 'Encargad% de Sistemas%', 'Gerente de Sistemas%', 'Jef% de Sistemas%', 'Coordinador% de Sistemas%'], 'departamentos' => ['Sistemas', 'TI']],
            ],
            'nota' => 'Las cinco áreas reportan directamente a la Dirección General',
        ],
        'comercial' => [
            'director' => ['titulo' => 'Director Comercial', 'puestos' => ['Dirección Comercial', 'Director Comercial', 'Directora Comercial']],
            'apoyo' => [
                ['titulo' => 'Asistente de Dirección Comercial', 'puestos' => ['Asistente de Dirección Comercial']],
                ['titulo' => 'Monitorista de Cámaras', 'puestos' => ['Monitorista%']],
            ],
            'columnas' => [
                [
                    'titulo' => 'Regional Comercial', 'icono' => 'map-pin', 'puestos' => ['Gerente Regional%'],
                    'cadena' => [
                        ['titulo' => 'Gerente de Sucursal', 'icono' => 'store', 'puestos' => ['Gerente de Sucursal']],
                        ['titulo' => 'Subgerente de Sucursal', 'icono' => 'user-check', 'puestos' => ['Subgerente%']],
                        ['titulo' => 'Gestores de Crédito', 'icono' => 'users', 'puestos' => ['Gestor%'], 'destacado' => true],
                    ],
                ],
                [
                    'titulo' => 'Regional Administrativo', 'icono' => 'folder', 'puestos' => ['Coordinadora Regional', 'Regional Administrativ%'],
                    'cadena' => [
                        ['titulo' => 'Coordinadoras de Sucursal', 'icono' => 'clipboard', 'puestos' => ['Coordinadora de Sucursal']],
                    ],
                ],
                [
                    'titulo' => 'Gerente de Mesa de Control', 'icono' => 'clipboard-check', 'puestos' => ['Gerente de Mesa de Control'],
                    'hijos' => [
                        ['titulo' => 'Analistas de Mesa de Control', 'icono' => 'search', 'puestos' => ['Analista de Mesa de Control']],
                        ['titulo' => 'Auditora de Mesa de Control', 'icono' => 'search-check', 'puestos' => ['Auditora de Mesa de Control', 'Auditora']],
                    ],
                ],
                [
                    'titulo' => 'Gerente de Auditoría', 'icono' => 'file-search', 'puestos' => ['Gerente de Auditoría%'],
                    'hijos' => [
                        ['titulo' => 'Auditores de Sucursales', 'icono' => 'shield-check', 'puestos' => ['Auditor de Sucursal%', 'Auditores de Sucursal%']],
                    ],
                ],
            ],
            'leyenda' => [
                'directa' => 'Línea de mando directa.',
                'apoyo' => 'Puestos de apoyo con reporte directo a la Dirección Comercial (Asistente de Dirección Comercial y Monitorista de Cámaras).',
            ],
        ],
        'sucursal' => [
            'gerente' => [
                'titulo' => 'Gerente de Sucursal', 'puestos' => ['Gerente de Sucursal'],
                'funciones' => ['Gestionar la operación de la sucursal', 'Generar y revisar reportes del día', 'Lograr los objetivos', 'Auditar cartera de clientes', 'Supervisar a los Gestores de Crédito', 'Medir el desempeño del personal'],
            ],
            'subgerente' => [
                'titulo' => 'Subgerente de Sucursal', 'puestos' => ['Subgerente%'],
                'funciones' => ['Asignar cartera a los gestores', 'Supervisar a los Gestores de Crédito', 'Realizar cobranza', 'Auditar cartera de clientes', 'Entregar resultados y KPI’s', 'Medición del desempeño del personal'],
            ],
            'coordinacion' => [
                'titulo' => 'Coordinación', 'puestos' => ['Coordinadora de Sucursal'],
                'funciones' => ['Revisión de expedientes', 'Reportes diarios y nómina de RH', 'Dispersión y recepción de efectivo', 'Gestión de cobranza y auditoría', 'Archivos y cuadre de caja', 'Depósito de seguros acumulados'],
            ],
            'gestores' => [
                'titulo' => 'Gestores de Crédito', 'puestos' => ['Gestor%'],
                'funciones' => ['Prospectar clientes', 'Verificar mora de la cartera', 'Mantener cartera activa', 'Integrar expediente del cliente', 'Realizar cobranza', 'Realizar cuadre diario de la cartera'],
            ],
            'leyenda' => [
                'apoyo' => 'La Coordinación mantiene una relación de apoyo administrativo con la Gerencia de Sucursal.',
                'directa' => 'Línea de mando directa.',
            ],
        ],
    ],
];
