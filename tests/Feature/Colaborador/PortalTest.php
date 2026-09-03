<?php

use App\Models\User;

test('un colaborador autenticado puede ver su portal', function () {
    $colaborador = User::factory()->create(['fecha_ingreso' => now()->subYears(2)]);

    $respuesta = $this->actingAs($colaborador)->get(route('portal.index'));

    $respuesta->assertOk();
    $respuesta->assertInertia(fn ($page) => $page
        ->component('Portal/Index')
        ->where('perfil.nombre_completo', $colaborador->nombreCompleto())
        ->where('vacaciones.antiguedad_anios', 2)
        ->has('solicitudes_recientes')
        ->has('notificaciones.no_leidas')
    );
});

test('un colaborador autenticado puede ver su perfil básico', function () {
    $colaborador = User::factory()->create();

    $this->actingAs($colaborador)
        ->get(route('portal.perfil'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Portal/Perfil')
            ->where('perfil.correo', $colaborador->email)
        );
});

test('un usuario no autenticado no puede acceder al portal', function () {
    $this->get(route('portal.index'))->assertRedirect(route('login'));
});
