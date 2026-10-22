<?php

use App\Models\User;
use App\Services\Permisos\AlcanceModuloService;
use Database\Seeders\RolesYPermisosSeeder;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * «Roles y permisos» como matriz por etapa → módulo → acceso / alcance /
 * acciones (config/permisos_modulos.php) y el guardado validado contra el
 * catálogo.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

function adminDeRoles(string $rol = 'super_admin'): User
{
    $usuario = User::factory()->create();
    $usuario->assignRole($rol);

    return $usuario;
}

test('la pantalla presenta cada módulo y cada acción del catálogo agrupados por etapa', function () {
    $this->actingAs(adminDeRoles())
        ->get(route('administracion.roles.index'))
        ->assertOk()
        ->assertInertia(function ($page) {
            $page->component('Administracion/Roles/Index');
            $catalogo = $page->toArray()['props']['catalogo'];

            $modulos = collect($catalogo['etapas'])->flatMap(fn ($e) => $e['modulos'])->keyBy('clave');

            foreach (app(AlcanceModuloService::class)->modulos() as $clave => $modulo) {
                expect($modulos->has($clave))->toBeTrue("Falta el módulo {$clave}");

                $acciones = collect($modulos[$clave]['acciones'])->pluck('permiso')->all();
                expect($acciones)->toEqualCanonicalizing(array_keys($modulo['acciones']));
            }

            // Mismo orden de etapas que el sidebar.
            $orden = collect($catalogo['etapas'])->pluck('clave')->all();
            $esperado = array_values(array_intersect(array_keys(config('permisos_modulos.etapas')), $orden));
            expect(array_values(array_diff($orden, ['otros'])))->toBe($esperado);
        });
});

test('ningún permiso queda oculto: lo que no tiene módulo sale en «Otros permisos»', function () {
    Permission::query()->create(['name' => 'legado.permiso_suelto', 'guard_name' => 'web']);

    $this->actingAs(adminDeRoles())
        ->get(route('administracion.roles.index'))
        ->assertOk()
        ->assertInertia(function ($page) {
            $catalogo = $page->toArray()['props']['catalogo'];

            $presentados = collect($catalogo['etapas'])
                ->flatMap(fn ($e) => $e['modulos'])
                ->flatMap(fn ($m) => [
                    ...($m['acceso'] ? [$m['acceso']['permiso']] : []),
                    ...array_values($m['alcances']),
                    ...collect($m['acciones'])->pluck('permiso')->all(),
                ])
                ->merge(collect($catalogo['otros'])->flatten())
                ->unique();

            foreach (Permission::query()->pluck('name') as $permiso) {
                expect($presentados->contains($permiso))->toBeTrue("El permiso {$permiso} no aparece en la pantalla");
            }

            expect($catalogo['otros']['legado'] ?? [])->toContain('legado.permiso_suelto');
        });
});

test('guardar valida contra el catálogo: un permiso inventado se rechaza', function () {
    $rol = Role::query()->create(['name' => 'rol_de_prueba', 'guard_name' => 'web']);
    Permission::query()->create(['name' => 'fuera.de_catalogo', 'guard_name' => 'web']);

    $this->actingAs(adminDeRoles())
        ->put(route('administracion.roles.update', $rol), [
            'nombre' => 'rol_de_prueba',
            'permisos' => ['vacantes.ver', 'no.existe'],
        ])
        ->assertSessionHasErrors('permisos.1');

    $this->actingAs(adminDeRoles())
        ->put(route('administracion.roles.update', $rol), [
            'nombre' => 'rol_de_prueba',
            'permisos' => ['fuera.de_catalogo'],
        ])
        ->assertSessionHasErrors('permisos.0');

    expect($rol->fresh()->permissions)->toHaveCount(0);
});

test('un permiso legado que el rol ya tenía se puede conservar al guardar', function () {
    Permission::query()->create(['name' => 'fuera.de_catalogo', 'guard_name' => 'web']);
    $rol = Role::query()->create(['name' => 'rol_legado', 'guard_name' => 'web']);
    $rol->givePermissionTo('fuera.de_catalogo');

    $this->actingAs(adminDeRoles())
        ->put(route('administracion.roles.update', $rol), [
            'nombre' => 'rol_legado',
            'permisos' => ['fuera.de_catalogo', 'vacantes.ver'],
        ])
        ->assertSessionHasNoErrors();

    expect($rol->fresh()->permissions->pluck('name')->all())->toEqualCanonicalizing(['fuera.de_catalogo', 'vacantes.ver']);
});

test('el ejemplo del negocio: un gerente que solo aprueba entrevista y no ve contratos', function () {
    $gerente = Role::findByName('gerente');
    $permisos = $gerente->permissions->pluck('name')
        ->reject(fn (string $p): bool => str_starts_with($p, 'contratos.') || str_starts_with($p, 'documentos_laborales.'))
        ->values()->all();

    $this->actingAs(adminDeRoles())
        ->put(route('administracion.roles.update', $gerente), ['nombre' => 'gerente', 'permisos' => $permisos])
        ->assertSessionHasNoErrors();

    $gerente->refresh();
    expect($gerente->hasPermissionTo('contratos.ver'))->toBeFalse()
        ->and($gerente->hasPermissionTo('candidatos.evaluar'))->toBeTrue();

    // Un deploy (sincronizar) no le devuelve lo que se le quitó.
    $this->artisan('people:sincronizar-permisos')->assertSuccessful();
    expect(Role::findByName('gerente')->hasPermissionTo('contratos.ver'))->toBeFalse();
});

test('cada cambio de permisos queda en la bitácora con lo agregado y lo quitado', function () {
    $rol = Role::query()->create(['name' => 'rol_auditado', 'guard_name' => 'web']);
    $rol->givePermissionTo('vacantes.ver');
    $admin = adminDeRoles();

    $this->actingAs($admin)
        ->put(route('administracion.roles.update', $rol), ['nombre' => 'rol_auditado', 'permisos' => ['candidatos.ver']])
        ->assertSessionHasNoErrors();

    $registro = Activity::query()->where('event', 'rol_permisos_actualizados')->latest('id')->first();

    expect($registro)->not->toBeNull()
        ->and($registro->causer_id)->toBe($admin->id)
        ->and($registro->properties['agregados'])->toBe(['candidatos.ver'])
        ->and($registro->properties['quitados'])->toBe(['vacantes.ver']);
});

test('super_admin está bloqueado como acceso total: no se edita', function () {
    $this->actingAs(adminDeRoles())
        ->put(route('administracion.roles.update', Role::findByName('super_admin')), ['nombre' => 'super_admin', 'permisos' => []])
        ->assertForbidden();

    expect(Role::findByName('super_admin')->permissions()->count())->toBeGreaterThan(0);
});

test('los roles del sistema no se renombran ni se eliminan; los personalizados sí', function () {
    $this->actingAs(adminDeRoles())
        ->put(route('administracion.roles.update', Role::findByName('gerente')), ['nombre' => 'gerente_renombrado', 'permisos' => []])
        ->assertSessionHasErrors('nombre');

    $this->actingAs(adminDeRoles())
        ->delete(route('administracion.roles.destroy', Role::findByName('gerente')))
        ->assertForbidden();

    $propio = Role::query()->create(['name' => 'rol_temporal', 'guard_name' => 'web']);

    $this->actingAs(adminDeRoles())
        ->delete(route('administracion.roles.destroy', $propio))
        ->assertRedirect();

    expect(Role::query()->where('name', 'rol_temporal')->exists())->toBeFalse()
        ->and(Role::query()->where('name', 'gerente')->exists())->toBeTrue();
});

test('sin roles.administrar no se ve ni se guarda la pantalla (403)', function () {
    $gerente = adminDeRoles('gerente');
    $rol = Role::query()->create(['name' => 'rol_ajeno', 'guard_name' => 'web']);

    $this->actingAs($gerente)->get(route('administracion.roles.index'))->assertForbidden();
    $this->actingAs($gerente)
        ->put(route('administracion.roles.update', $rol), ['nombre' => 'rol_ajeno', 'permisos' => ['vacantes.ver']])
        ->assertForbidden();
    $this->actingAs($gerente)
        ->post(route('administracion.roles.store'), ['nombre' => 'otro', 'permisos' => []])
        ->assertForbidden();

    expect($rol->fresh()->permissions)->toHaveCount(0);
});

test('Sistemas (roles.administrar) también administra roles', function () {
    $this->actingAs(adminDeRoles('sistemas'))
        ->get(route('administracion.roles.index'))
        ->assertOk();
});
