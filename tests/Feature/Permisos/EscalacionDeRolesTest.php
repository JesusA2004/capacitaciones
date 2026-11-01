<?php

use App\Models\User;
use App\Services\RolPermisoService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->objetivo = clUsuario('colaborador');
});

function erPutRoles(User $objetivo, array $roles): array
{
    return ['username' => $objetivo->username, 'email' => $objetivo->email, 'roles' => $roles];
}

test('gerentes y regionales no pueden dar ningún rol, tampoco super_admin', function (string $rol) {
    $actor = clUsuario($rol, ['sucursal_principal_id' => $this->objetivo->colaborador->sucursal_principal_id]);

    $this->actingAs($actor)
        ->put(route('administracion.usuarios.update', $this->objetivo), erPutRoles($this->objetivo, ['colaborador', 'super_admin']))
        ->assertForbidden();

    expect($this->objetivo->fresh()->hasRole('super_admin'))->toBeFalse();
})->with(['gerente', 'gerente_sucursal', 'gerente_regional']);

test('RH admin asigna roles operativos, pero no superiores a los suyos', function () {
    $rh = clUsuario('rh_admin');

    $this->actingAs($rh)
        ->put(route('administracion.usuarios.update', $this->objetivo), erPutRoles($this->objetivo, ['colaborador', 'gerente_regional']))
        ->assertSessionHasNoErrors();
    expect($this->objetivo->fresh()->hasRole('gerente_regional'))->toBeTrue();

    foreach (['super_admin', 'sistemas', 'direccion'] as $superior) {
        $this->actingAs($rh)
            ->put(route('administracion.usuarios.update', $this->objetivo), erPutRoles($this->objetivo, ['colaborador', $superior]))
            ->assertForbidden();
    }
});

test('tampoco puede quitar un rol superior ni crear una cuenta con él', function () {
    $rh = clUsuario('rh_admin');
    $admin = clUsuario('super_admin');

    $this->actingAs($rh)
        ->put(route('administracion.usuarios.update', $admin), erPutRoles($admin, []))
        ->assertForbidden();
    expect($admin->fresh()->hasRole('super_admin'))->toBeTrue();

    $persona = clUsuario('colaborador')->colaborador;
    $persona->user->delete();
    $persona->unsetRelation('user');

    $this->actingAs($rh)
        ->post(route('administracion.usuarios.store'), ['colaborador_id' => $persona->id, 'roles' => ['super_admin']])
        ->assertForbidden();
});

test('un rol personalizado solo se da si quien asigna ya tiene todos sus permisos', function () {
    $rh = clUsuario('rh_admin');
    $amplio = Role::create(['name' => 'rol_amplio', 'guard_name' => 'web']);
    $amplio->givePermissionTo('roles.administrar');
    $menor = Role::create(['name' => 'rol_menor', 'guard_name' => 'web']);
    $menor->givePermissionTo('usuarios.ver');

    $asignables = app(RolPermisoService::class)->rolesAsignablesPor($rh);

    expect($asignables)->toContain('rol_menor', 'gerente_regional', 'rh_admin')
        ->not->toContain('rol_amplio', 'super_admin', 'sistemas', 'direccion');
});

test('super_admin puede dar cualquier rol y la pantalla solo ofrece los asignables', function () {
    expect(app(RolPermisoService::class)->rolesAsignablesPor(clUsuario('super_admin')))->toContain('super_admin', 'sistemas')
        ->and(app(RolPermisoService::class)->rolesAsignablesPor(clUsuario('gerente_regional')))->toBe([]);

    $this->actingAs(clUsuario('rh_admin'))->get(route('administracion.usuarios.index'))
        ->assertInertia(fn ($page) => $page->where('rolesDisponibles', fn ($roles) => ! collect($roles)->contains('super_admin')));
});

test('el servicio también rechaza la escalación, sin pasar por el formulario', function () {
    expect(fn () => app(RolPermisoService::class)->asignarRoles($this->objetivo, ['super_admin'], clUsuario('rh_admin')))
        ->toThrow(AuthorizationException::class);
});

test('la API móvil no tiene ninguna ruta que cambie roles', function () {
    $rutas = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($ruta) => str_starts_with($ruta->uri(), 'api/') && array_intersect($ruta->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [])
        ->filter(fn ($ruta) => str_contains($ruta->uri(), 'rol') || str_contains((string) $ruta->getActionName(), 'UsuarioController'));

    expect($rutas->map(fn ($ruta) => $ruta->uri())->values()->all())->toBe([]);

    $token = clUsuario('gerente_regional')->createToken('prueba')->plainTextToken;

    $this->withToken($token)->putJson('/api/v1/usuarios/'.$this->objetivo->id, ['roles' => ['super_admin']])->assertNotFound();
    expect($this->objetivo->fresh()->hasRole('super_admin'))->toBeFalse();
});
