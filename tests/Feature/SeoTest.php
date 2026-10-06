<?php

use Database\Seeders\RolesYPermisosSeeder;

/*
 * SEO del sitio público: solo login y descarga de la app se indexan.
 */
test('robots.txt apunta al sitemap y bloquea las áreas privadas', function () {
    $this->get('/robots.txt')->assertOk()
        ->assertSee('Sitemap: '.url('sitemap.xml'), false)
        ->assertSee('Disallow: /rh/', false)
        ->assertSee('Disallow: /api/', false);
});

test('sitemap.xml lista solo páginas públicas', function () {
    $respuesta = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

    expect($respuesta->getContent())->toContain(route('login'))->toContain(route('app.index'))->not->toContain('/rh/');
});

test('el login se indexa con descripción y las pantallas con sesión mandan noindex', function () {
    $this->get(route('login'))->assertOk()
        ->assertSee('<meta name="robots" content="index, follow">', false)
        ->assertSee('MR. LANA PEOPLE: portal de Recursos Humanos', false)
        ->assertSee('application/ld+json', false);

    $this->seed(RolesYPermisosSeeder::class);
    $this->actingAs(clUsuario('rh_admin'))->get(route('dashboard'))->assertOk()
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
});
