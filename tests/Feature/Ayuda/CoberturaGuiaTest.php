<?php

use Illuminate\Support\Facades\File;

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
    'Inicio' => ['dashboard'],
    'Expedientes' => ['expedientes'],
    'Solicitudes' => ['solicitudes'],
    'Organigrama' => ['organigrama'],
    'Vacantes' => ['vacantes'],
    'Candidatos' => ['candidatos'],
    'Campañas' => ['campanas'],
    'Invitaciones QR' => ['invitaciones'],
    'Formatos' => ['formatos'],
    'Reportes' => ['reportes'],
    'Celebraciones' => ['cumpleanos', 'aniversarios'],
    'Empresas' => ['empresas'],
    'Usuarios' => ['usuarios'],
    'Sucursales' => ['sucursales'],
    'Departamentos' => ['departamentos'],
    'Puestos' => ['puestos'],
    'Roles y permisos' => ['roles'],
    'Versiones de app' => ['app-releases'],
    'Mi portal' => ['portal'],
    'Mis solicitudes' => ['mis-solicitudes'],
];

const SIDEBAR_SIN_GUIA = [
    // Es la propia página de la guía (buscador + guía escrita + recorridos).
    'Ayuda',
    // Oculto tras el feature flag `capacitacion` (docs/CAPACITACION_PROXIMAMENTE.md).
    'Capacitación',
];

test('todo acceso del sidebar tiene módulo de guía o una justificación explícita', function () {
    preg_match_all("/title: '([^']+)'/", archivoJs('components/AppSidebar.vue'), $m);
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

test('la guía de Formatos cubre sus cinco pestañas y cada anclaje existe en su pantalla', function () {
    $bloque = modulosDeLaGuia()['formatos'];
    $pantallas = implode("\n", [
        contenidoConImportados('pages/Rh/FormatosOficiales/Index.vue'),
        contenidoConImportados('pages/Rh/FormatosOficiales/Generados.vue'),
        contenidoConImportados('pages/Rh/FormatosOficiales/Variables.vue'),
        contenidoConImportados('pages/Rh/Plantillas/Index.vue'),
        contenidoConImportados('pages/Rh/Formatos/Index.vue'),
    ]);

    foreach (['/rh/formatos-oficiales/generados', '/rh/formatos-oficiales/variables', '/rh/plantillas', '/rh/formatos/catalogo'] as $ruta) {
        expect($bloque)->toContain("ruta: '{$ruta}'");
    }

    foreach (['marcadores', 'Variables manuales', 'Obligatorio', 'Vista previa', 'Descargar Word o PDF', 'Nueva versión', 'Histórico'] as $tema) {
        expect($bloque)->toContain($tema);
    }

    foreach (selectoresDe($bloque) as $selector) {
        expect(str_contains($pantallas, sprintf('data-tour="%s"', $selector)))
            ->toBeTrue("Guía «formatos»: [data-tour=\"{$selector}\"] no está en las pantallas de Formatos.");
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
