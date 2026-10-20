<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/*
 * Auditoría estática de la guía (resources/js/lib/tours/modulos.ts) contra
 * el sidebar real (AppSidebar.vue) y contra los anclajes data-tour de las
 * pantallas. Regresión de producción: la guía de Cumpleaños esperaba
 * selectores que CelebracionesPanel ya no tenía y parecía congelada, y
 * Aniversarios/Formatos no tenían guía completa.
 */

function archivoJs(string $ruta): string
{
    return (string) file_get_contents(base_path('resources/js/'.$ruta));
}

/**
 * @return array<string, string> id del módulo => bloque de texto del módulo
 */
function modulosDeLaGuia(): array
{
    $fuente = archivoJs('lib/tours/modulos.ts');
    $partes = preg_split("/\n        id: '/", $fuente) ?: [];
    array_shift($partes);

    $modulos = [];
    foreach ($partes as $parte) {
        $id = substr($parte, 0, (int) strpos($parte, "'"));
        $modulos[$id] = $parte;
    }

    return $modulos;
}

/**
 * @return list<string>
 */
function selectoresDe(string $bloque): array
{
    preg_match_all("/sel\\('([a-z0-9-]+)'\\)/", $bloque, $m);

    return array_values(array_unique($m[1]));
}

/**
 * Contenido de un .vue más el de todos los componentes que importa,
 * recursivamente (resuelve el alias `@/`).
 */
function contenidoConImportados(string $ruta, array &$visitados = []): string
{
    $absoluta = realpath(base_path('resources/js/'.$ruta));

    if ($absoluta === false || isset($visitados[$absoluta])) {
        return '';
    }

    $visitados[$absoluta] = true;
    $contenido = (string) file_get_contents($absoluta);

    preg_match_all("#from '@/(components/[^']+\\.vue)'#", $contenido, $m);
    foreach ($m[1] as $importado) {
        $contenido .= contenidoConImportados($importado, $visitados);
    }

    return $contenido;
}

/**
 * Cada acceso del sidebar => módulo(s) de la guía que lo explican. Un acceso
 * nuevo sin entrada aquí hace fallar la prueba: o se le agrega guía, o se
 * justifica en SIN_GUIA.
 */
const SIDEBAR_A_GUIA = [
    // Modo operativo (títulos del sidebar por etapas, 2026-10-19/20).
    'Tablero de RH' => ['dashboard'],
    'Pendientes' => ['pendientes'],
    'Configuración de onboarding' => ['onboarding'],
    'Inducción institucional' => ['presentar-induccion'],
    'Reingresos' => ['reingresos'],
    'Ajustes y notificaciones' => ['configuracion'],
    'Colaboradores' => ['expedientes'],
    'Actas administrativas' => ['actas'],
    'Solicitudes' => ['solicitudes'],
    'Organigrama' => ['organigrama'],
    'Vacantes' => ['vacantes'],
    'Candidatos' => ['candidatos'],
    'Campañas' => ['campanas'],
    'Invitaciones QR' => ['invitaciones'],
    'Reportes de RH' => ['reportes'],
    'Celebraciones' => ['cumpleanos', 'aniversarios'],
    'Documentos maestros' => ['documentos-maestros'],
    'Empresas' => ['empresas'],
    'Usuarios y permisos' => ['usuarios'],
    'Sucursales' => ['sucursales'],
    'Departamentos' => ['departamentos'],
    'Puestos y tabulador' => ['puestos'],
    'Roles y permisos' => ['roles'],
    'Versiones de app' => ['app-releases'],
    'Descriptivos y perfiles' => ['perfiles-puesto'],
    'Rotación de personal' => ['rotacion'],
    'Directorio' => ['directorio', 'directorio-operativo'],
    // Modo colaborador.
    'Mi inicio' => ['portal'],
    'Mi expediente' => ['mi-expediente'],
    'Mis solicitudes' => ['mis-solicitudes'],
    'Mi inducción' => ['induccion'],
];

const SIDEBAR_SIN_GUIA = [
    // Es la propia página de la guía (buscador + guía escrita + recorridos).
    'Ayuda',
    // Ocultos tras el feature flag `capacitacion` (docs/CAPACITACION_PROXIMAMENTE.md).
    'Mi capacitación',
    'Capacitación continua',
    // Detrás de su feature flag apagado y con href '#': el menú nunca los
    // pinta (no tienen pantalla real todavía).
    'Evaluación del desempeño',
    'Matriz 9 box y planes',
    'Certificación Mr. Lana',
    // Recibo impreso: el acceso del colaborador solo existe con
    // features.recibos_colaborador (apagado).
    'Mi recibo',
    // Bandejas propias de notificaciones (marcar leída / ir al registro).
    'Mis notificaciones',
    'Notificaciones del sistema',
    // Formulario de un solo paso para enviar un aviso (artículo «avisos» de la Guía).
    'Enviar notificación',
    // Importaciones / cargas masivas: solo super_admin; carga puntual de un
    // archivo con vista previa (el proceso está en la Guía maestra).
    'Importar salarios y vacaciones',
    'Contactos de emergencia',
    'Importar datos',
    // Pantallas de las etapas nuevas del sidebar (contratación, onboarding,
    // periodo de prueba, cierres): aún no tienen módulo de guía propio.
    'Personas en contratación',
    'Validación de documentos',
    'Contratos de ingreso',
    'Contratos',
    'Inducción al puesto',
    'Evaluación de capacitación inicial',
    'Calificaciones y refuerzo',
    'Activos y responsivas',
    'Evaluaciones de periodo de prueba',
    'Periodos por vencer',
    'Evaluación y decisión',
    'Recibo simple de pago',
    'Bajas',
    'Bajas y finiquitos',
    // Sección «Causales» de Bajas y finiquitos (misma pantalla).
    'Catálogo de bajas',
];

test('todo acceso del sidebar tiene módulo de guía o una justificación explícita', function () {
    // Los accesos viven en useMainNavItems.ts (compartido por AppSidebar.vue
    // y MobileBottomNav.vue, ver docs/ROLES_Y_NAVEGACION.md) — ya no en
    // AppSidebar.vue directamente.
    preg_match_all("/title: '([^']+)'/", archivoJs('composables/useMainNavItems.ts'), $m);
    $titulos = array_values(array_unique($m[1]));
    $modulos = modulosDeLaGuia();

    foreach ($titulos as $titulo) {
        if (in_array($titulo, SIDEBAR_SIN_GUIA, true)) {
            continue;
        }

        expect(array_key_exists($titulo, SIDEBAR_A_GUIA))->toBeTrue("El acceso «{$titulo}» del sidebar no tiene guía (MODULOS_GUIA) ni justificación.");

        foreach (SIDEBAR_A_GUIA[$titulo] as $id) {
            expect(array_key_exists($id, $modulos))->toBeTrue("«{$titulo}» apunta al módulo de guía «{$id}», que no existe en modulos.ts.");
        }
    }

    expect(count($titulos))->toBeGreaterThanOrEqual(20);

    // Sin entradas huérfanas: un acceso renombrado o retirado del sidebar
    // también se retira de los mapas (si no, su guía deja de cubrir nada).
    foreach ([...array_keys(SIDEBAR_A_GUIA), ...SIDEBAR_SIN_GUIA] as $titulo) {
        expect(in_array($titulo, $titulos, true))->toBeTrue("«{$titulo}» está en el mapa de la guía pero ya no es un acceso del sidebar.");
    }
});

test('todo selector data-tour que usa la guía existe en alguna pantalla', function () {
    $vues = collect(File::allFiles(resource_path('js')))
        ->filter(fn ($f) => $f->getExtension() === 'vue')
        ->map(fn ($f) => $f->getContents())
        ->implode("\n");

    foreach (modulosDeLaGuia() as $id => $bloque) {
        foreach (selectoresDe($bloque) as $selector) {
            expect(str_contains($vues, sprintf('data-tour="%s"', $selector)))
                ->toBeTrue("Módulo «{$id}»: el selector [data-tour=\"{$selector}\"] no existe en ninguna pantalla.");
        }
    }
});

test('Cumpleaños y Aniversarios solo usan anclajes que existen en su pantalla real', function () {
    $modulos = modulosDeLaGuia();
    $paginas = [
        'cumpleanos' => contenidoConImportados('pages/Rh/Cumpleanos/Index.vue'),
        'aniversarios' => contenidoConImportados('pages/Rh/Aniversarios/Index.vue'),
    ];

    foreach ($paginas as $id => $contenido) {
        $selectores = selectoresDe($modulos[$id]);

        expect($selectores)->not->toContain('encabezado')
            ->not->toContain('indicadores')
            ->toContain('celebraciones-hoy')
            ->toContain('celebraciones-calendario')
            ->toContain('celebraciones-proximos');

        foreach ($selectores as $selector) {
            expect(str_contains($contenido, sprintf('data-tour="%s"', $selector)))
                ->toBeTrue("Guía «{$id}»: [data-tour=\"{$selector}\"] no está en su pantalla ni en sus componentes.");
        }
    }
});

test('Aniversarios está en la guía con su ruta, permiso y temas', function () {
    $bloque = modulosDeLaGuia()['aniversarios'];

    expect($bloque)->toContain("ruta: '/rh/aniversarios'")
        ->toContain("permisos: ['celebraciones.ver']")
        ->toContain('fecha de ingreso')
        ->toContain('Enviar al colaborador')
        ->toContain('Avisar a todos')
        ->toContain('Configuración');
});

/**
 * Pantalla(s) real(es) de cada módulo de la guía. Un módulo nuevo sin
 * entrada aquí hace fallar la prueba (así nadie agrega una guía sin
 * validar sus anclajes contra SU pantalla).
 */
const PANTALLAS_GUIA = [
    'dashboard' => ['pages/Dashboard/Global.vue', 'pages/Dashboard/Sucursal.vue'],
    'expedientes' => ['pages/Rh/Expedientes/Index.vue', 'pages/Rh/Expedientes/Show.vue'],
    'solicitudes' => ['pages/Rh/Solicitudes/Tipos.vue', 'pages/Rh/Solicitudes/Index.vue'],
    'organigrama' => ['pages/Administracion/JerarquiaPuestos/Index.vue'],
    'vacantes' => ['pages/Rh/Vacantes/Index.vue'],
    'candidatos' => ['pages/Rh/Candidatos/Index.vue'],
    'campanas' => ['pages/Rh/Campanas/Index.vue'],
    'invitaciones' => ['pages/Rh/Incorporacion/Invitaciones/Index.vue'],
    'reportes' => ['pages/Rh/Reportes/Index.vue'],
    'cumpleanos' => ['pages/Rh/Cumpleanos/Index.vue'],
    'aniversarios' => ['pages/Rh/Aniversarios/Index.vue'],
    'documentos-maestros' => ['pages/Rh/DocumentosMaestros/Index.vue'],
    'empresas' => ['pages/Administracion/Empresas/Index.vue'],
    'usuarios' => ['pages/Administracion/Usuarios/Index.vue'],
    'sucursales' => ['pages/Administracion/Sucursales/Index.vue'],
    'departamentos' => ['pages/Administracion/Departamentos/Index.vue'],
    'puestos' => ['pages/Administracion/Puestos/Index.vue'],
    'roles' => ['pages/Administracion/Roles/Index.vue'],
    'app-releases' => ['pages/Administracion/AppReleases/Index.vue'],
    'portal' => ['pages/Portal/Index.vue'],
    'mi-expediente' => ['pages/Rh/Expedientes/MiExpediente.vue'],
    'pendientes' => ['pages/Rh/Pendientes/Index.vue'],
    'onboarding' => ['pages/Rh/Onboarding/Configuracion.vue'],
    'reingresos' => ['pages/Rh/Reingresos/Index.vue'],
    'configuracion' => ['pages/Administracion/Configuracion/Notificaciones.vue'],
    'mis-solicitudes' => ['pages/Solicitudes/Index.vue'],
    'directorio' => ['pages/Directorio/Index.vue'],
    'directorio-operativo' => ['pages/Directorio/Index.vue'],
    'induccion' => ['pages/Portal/Induccion.vue'],
    'presentar-induccion' => ['pages/Portal/Induccion.vue'],
    'actas' => ['pages/Rh/Actas/Index.vue'],
    'perfiles-puesto' => ['pages/Rh/PerfilesPuesto/Index.vue', 'pages/Rh/PerfilesPuesto/Show.vue', 'pages/Rh/PerfilesPuesto/Editar.vue'],
    'rotacion' => ['pages/Reportes/RotacionPersonal.vue'],
];

test('cada selector de cada módulo existe en SU pantalla (no solo en alguna)', function () {
    $modulos = modulosDeLaGuia();

    expect(array_keys($modulos))->toEqualCanonicalizing(array_keys(PANTALLAS_GUIA));

    foreach ($modulos as $id => $bloque) {
        $contenido = implode("\n", array_map(fn (string $pagina) => contenidoConImportados($pagina), PANTALLAS_GUIA[$id]));

        foreach (selectoresDe($bloque) as $selector) {
            expect(str_contains($contenido, sprintf('data-tour="%s"', $selector)))
                ->toBeTrue("Guía «{$id}»: [data-tour=\"{$selector}\"] no está en su pantalla ni en sus componentes.");
        }
    }
});

test('ningún anclaje de la guía se define en dos componentes distintos de la misma pantalla', function () {
    foreach (PANTALLAS_GUIA as $id => $paginas) {
        $definidoEn = [];

        foreach ($paginas as $pagina) {
            $visitados = [];
            contenidoConImportados($pagina, $visitados);

            foreach (array_keys($visitados) as $archivo) {
                preg_match_all('/data-tour="([a-z0-9-]+)"/', (string) file_get_contents($archivo), $m);

                foreach (array_unique($m[1]) as $selector) {
                    $definidoEn[$selector][$archivo] = true;
                }
            }
        }

        foreach (selectoresDe(modulosDeLaGuia()[$id]) as $selector) {
            // encabezado/busqueda/tabla/indicadores son de componentes
            // compartidos (CrudPageHeader, DataTable…) y se permiten por
            // pantalla una sola vez por componente.
            expect(count($definidoEn[$selector] ?? []))->toBeLessThanOrEqual(
                in_array($selector, ['encabezado', 'busqueda', 'tabla', 'indicadores'], true) ? 2 : 1,
                "Guía «{$id}»: [data-tour=\"{$selector}\"] está definido en varios componentes: ".implode(', ', array_map('basename', array_keys($definidoEn[$selector] ?? []))),
            );
        }
    }
});

test('cada ruta de la guía es una ruta GET real del sistema', function () {
    $uris = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => in_array('GET', $r->methods(), true))
        ->map(fn ($r) => '/'.ltrim($r->uri(), '/'))
        ->all();

    preg_match_all("/ruta: '([^']+)'/", archivoJs('lib/tours/modulos.ts'), $m);

    foreach (array_unique($m[1]) as $ruta) {
        expect(in_array($ruta, $uris, true))->toBeTrue("La guía navega a «{$ruta}», que no es una ruta GET registrada.");
    }
});

test('cada módulo de la guía tiene ayuda escrita completa (qué es, qué puedes hacer, flujo, permisos, errores)', function () {
    $fuente = archivoJs('lib/tours/ayudaEscrita.ts');

    foreach (array_keys(modulosDeLaGuia()) as $id) {
        $clave = preg_match('/^[a-z]+$/', $id) === 1 ? "    {$id}: {" : "    '{$id}': {";
        $inicio = strpos($fuente, $clave);

        expect($inicio)->not->toBeFalse("El módulo «{$id}» no tiene ayuda escrita en ayudaEscrita.ts.");

        $bloque = substr($fuente, (int) $inicio, 4000);

        foreach (['queEs:', 'puedes:', 'flujo:', 'permisos:', 'errores:'] as $campo) {
            expect(str_contains($bloque, $campo))->toBeTrue("Ayuda escrita de «{$id}» sin «{$campo}».");
        }
    }
});
