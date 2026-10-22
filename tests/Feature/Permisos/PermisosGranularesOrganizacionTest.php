<?php

use App\Models\CausalRotacion;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Organización y Configuración: cada acción tiene su permiso (config/
 * permisos_modulos.php) y el backend lo exige además del acceso. Los roles
 * sembrados conservan lo que podían hacer (herencia del permiso general).
 *
 * Las FormRequests autorizan antes de validar: 403 = sin permiso; una
 * redirección con errores = pasó la autorización.
 */

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

function pgoUsuario(string $rol): User
{
    $usuario = User::factory()->create(['colaborador_id' => Colaborador::factory()->create()->id]);
    $usuario->assignRole($rol);

    return $usuario;
}

/**
 * @param  list<string>  $permisos
 */
function pgoUsuarioConPermisos(string $rol, array $permisos): User
{
    Role::query()->create(['name' => $rol, 'guard_name' => 'web'])->givePermissionTo($permisos);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return pgoUsuario($rol);
}

test('los roles sembrados heredan las acciones nuevas del permiso general', function () {
    $base = RolesYPermisosSeeder::permisosBasePorRol();

    expect($base['rh_admin'])->toContain('sucursales.crear', 'sucursales.editar', 'sucursales.eliminar', 'puestos.editar', 'departamentos.mover_colaboradores', 'organigrama.coberturas', 'organigrama.mover_personas', 'responsables_aprobacion.ver', 'responsables_aprobacion.administrar', 'usuarios.asignar_roles', 'usuarios.restablecer_password', 'configuracion.rh.editar_parametros', 'causales_rotacion.crear', 'documentos_maestros.cargar')
        ->and($base['gerente'])->toContain('responsables_aprobacion.ver')
        ->and($base['gerente'])->not->toContain('responsables_aprobacion.administrar', 'organigrama.coberturas')
        ->and($base['rh_auxiliar'])->not->toContain('usuarios.asignar_roles', 'sucursales.crear');

    expect(Role::findByName('rh_admin')->hasPermissionTo('sucursales.crear'))->toBeTrue();
});

test('sucursales: entrar al catálogo no basta para crear', function () {
    $sinCrear = pgoUsuarioConPermisos('catalogo_sucursales_lectura', ['sucursales.administrar']);
    $conCrear = pgoUsuarioConPermisos('catalogo_sucursales_alta', ['sucursales.administrar', 'sucursales.crear']);

    $this->actingAs($sinCrear)->get(route('administracion.sucursales.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('puede.crear', false));
    $this->actingAs($sinCrear)->post(route('administracion.sucursales.store'), [])->assertForbidden();
    $this->actingAs($conCrear)->post(route('administracion.sucursales.store'), [])->assertSessionHasErrors('nombre');
    $this->actingAs(pgoUsuario('rh_admin'))->post(route('administracion.sucursales.store'), [])->assertSessionHasErrors('nombre');
});

test('departamentos: eliminar y mover personas exigen su propio permiso', function () {
    $departamento = Departamento::factory()->create();
    $usuario = pgoUsuarioConPermisos('catalogo_departamentos', ['departamentos.administrar', 'departamentos.editar', 'expedientes.editar']);

    $this->actingAs($usuario)->delete(route('administracion.departamentos.destroy', $departamento))->assertForbidden();
    $this->actingAs($usuario)->post(route('administracion.departamentos.mover-colaboradores', $departamento), [])->assertForbidden();
    $this->actingAs($usuario)->put(route('administracion.departamentos.update', $departamento), [])->assertSessionHasErrors();
});

test('puestos: editar el catálogo ≠ editar la jerarquía del organigrama', function () {
    $puesto = Puesto::factory()->create();
    $soloOrganigrama = pgoUsuarioConPermisos('editor_organigrama', ['organigrama.ver', 'organigrama.editar']);
    $soloLectura = pgoUsuarioConPermisos('lector_puestos', ['puestos.administrar']);

    $this->actingAs($soloOrganigrama)->put(route('administracion.puestos.update', $puesto), [])->assertForbidden();
    $this->actingAs($soloLectura)->put(route('administracion.puestos.update', $puesto), [])->assertForbidden();
    $this->actingAs($soloLectura)->delete(route('administracion.puestos.destroy', $puesto))->assertForbidden();
    // Editar la jerarquía sigue abierto a quien edita el organigrama.
    $this->actingAs($soloOrganigrama)->put(route('administracion.jerarquia-puestos.actualizar', $puesto), ['nivel_jerarquico' => 'no-es-numero'])->assertStatus(302);
});

test('organigrama: coberturas y matriz comercial tienen permiso propio', function () {
    $usuario = pgoUsuarioConPermisos('organigrama_sin_coberturas', ['organigrama.ver', 'organigrama.editar', 'puestos.administrar']);
    $nodo = NodoComercial::factory()->create();

    $this->actingAs($usuario)->post(route('administracion.jerarquia-puestos.coberturas.store'), [])->assertForbidden();
    $this->actingAs($usuario)->put(route('administracion.matriz-comercial.responsable', $nodo), [])->assertForbidden();
    $this->actingAs($usuario)->get(route('administracion.jerarquia-puestos.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('permisosOrganigrama.cubrir', false)->where('permisosOrganigrama.mover', false)->where('permisosOrganigrama.editarJerarquia', true));

    $this->actingAs(pgoUsuario('rh_admin'))->post(route('administracion.jerarquia-puestos.coberturas.store'), [])->assertSessionHasErrors();
});

test('responsables de aprobación: ver y administrar por separado', function () {
    $sinVer = pgoUsuarioConPermisos('organigrama_solo_arbol', ['organigrama.ver']);
    $soloVer = pgoUsuarioConPermisos('organigrama_responsables_lectura', ['organigrama.ver', 'responsables_aprobacion.ver']);

    $this->actingAs($sinVer)->get(route('administracion.responsables-aprobacion.index'))->assertForbidden();
    $this->actingAs($soloVer)->get(route('administracion.responsables-aprobacion.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('puedeEditar', false));
    $this->actingAs($soloVer)->post(route('administracion.responsables-aprobacion.store'), [])->assertForbidden();
    $this->actingAs(pgoUsuario('gerente'))->get(route('administracion.responsables-aprobacion.index'))->assertOk();
});

test('usuarios: cambiar roles exige «Asignar roles»; editar el correo no', function () {
    Role::findByName('rh_admin')->revokePermissionTo('usuarios.asignar_roles');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $admin = pgoUsuario('rh_admin');
    $objetivo = pgoUsuario('colaborador');

    $this->actingAs($admin)->put(route('administracion.usuarios.update', $objetivo), [
        'username' => $objetivo->username, 'email' => $objetivo->email, 'roles' => ['colaborador', 'rh_admin'],
    ])->assertForbidden();

    $this->actingAs($admin)->put(route('administracion.usuarios.update', $objetivo), [
        'username' => $objetivo->username, 'email' => $objetivo->email, 'roles' => ['colaborador'],
    ])->assertSessionHasNoErrors();

    expect($objetivo->fresh()->getRoleNames()->all())->toBe(['colaborador']);
});

test('usuarios: generar contraseña exige su propio permiso', function () {
    Role::findByName('rh_admin')->revokePermissionTo('usuarios.restablecer_password');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs(pgoUsuario('rh_admin'))
        ->postJson(route('administracion.usuarios.establecer-password', pgoUsuario('colaborador')))
        ->assertForbidden();
});

test('ajustes: ver los parámetros de RH no permite cambiarlos', function () {
    $usuario = pgoUsuarioConPermisos('lector_parametros_rh', ['configuracion.ver', 'configuracion.rh']);

    $this->actingAs($usuario)->get(route('administracion.configuracion.parametros-rh'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('puede.parametros', false)->where('puede.reclutamiento', false));
    $this->actingAs($usuario)->put(route('administracion.configuracion.parametros-rh.update'), ['valores' => []])->assertForbidden();
    $this->actingAs($usuario)->post(route('administracion.configuracion.parametros-rh.motivos-rechazo.store'), [])->assertForbidden();
});

test('catálogo de bajas: administrar causales se divide por acción', function () {
    $usuario = pgoUsuarioConPermisos('causales_lectura', ['finiquitos.ver', 'finiquitos.causales_rotacion']);

    // GuardarCausalRotacionRequest valida antes; la Policy decide en el controlador.
    expect($usuario->can('administrar', CausalRotacion::class))->toBeTrue()
        ->and($usuario->can('create', CausalRotacion::class))->toBeFalse()
        ->and(pgoUsuario('rh_admin')->can('create', CausalRotacion::class))->toBeTrue();
});

test('documentos maestros: entrar no permite cargar ni activar', function () {
    $usuario = pgoUsuarioConPermisos('maestros_lectura', ['plantillas_documentales.administrar']);

    $this->actingAs($usuario)->postJson(route('rh.documentos-maestros.nuevo'), [])->assertForbidden();
    $this->actingAs($usuario)->postJson(route('rh.documentos-maestros.revalidar-pendientes'))->assertForbidden();
});
