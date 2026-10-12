<?php

use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/*
 * Auditoría de la Guía maestra (resources/js/lib/guia + recorridos por
 * proceso) contra el sistema REAL: ningún botón «Ir a…» lleva a una ruta que
 * no existe, ningún recorrido resalta un elemento que no está en pantalla y
 * cada página de la guía responde. La integridad interna del contenido
 * (referencias entre artículos, buscador, docs sincronizados) la prueba
 * tests/js/guia.test.mjs.
 */

function fuenteGuia(string $ruta): string
{
    return (string) file_get_contents(resource_path('js/'.$ruta));
}

/**
 * @return list<string> URIs GET registradas, con «/» inicial.
 */
function urisGet(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => in_array('GET', $r->methods(), true))
        ->map(fn ($r) => '/'.ltrim($r->uri(), '/'))
        ->values()
        ->all();
}

test('cada botón «Ir a…» de la guía abre una ruta GET real del sistema', function () {
    $uris = urisGet();
    $archivos = File::glob(resource_path('js/lib/guia/articulos/*.ts'));

    expect($archivos)->not->toBeEmpty();

    foreach ($archivos as $archivo) {
        preg_match_all("/ruta: '([^']+)'/", (string) file_get_contents($archivo), $m);

        foreach (array_unique($m[1]) as $ruta) {
            expect(in_array($ruta, $uris, true))->toBeTrue(sprintf('%s: el acceso «%s» no es una ruta GET registrada.', basename($archivo), $ruta));
        }
    }
});

test('cada recorrido por proceso navega a rutas reales y resalta anclajes que existen', function () {
    $fuente = fuenteGuia('lib/tours/recorridos.ts');
    $uris = urisGet();

    preg_match_all("/ruta: '([^']+)'/", $fuente, $rutas);
    expect($rutas[1])->not->toBeEmpty();

    foreach (array_unique($rutas[1]) as $ruta) {
        expect(in_array($ruta, $uris, true))->toBeTrue("Un recorrido navega a «{$ruta}», que no es una ruta GET registrada.");
    }

    $vues = collect(File::allFiles(resource_path('js')))
        ->filter(fn ($f) => $f->getExtension() === 'vue')
        ->map(fn ($f) => $f->getContents())
        ->implode("\n");

    preg_match_all("/sel\\('([a-z0-9-]+)'\\)/", $fuente, $selectores);

    foreach (array_unique($selectores[1]) as $selector) {
        expect(str_contains($vues, sprintf('data-tour="%s"', $selector)))
            ->toBeTrue("Recorridos: [data-tour=\"{$selector}\"] no existe en ninguna pantalla.");
    }

    // Las tarjetas del tablero de candidatos declaran su destino para el
    // recorrido «Contratar un candidato» (rutaDesde con data-tour-href).
    expect(fuenteGuia('pages/Rh/Candidatos/Index.vue'))->toContain(':data-tour-href=');
});

test('las páginas de la guía responden a un usuario de RH', function () {
    $this->seed(RolesYPermisosSeeder::class);
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $paginas = [
        '/ayuda' => 'Ayuda/Index',
        '/ayuda/proceso/reclutamiento' => 'Ayuda/Proceso',
        '/ayuda/articulo/iniciar-contratacion' => 'Ayuda/Articulo',
        '/ayuda/rol/gerente' => 'Ayuda/Rol',
        '/ayuda/video' => 'Ayuda/Video',
        '/ayuda/glosario' => 'Ayuda/Glosario',
        '/ayuda/preguntas' => 'Ayuda/Preguntas',
    ];

    foreach ($paginas as $url => $componente) {
        $this->actingAs($usuario)
            ->get($url)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component($componente));
    }

    $this->actingAs($usuario)
        ->get('/ayuda/articulo/iniciar-contratacion')
        ->assertInertia(fn ($page) => $page->where('articulo', 'iniciar-contratacion')->has('capturas'));
});

test('la guía exige sesión iniciada', function () {
    $this->get('/ayuda')->assertRedirect(route('login'));
    $this->get('/ayuda/articulo/permisos')->assertRedirect(route('login'));
});

test('los identificadores de la guía solo aceptan slugs (sin rutas arbitrarias)', function () {
    $this->seed(RolesYPermisosSeeder::class);
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');

    $this->actingAs($usuario)->get('/ayuda/articulo/..%2Fetc')->assertNotFound();
    $this->actingAs($usuario)->get('/ayuda/rol/RH;drop')->assertNotFound();
});

test('el expediente ofrece el acceso al Ciclo laboral (donde se solicita la baja con finiquito)', function () {
    expect(fuenteGuia('components/Rh/ExpedienteDetalle.vue'))
        ->toContain('data-tour="expediente-ciclo"')
        ->toContain('Ciclo laboral');

    expect((string) file_get_contents(app_path('Http/Controllers/Rh/ExpedienteController.php')))
        ->toContain("'cicloUrl'");
});
