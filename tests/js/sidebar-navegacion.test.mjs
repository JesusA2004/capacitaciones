/**
 * Estructura del sidebar operativo (RH/admin): grupos por etapa del ciclo
 * laboral y sus accesos, en orden exacto. La fuente única es
 * resources/js/composables/useMainNavItems.ts (depende de Vue/Inertia/
 * Wayfinder, así que aquí se lee su código fuente en lugar de importarlo).
 * Ver docs/ROLES_Y_NAVEGACION.md.
 *
 *   npm run test:js
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const raiz = new URL('../../', import.meta.url);
const leer = (ruta) => readFileSync(new URL(ruta, raiz), 'utf8');

const fuente = leer('resources/js/composables/useMainNavItems.ts');
const navMain = leer('resources/js/components/NavMain.vue');
const sidebar = leer('resources/js/components/ui/sidebar/Sidebar.vue');
const provider = leer('resources/js/components/ui/sidebar/SidebarProvider.vue');

// Solo el bloque del modo operativo (de `gruposOperativo` a su `return`).
const operativo = fuente.slice(
    fuente.indexOf('const gruposOperativo'),
    fuente.indexOf('return grupos.filter'),
);

/** [{ titulo, items: [title…] }] en el orden en que aparecen en el código. */
function gruposDeclarados() {
    const grupos = [];
    const patron = /titulo: '([^']+)'|title: '([^']+)'/g;

    for (const m of operativo.matchAll(patron)) {
        if (m[1]) {
            grupos.push({ titulo: m[1], items: [] });
        } else {
            grupos.at(-1).items.push(m[2]);
        }
    }

    return grupos;
}

const ESPERADO = [
    // «Tablero de RH» / «Mi bandeja de bajas» son las dos ramas del mismo
    // acceso (según tenga o no tablero). «Solicitudes» (bandeja de RH) vive
    // en Inicio, siempre a la vista (2026-11-12).
    [
        'Inicio',
        [
            'Tablero de RH',
            'Mi bandeja de bajas',
            'Directorio',
            'Pendientes',
            'Solicitudes',
        ],
    ],
    [
        'Reclutamiento y selección',
        [
            'Vacantes',
            'Campañas',
            'Descriptivos y perfiles',
            'Candidatos',
            'Agenda de entrevistas',
        ],
    ],
    [
        'Contratación y expediente',
        [
            'Personas en contratación',
            // Solo aplica a quien ya está en contratación (antes en el grupo 1).
            'Invitaciones QR',
            'Pendientes de alta en sistemas',
            'Pendientes de baja en sistemas',
            'Validación de documentos',
            'Contratos de ingreso',
            'Contratos de la plantilla',
            'Cartas compromiso',
        ],
    ],
    [
        'Onboarding',
        [
            'Inducción institucional',
            'Inducción al puesto',
            'Configuración de onboarding',
            'Calificaciones y refuerzo',
            'Activos y responsivas',
        ],
    ],
    ['Cierre del periodo de prueba', ['Evaluaciones de periodo de prueba']],
    [
        'Permanencia',
        [
            'Colaboradores',
            'Fechas laborales',
            'Incapacidades',
            'Actas administrativas',
            'Recibos de nómina',
            'Avisos',
            'Celebraciones',
        ],
    ],
    // Etapa 6 · Desarrollo = Capacitación (Fase 3). Los accesos marcador
    // href '#' del antiguo grupo «Desarrollo» ya no existen (2026-11-17).
    [
        'Capacitación',
        [
            'Portada de capacitación',
            'Cursos y aulas',
            'Asignaciones',
            'Calificar cuestionarios',
            'Calificar actividades',
            'Banco de preguntas',
            'Biblioteca multimedia',
            'Calendario de sesiones',
            'Reporte de cumplimiento',
            'Certificación Mr. Lana',
        ],
    ],
    ['Cierres laborales', ['Bajas', 'Reingresos']],
    [
        'Organización',
        [
            'Organigrama',
            // Responsable de aprobación explícito ≠ jefe organizacional (2026-10-20).
            'Responsables de aprobación',
            'Puestos y salarios',
            'Sucursales',
            'Departamentos',
            'Empresas',
        ],
    ],
    [
        // Fase 2 (docs/SEGUIMIENTO.md, docs/NINE_BOX.md,
        // docs/EVALUACIONES_TECNICAS.md): bloque transversal, sin número.
        'Seguimiento',
        [
            'Por sucursal',
            'Por gestor',
            'Nine Box',
            'Seguimientos técnicos',
            'Calendario de seguimientos',
            'Vínculos con Reportes',
        ],
    ],
    [
        'Reportes',
        [
            'Rotación de personal',
            'Reportes de RH',
            'Mis reportes',
            'Recepción documental',
        ],
    ],
    [
        'Configuración',
        [
            'Formularios de evaluación',
            'Documentos maestros',
            'Catálogo de causales',
            'Usuarios y permisos',
            'Roles y permisos',
            'Importar datos',
            'Ajustes y notificaciones',
            'Notificaciones del sistema',
            'Versiones de app',
        ],
    ],
];

test('el sidebar operativo tiene exactamente los grupos y accesos, en orden', () => {
    assert.deepEqual(
        gruposDeclarados().map((g) => [g.titulo, g.items]),
        ESPERADO,
    );
});

const TRANSVERSALES = [
    'Inicio',
    'Organización',
    'Seguimiento',
    'Reportes',
    'Configuración',
];

test('las etapas 1–7 llevan su número, sin huecos, y los bloques transversales no', () => {
    const numeros = [
        ...operativo.matchAll(/numero: (\d+),\s*titulo: '([^']+)'/g),
    ].map((m) => [Number(m[1]), m[2]]);

    assert.deepEqual(
        numeros,
        ESPERADO.filter(([titulo]) => !TRANSVERSALES.includes(titulo)).map(
            ([titulo], i) => [i + 1, titulo],
        ),
    );
    assert.equal(numeros.length, 7);
});

/** Texto de la declaración de un acceso (desde la condición previa hasta su `}`). */
function declaracion(titulo) {
    const i = operativo.indexOf(`title: '${titulo}'`);
    const inicio = Math.max(
        operativo.lastIndexOf('},', i),
        operativo.lastIndexOf('[', i),
    );

    return operativo.slice(inicio, operativo.indexOf('}', i) + 1);
}

test('cada acceso (salvo Tablero/Bandeja y Directorio) está condicionado por permiso o bandera', () => {
    // El inicio siempre existe (rama según dashboard.*.ver) y el directorio
    // es de toda la empresa (cualquier usuario autenticado).
    const abiertos = ['Tablero de RH', 'Mi bandeja de bajas', 'Directorio'];

    for (const [, items] of ESPERADO) {
        for (const titulo of items) {
            if (abiertos.includes(titulo)) {
                continue;
            }

            assert.match(
                declaracion(titulo),
                /tienePermiso\(|algunPermiso\(|features\.value\.|capacitacionActiva\.value/,
                `«${titulo}» no está condicionado por permiso/bandera`,
            );
        }
    }
});

test('cada acceso nuevo o reacomodado exige el permiso real de su controlador', () => {
    const permisos = {
        Solicitudes: ['solicitudes.revisar', 'solicitudes.aprobar', 'rh.solicitudes.ver'],
        'Invitaciones QR': ['rh.incorporacion.invitaciones.ver'],
        'Pendientes de baja en sistemas': ['sistemas.altas.ver'],
        'Contratos de la plantilla': ['contratos.ver'],
        'Fechas laborales': ['expedientes.editar_fechas'],
        'Recibos de nómina': ['nomina.recibos.ver'],
        Avisos: ['avisos.ver', 'avisos.enviar'],
        'Portada de capacitación': ['cursos.ver', 'asignaciones.ver', 'respuestas.ver'],
        'Cursos y aulas': ['cursos.ver'],
        Asignaciones: ['asignaciones.ver'],
        'Calificar cuestionarios': ['respuestas.ver'],
        'Calificar actividades': ['respuestas.ver'],
        'Certificación Mr. Lana': ['cursos.ver', 'asignaciones.ver', 'respuestas.ver'],
        'Por sucursal': ['seguimiento.ver'],
        'Por gestor': ['seguimiento.ver'],
        'Nine Box': ['nine_box.ver'],
        'Seguimientos técnicos': ['evaluaciones_tecnicas.ver'],
        'Calendario de seguimientos': ['evaluaciones_tecnicas.configurar'],
        'Vínculos con Reportes': ['seguimiento.vincular'],
        'Catálogo de causales': ['finiquitos.causales_rotacion', 'finiquitos.ver'],
    };

    for (const [titulo, lista] of Object.entries(permisos)) {
        for (const permiso of lista) {
            assert.ok(
                declaracion(titulo).includes(`'${permiso}'`),
                `«${titulo}» no exige ${permiso}`,
            );
        }
    }

    // Todo el grupo Capacitación queda tras su feature flag.
    for (const titulo of ESPERADO.find(([g]) => g === 'Capacitación')[1]) {
        assert.match(declaracion(titulo), /capacitacionActiva\.value &&/);
    }
});

test('las importaciones solo se ofrecen con permisos de importación (solo super_admin, 2026-10-20)', () => {
    const permisosImportacion = [
        'expedientes.migrar',
        'headcount.importar',
        'reportes_rh.importar_historico',
        'colaboradores.importar_salarios_vacaciones',
        'rotacion.cargar',
        'perfiles_puesto.importar',
        'contactos_emergencia.importar',
        'nomina.recibos.importar',
    ];

    // Las cargas por Excel ya no tienen acceso propio en Permanencia: todo
    // entra por Configuración → Importar datos.
    for (const titulo of ['Importar salarios y vacaciones', 'Contactos de emergencia']) {
        assert.ok(
            !operativo.includes(`title: '${titulo}'`),
            `«${titulo}» no debe tener acceso propio en el menú`,
        );
    }

    const hub = declaracion('Importar datos');

    for (const permiso of permisosImportacion) {
        assert.ok(hub.includes(`'${permiso}'`), `«Importar datos» no considera ${permiso}`);
    }

    // Ningún permiso de RH de uso diario (editar expedientes o perfiles)
    // abre una pantalla de importación.
    assert.doesNotMatch(hub, /'expedientes\.editar'|'perfiles_puesto\.editar'/);
});

test('los módulos con bandera quedan tras su feature flag y nunca se pintan con href "#"', () => {
    const marcadores = {
        'Por sucursal': 'seguimiento',
        'Por gestor': 'seguimiento',
        'Nine Box': 'nine_box',
        'Vínculos con Reportes': 'seguimiento',
        'Certificación Mr. Lana': 'certificacion',
    };

    // Ningún acceso declara un href marcador: un módulo sin pantalla no
    // tiene entrada en el menú (Evaluación del desempeño, 2026-11-17).
    assert.doesNotMatch(operativo, /href: '#'/);

    for (const [titulo, bandera] of Object.entries(marcadores)) {
        assert.match(
            declaracion(titulo),
            new RegExp(`features\\.value\\.${bandera} &&`),
        );
    }

    // Defensa: el filtro de cada grupo descarta cualquier href '#'.
    assert.match(operativo, /i !== false && i\.href !== '#'/);

    // Y las banderas existen en config/features.php: la del módulo sin
    // pantalla, apagada; Seguimiento, Nine Box y Certificación Mr. Lana,
    // encendidas.
    const features = leer('config/features.php');
    const porDefecto = {
        evaluacion_desempeno: false,
        seguimiento: true,
        nine_box: true,
        certificacion: true,
    };

    for (const [bandera, valor] of Object.entries(porDefecto)) {
        assert.match(
            features,
            new RegExp(`'${bandera}' => \\(bool\\) env\\('[A-Z_]+', ${valor}\\)`),
        );
    }
});

test('NavMain: grupos plegables, persistidos con try/catch y abiertos si contienen la ruta activa', () => {
    assert.match(navMain, /<Collapsible/);
    assert.match(
        navMain,
        /try \{\s*const valor = window\.localStorage\.getItem/,
    );
    assert.match(navMain, /try \{\s*window\.localStorage\.setItem/);
    assert.match(navMain, /leerPreferencia\(\) \?\? grupoActivo\.value/);
    assert.match(navMain, /watch\(grupoActivo/);
    // Accesos de altura mínima min-h-9 (152971ff, 2026-11-12: más área de
    // toque con el efecto «presionable») que envuelven el texto en vez de
    // cortarlo.
    assert.match(navMain, /class="relative h-auto min-h-9 /);
    assert.ok(navMain.includes('[&>span:last-child]:whitespace-normal!'));
});

test('Sidebar: modo iconos, hover sin clic solo con puntero fino y expansión superpuesta', () => {
    assert.match(
        leer('resources/js/components/AppSidebar.vue'),
        /collapsible="icon"/,
    );
    assert.match(
        sidebar,
        /useMediaQuery\("\(hover: hover\) and \(pointer: fine\)"\)/,
    );
    assert.match(sidebar, /@mouseenter="alEntrarMouse"/);
    assert.match(sidebar, /@mouseleave="alSalirMouse"/);
    // El hueco que empuja al contenido sigue el estado FIJADO, no el visible.
    assert.match(
        sidebar,
        /const pinnedCollapsed = computed\(\(\) => !open\.value\)/,
    );
    assert.match(sidebar, /:style="\{ width: gapWidth \}"/);
    // La barra visible es fija (overlay) y se expande con `hovered`.
    assert.match(sidebar, /'fixed inset-y-0 z-30/);
    assert.match(provider, /open\.value \|\| hovered\.value/);
});
