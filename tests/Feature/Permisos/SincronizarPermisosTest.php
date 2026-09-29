<?php

use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

const PERMISOS_CELEBRACIONES = ['celebraciones.ver', 'celebraciones.gestionar', 'celebraciones.enviar', 'celebraciones.moderar'];

/**
 * Simula producción: roles ya sembrados con una versión vieja del catálogo
 * (sin celebraciones.*) y un rol personalizado a mano desde la pantalla
 * de roles.
 */
function produccionDesactualizada(): void
{
    test()->seed(RolesYPermisosSeeder::class);

    Permission::query()->whereIn('name', PERMISOS_CELEBRACIONES)->delete();
    Permission::query()->create(['name' => 'permiso.personalizado', 'guard_name' => 'web']);
    Role::findByName('super_admin')->givePermissionTo('permiso.personalizado');
    Role::findByName('rh_auxiliar')->givePermissionTo('permiso.personalizado');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

test('crea los permisos nuevos y se los otorga a super_admin y rh_admin', function () {
    produccionDesactualizada();

    $this->artisan('people:sincronizar-permisos')->assertSuccessful();

    foreach (PERMISOS_CELEBRACIONES as $permiso) {
        expect(Permission::query()->where('name', $permiso)->exists())->toBeTrue()
            ->and(Role::findByName('super_admin')->hasPermissionTo($permiso))->toBeTrue()
            ->and(Role::findByName('rh_admin')->hasPermissionTo($permiso))->toBeTrue();
    }
});

test('nunca quita permisos personalizados de un rol', function () {
    produccionDesactualizada();

    $this->artisan('people:sincronizar-permisos')->assertSuccessful();

    expect(Role::findByName('super_admin')->hasPermissionTo('permiso.personalizado'))->toBeTrue()
        ->and(Role::findByName('rh_auxiliar')->hasPermissionTo('permiso.personalizado'))->toBeTrue();
});

test('es idempotente: la segunda corrida no cambia nada', function () {
    produccionDesactualizada();

    $this->artisan('people:sincronizar-permisos')->assertSuccessful();
    $antes = Role::findByName('super_admin')->permissions()->count();

    $this->artisan('people:sincronizar-permisos')
        ->expectsOutput('Permisos nuevos: 0')
        ->expectsOutput('Roles con permisos base agregados: 0')
        ->assertSuccessful();

    expect(Role::findByName('super_admin')->permissions()->count())->toBe($antes);
});

test('--simular no escribe nada', function () {
    produccionDesactualizada();

    $this->artisan('people:sincronizar-permisos', ['--simular' => true])->assertSuccessful();

    expect(Permission::query()->where('name', 'celebraciones.ver')->exists())->toBeFalse();
});

test('re-sembrar RolesYPermisosSeeder tampoco borra personalizaciones (db:seed --force en deploy)', function () {
    produccionDesactualizada();

    $this->seed(RolesYPermisosSeeder::class);

    expect(Role::findByName('super_admin')->hasPermissionTo('permiso.personalizado'))->toBeTrue()
        ->and(Role::findByName('super_admin')->hasPermissionTo('celebraciones.ver'))->toBeTrue();
});

test('tras sincronizar, un super_admin existente ve Aniversarios (200) y recibe el permiso en props', function () {
    produccionDesactualizada();
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)->get(route('rh.aniversarios.index'))->assertForbidden();

    $this->artisan('people:sincronizar-permisos')->assertSuccessful();

    $this->actingAs($admin->fresh())->get(route('rh.aniversarios.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Rh/Aniversarios/Index')
            ->where('auth.user.permissions', fn ($permisos) => collect($permisos)->contains('celebraciones.ver')
                && collect($permisos)->contains('rh.cumpleanos.ver')));

    $this->actingAs($admin->fresh())->get(route('rh.cumpleanos.index'))->assertOk();
});
