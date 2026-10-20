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
    ['Inicio', ['Tablero de RH', 'Directorio', 'Pendientes']],
    [
        'Reclutamiento y selección',
        [
            'Vacantes',
            'Campañas',
            'Descriptivos y perfiles',
            'Candidatos',
            'Invitaciones QR',
        ],
    ],
    [
        'Contratación y expediente',
        [
            'Personas en contratación',
            'Validación de documentos',
            'Contratos de ingreso',
            'Contratos',
        ],
    ],
    [
        'Onboarding',
        [
            'Inducción institucional',
            'Inducción al puesto',
            'Configuración de onboarding',
            'Evaluación de capacitación inicial',
            'Calificaciones y refuerzo',
            'Activos y responsivas',
        ],
    ],
    [
        'Cierre del periodo de prueba',
        [
            'Evaluaciones de periodo de prueba',
            'Periodos por vencer',
            'Evaluación y decisión',
        ],
    ],
    [
        'Permanencia',
        [
            'Colaboradores',
            'Solicitudes',
            'Actas administrativas',
            'Recibo simple de pago',
            'Importar salarios y vacaciones',
            'Contactos de emergencia',
            'Enviar notificación',
            'Celebraciones',
        ],
    ],
    [
        'Desarrollo',
        [
            'Evaluación del desempeño',
            'Matriz 9 box y planes',
            'Capacitación continua',
            'Certificación Mr. Lana',
        ],
    ],
    [
        'Cierres laborales',
        ['Bajas', 'Bajas y finiquitos', 'Reingresos'],
    ],
    [
        'Organización',
        [
            'Organigrama',
            'Puestos y tabulador',
            'Sucursales',
            'Departamentos',
            'Empresas',
        ],
    ],
    ['Reportes', ['Reportes de RH', 'Rotación de personal']],
    [
        'Configuración',
        [
            'Documentos maestros',
            'Catálogo de bajas',
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

test('las etapas 1–7 llevan su número y los bloques transversales no', () => {
    const numeros = [
        ...operativo.matchAll(/numero: (\d+),\s*titulo: '([^']+)'/g),
    ].map((m) => [Number(m[1]), m[2]]);

    assert.deepEqual(
        numeros,
        ESPERADO.slice(1, 8).map(([titulo], i) => [i + 1, titulo]),
    );
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

test('cada acceso (salvo Tablero y Directorio) está condicionado por permiso o bandera', () => {
    for (const [, items] of ESPERADO) {
        for (const titulo of items) {
            // El directorio es de toda la empresa (cualquier usuario autenticado).
            if (titulo === 'Tablero de RH' || titulo === 'Directorio') {
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

test('las importaciones solo se ofrecen con permisos de importación (solo super_admin, 2026-10-20)', () => {
    const permisosImportacion = [
        'expedientes.migrar',
        'headcount.importar',
        'colaboradores.importar_salarios_vacaciones',
        'rotacion.cargar',
        'perfiles_puesto.importar',
        'contactos_emergencia.importar',
        'nomina.recibos.importar',
    ];
    const gate = {
        'Importar salarios y vacaciones': /tienePermiso\(\s*'colaboradores\.importar_salarios_vacaciones',?\s*\)/,
        'Contactos de emergencia': /tienePermiso\('contactos_emergencia\.importar'\)/,
    };

    for (const [titulo, patron] of Object.entries(gate)) {
        assert.match(declaracion(titulo), patron, `«${titulo}» debe exigir su permiso de importación`);
    }

    const hub = declaracion('Importar datos');

    for (const permiso of permisosImportacion) {
        assert.ok(hub.includes(`'${permiso}'`), `«Importar datos» no considera ${permiso}`);
    }

    // Ningún permiso de RH de uso diario (editar expedientes o perfiles)
    // abre una pantalla de importación.
    for (const titulo of ['Importar datos', 'Contactos de emergencia', 'Importar salarios y vacaciones']) {
        assert.doesNotMatch(declaracion(titulo), /'expedientes\.editar'|'perfiles_puesto\.editar'/);
    }
});

test('los módulos sin pantalla de Desarrollo quedan tras su feature flag y nunca se pintan con href "#"', () => {
    const marcadores = {
        'Evaluación del desempeño': 'evaluacion_desempeno',
        'Matriz 9 box y planes': 'nine_box',
        'Certificación Mr. Lana': 'certificacion',
    };

    for (const [titulo, bandera] of Object.entries(marcadores)) {
        assert.match(
            declaracion(titulo),
            new RegExp(`features\\.value\\.${bandera} &&`),
        );
    }

    // Defensa: el filtro de cada grupo descarta cualquier href '#'.
    assert.match(operativo, /i !== false && i\.href !== '#'/);

    // Y las banderas existen en config/features.php, apagadas por defecto.
    const features = leer('config/features.php');

    for (const bandera of Object.values(marcadores)) {
        assert.match(
            features,
            new RegExp(`'${bandera}' => \\(bool\\) env\\('[A-Z_]+', false\\)`),
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
    // Accesos compactos (min-h-8) que envuelven el texto en vez de cortarlo.
    assert.match(navMain, /class="relative h-auto min-h-8 /);
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
