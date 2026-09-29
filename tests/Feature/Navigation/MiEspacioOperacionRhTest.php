<?php

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\Navigation\NavigationService;
use Database\Seeders\RolesYPermisosSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Regresión de producción: el selector [Mi espacio] [Operación RH]
 * desapareció para cuentas administrativas que además son colaboradores,
 * porque el modo personal dependía solo de `portal.ver` y super_admin ya
 * no lo tiene. La capacidad ahora es "cuenta enlazada a un Colaborador
 * activo" (NavigationService::puedeUsarModoColaborador()).
 */

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

function modosDe(User $usuario): array
{
    return app(NavigationService::class)->modosDisponibles($usuario->fresh());
}

test('1) solo colaborador: solo Mi espacio', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');

    expect(modosDe($usuario))->toBe(['colaborador']);
    $this->actingAs($usuario)->get(route('portal.index'))->assertOk();
    $this->actingAs($usuario)->post(route('modo-navegacion.update'), ['modo' => 'operativo'])->assertForbidden();
});

test('2) administrativo sin colaborador: solo Operación RH', function () {
    $usuario = User::factory()->create(['colaborador_id' => null]);
    $usuario->assignRole('rh_admin');

    // Sin colaborador no hay sesión web posible (EnsureCuentaActiva); la
    // capacidad se verifica en el Gate central y la página nunca se sirve.
    expect(modosDe($usuario))->toBe(['operativo'])
        ->and($usuario->can('modo-colaborador'))->toBeFalse()
        ->and($this->actingAs($usuario)->get(route('portal.index'))->isOk())->toBeFalse()
        ->and($this->actingAs($usuario)->get(route('mi-expediente'))->isOk())->toBeFalse();
});

test('3) administrativo con colaborador activo: ambos modos, sin rol colaborador', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    expect($usuario->hasRole('colaborador'))->toBeFalse()
        ->and(modosDe($usuario))->toBe(['operativo', 'colaborador']);

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('navegacion.modosDisponibles', ['operativo', 'colaborador']));
    $this->actingAs($usuario)->get(route('portal.index'))->assertOk();
    $this->actingAs($usuario)->get(route('portal.perfil'))->assertOk();
    $this->actingAs($usuario)->get(route('mi-expediente'))->assertOk();
});

test('4) super_admin con colaborador activo: ambos modos', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('super_admin');

    expect(modosDe($usuario))->toBe(['operativo', 'colaborador']);
    $this->actingAs($usuario)->get(route('portal.index'))->assertOk();
});

test('5) super_admin sin colaborador: solo operativo', function () {
    $usuario = User::factory()->create(['colaborador_id' => null]);
    $usuario->assignRole('super_admin');

    expect(modosDe($usuario))->toBe(['operativo'])
        ->and($usuario->can('modo-colaborador'))->toBeFalse();
});

test('5b) colaborador inactivo o con acceso bloqueado pierde Mi espacio', function () {
    $baja = User::factory()->create();
    $baja->assignRole('rh_admin');
    $baja->colaborador->update(['estatus' => EstadoUsuario::Inactivo]);

    $bloqueado = User::factory()->create(['acceso_bloqueado_en' => now()]);
    $bloqueado->assignRole('rh_admin');

    expect(modosDe($baja))->toBe(['operativo'])
        ->and(modosDe($bloqueado))->toBe(['operativo']);
});

test('6) cambiar de modo persiste en cookie y el Inicio respeta el modo elegido', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('super_admin');

    $respuesta = $this->actingAs($usuario)->post(route('modo-navegacion.update'), ['modo' => 'colaborador']);
    $respuesta->assertRedirect(route('portal.index'))->assertPlainCookie(NavigationService::nombreCookie(), 'colaborador');

    $this->actingAs($usuario)
        ->withUnencryptedCookie(NavigationService::nombreCookie(), 'colaborador')
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Colaborador')
            ->where('navegacion.modoActual', 'colaborador'));

    $this->actingAs($usuario)
        ->withUnencryptedCookie(NavigationService::nombreCookie(), 'operativo')
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Global')
            ->where('navegacion.modoActual', 'operativo'));
});

test('7) Mi espacio de un administrador jamás abre los datos de otro colaborador', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $otro = Colaborador::factory()->create(['name' => 'Otra', 'apellidos' => 'Persona']);

    // Las rutas personales no aceptan un id: aunque se mande uno, se ignora.
    $this->actingAs($admin)
        ->get(route('mi-expediente', ['colaborador' => $otro->id, 'colaborador_id' => $otro->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('colaborador.id', $admin->colaborador_id));

    $this->actingAs($admin)
        ->get(route('portal.perfil', ['colaborador_id' => $otro->id]))
        ->assertOk()
        ->assertDontSee('Otra Persona');
});
