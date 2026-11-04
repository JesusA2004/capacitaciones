<?php

use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('un colaborador que abre la vista de RH de su propio expediente va a Mi expediente', function () {
    $colaborador = clUsuario('colaborador');

    $this->actingAs($colaborador)
        ->get(route('rh.expedientes.show', $colaborador->colaborador_id))
        ->assertRedirect(route('mi-expediente'));
});

test('quien explora expedientes sigue viendo la vista de RH, aunque sea el suyo', function () {
    $rh = clUsuario('rh_admin');

    $this->actingAs($rh)
        ->get(route('rh.expedientes.show', $rh->colaborador_id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Rh/Expedientes/Show'));
});

test('el header Link de preload queda acotado (sin riesgo de 502 en Nginx)', function () {
    $rh = clUsuario('rh_admin');

    $respuesta = $this->actingAs($rh)->get(route('rh.expedientes.show', $rh->colaborador_id))->assertOk();

    $link = (string) $respuesta->headers->get('Link');

    expect(strlen($link))->toBeLessThan(4096)
        ->and($link === '' ? 0 : count(explode(', <', $link)))->toBeLessThanOrEqual(12);
});
