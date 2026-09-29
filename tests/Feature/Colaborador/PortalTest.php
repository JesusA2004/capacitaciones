<?php

use App\Models\Colaborador;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('un colaborador autenticado puede ver su portal', function () {
    $colaborador = User::factory()->for(Colaborador::factory()->state(['fecha_ingreso' => now()->subYears(2)]))->create();
    $colaborador->assignRole('colaborador');

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
    $colaborador->assignRole('colaborador');

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

test('una cuenta operativa sin colaborador enlazado no puede ver el portal ni por URL directa', function () {
    $superAdminPuro = User::factory()->create(['colaborador_id' => null]);
    $superAdminPuro->assignRole('super_admin');

    $rhAdminPuro = User::factory()->create(['colaborador_id' => null]);
    $rhAdminPuro->assignRole('rh_admin');

    // (EnsureCuentaActiva además cierra la sesión de una cuenta sin
    // colaborador; aquí solo importa que nunca obtenga la página.)
    foreach ([$superAdminPuro, $rhAdminPuro] as $usuarioOperativo) {
        expect($usuarioOperativo->can('modo-colaborador'))->toBeFalse();

        foreach (['portal.index', 'portal.perfil', 'portal.notificaciones', 'mi-expediente'] as $ruta) {
            expect($this->actingAs($usuarioOperativo)->get(route($ruta))->isOk())->toBeFalse();
        }
    }
});

test('un usuario con ambos roles (operativo + colaborador) sí puede ver el portal', function () {
    $mixto = User::factory()->create();
    $mixto->assignRole(['rh_admin', 'colaborador']);

    $this->actingAs($mixto)->get(route('portal.index'))->assertOk();
    $this->actingAs($mixto)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('navegacion.modosDisponibles', ['operativo', 'colaborador'])
        );
});
