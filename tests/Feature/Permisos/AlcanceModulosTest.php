<?php

use App\Enums\AlcanceDatos;
use App\Enums\TipoNodoComercial;
use App\Models\Colaborador;
use App\Models\NodoComercial;
use App\Models\SolicitudInterna;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Permisos\AlcanceModuloService;
use Database\Seeders\RolesYPermisosSeeder;
use Spatie\Permission\Models\Role;

/*
 * Alcance por módulo (config/permisos_modulos.php): primero el permiso de
 * acceso/acción, después QUÉ registros (Propios/Equipo/Sucursal/Región/
 * Todos). Se respeta en el backend (Policies y consultas), no solo
 * ocultando botones. Ver todo ≠ aprobar todo.
 */

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

function amUsuarioConRol(string $rol, array $colaborador = []): User
{
    $usuario = User::factory()->create(['colaborador_id' => Colaborador::factory()->create($colaborador)->id]);
    $usuario->assignRole($rol);

    return $usuario;
}

function amRolPersonalizado(string $nombre, array $permisos): Role
{
    $rol = Role::query()->create(['name' => $nombre, 'guard_name' => 'web']);
    $rol->givePermissionTo($permisos);

    return $rol;
}

function amSolicitudDe(User $usuario): SolicitudInterna
{
    return SolicitudInterna::factory()->create(['user_id' => $usuario->id, 'colaborador_id' => $usuario->colaborador_id]);
}

test('PROPIOS: con solicitudes.ver y alcance propios ve sus solicitudes, no las de otro', function () {
    amRolPersonalizado('consulta_propia', ['solicitudes.ver']);
    $usuario = amUsuarioConRol('consulta_propia');
    $otro = amUsuarioConRol('colaborador');

    expect(app(AlcanceModuloService::class)->nivel($usuario, 'solicitudes'))->toBe(AlcanceDatos::Propios);

    $this->actingAs($usuario)->get(route('rh.solicitudes.show', amSolicitudDe($usuario)))->assertOk();
    $this->actingAs($usuario)->get(route('rh.solicitudes.show', amSolicitudDe($otro)))->assertForbidden();
    $this->actingAs($usuario)->get(route('rh.solicitudes.index'))->assertForbidden();
});

test('TODOS: con solicitudes.ver + solicitudes.ver_todos ve las de todos', function () {
    amRolPersonalizado('consulta_global', ['solicitudes.ver', 'solicitudes.ver_todos']);
    $usuario = amUsuarioConRol('consulta_global');
    $otro = amUsuarioConRol('colaborador');

    expect(app(AlcanceModuloService::class)->nivel($usuario, 'solicitudes'))->toBe(AlcanceDatos::Todos);

    $this->actingAs($usuario)->get(route('rh.solicitudes.show', amSolicitudDe($otro)))->assertOk();
    $this->actingAs($usuario)->get(route('rh.solicitudes.index', ['todas' => 1]))->assertOk();
});

test('VER TODAS ≠ APROBAR: sin solicitudes.aprobar no aprueba aunque las vea todas', function () {
    amRolPersonalizado('consulta_global', ['solicitudes.ver', 'solicitudes.ver_todos']);
    $usuario = amUsuarioConRol('consulta_global');
    $solicitud = amSolicitudDe(amUsuarioConRol('colaborador'));

    $this->actingAs($usuario)->post(route('rh.solicitudes.aprobar', $solicitud))->assertForbidden();
    expect($solicitud->refresh()->estado->value)->toBe('enviada');
});

test('SISTEMAS: dashboard y lectura global 200; acciones RH sensibles 403', function () {
    $sistemas = amUsuarioConRol('sistemas');
    $solicitud = amSolicitudDe(amUsuarioConRol('colaborador'));
    $vacaciones = SolicitudInterna::factory()->create(['tipo' => 'vacaciones', 'user_id' => amUsuarioConRol('colaborador')->id]);

    foreach (['dashboard', 'rh.solicitudes.index', 'rh.expedientes.index', 'rh.candidatos.index', 'rh.vacantes.index', 'rh.actas.index', 'rh.evaluaciones.index', 'administracion.notificaciones-sistema.index',
        'administracion.usuarios.index', 'administracion.roles.index', 'administracion.app-releases.index'] as $ruta) {
        $this->actingAs($sistemas)->get(route($ruta))->assertSuccessful();
    }

    $this->actingAs($sistemas)->get(route('rh.solicitudes.show', $solicitud))->assertOk();

    // Ver todo ≠ decidir: ni aprobar vacaciones ni solicitudes.
    $this->actingAs($sistemas)->post(route('rh.solicitudes.aprobar', $vacaciones))->assertForbidden();
    $this->actingAs($sistemas)->post(route('rh.solicitudes.aprobar', $solicitud))->assertForbidden();

    expect($sistemas->can('vacaciones.aprobar'))->toBeFalse()
        ->and($sistemas->can('candidatos.aprobar'))->toBeFalse()
        ->and($sistemas->can('prestamos.autorizar'))->toBeFalse()
        ->and($sistemas->can('ciclo.autorizar_rh'))->toBeFalse()
        ->and($sistemas->can('cierres.ejecutar_baja'))->toBeFalse()
        ->and($sistemas->can('dashboard.global.ver'))->toBeTrue()
        ->and($sistemas->can('notificaciones.ver_todas'))->toBeTrue();
});

test('RH admin tiene alcance TODOS en los módulos PEOPLE/RH', function () {
    $rh = amUsuarioConRol('rh_admin');
    $servicio = app(AlcanceModuloService::class);

    foreach (['expedientes', 'solicitudes', 'vacaciones', 'prestamos', 'actas', 'documentos', 'contratos', 'evaluaciones', 'candidatos', 'vacantes', 'recibos', 'finiquitos', 'cierres', 'incorporaciones', 'notificaciones'] as $modulo) {
        expect($servicio->nivel($rh, $modulo))->toBe(AlcanceDatos::Todos, "RH sin alcance global en {$modulo}");
    }
});

test('EQUIPO: el jefe directo ve el expediente de su subordinado, no el de otro de la misma sucursal', function () {
    $sucursal = Sucursal::factory()->create();
    $jefe = amUsuarioConRol('jefe_directo', ['sucursal_principal_id' => $sucursal->id]);
    $subordinado = Colaborador::factory()->create(['sucursal_principal_id' => $sucursal->id, 'jefe_id' => $jefe->colaborador_id]);
    $ajeno = Colaborador::factory()->create(['sucursal_principal_id' => $sucursal->id]);

    expect(app(AlcanceModuloService::class)->nivel($jefe, 'expedientes'))->toBe(AlcanceDatos::Equipo);

    $this->actingAs($jefe)->get(route('rh.expedientes.show', $subordinado))->assertOk();
    $this->actingAs($jefe)->get(route('rh.expedientes.show', $ajeno))->assertForbidden();
});

test('SUCURSAL y REGIÓN: el regional ve las sucursales de su región (matriz comercial); el gerente solo la suya', function () {
    [$a, $b, $c] = Sucursal::factory()->count(3)->create();
    $region = NodoComercial::query()->create(['tipo' => TipoNodoComercial::Region->value, 'nombre' => 'Región Centro', 'activa' => true]);
    NodoComercial::query()->create(['tipo' => TipoNodoComercial::Zona->value, 'nombre' => 'Zona A', 'parent_id' => $region->id, 'sucursal_id' => $a->id, 'activa' => true]);
    NodoComercial::query()->create(['tipo' => TipoNodoComercial::Zona->value, 'nombre' => 'Zona B', 'parent_id' => $region->id, 'sucursal_id' => $b->id, 'activa' => true]);

    $regional = amUsuarioConRol('gerente_regional', ['sucursal_principal_id' => $a->id]);
    $gerente = amUsuarioConRol('gerente_sucursal', ['sucursal_principal_id' => $a->id]);
    $servicio = app(AlcanceModuloService::class);

    $enB = Colaborador::factory()->create(['sucursal_principal_id' => $b->id]);
    $enC = Colaborador::factory()->create(['sucursal_principal_id' => $c->id]);

    expect($servicio->nivel($regional, 'expedientes'))->toBe(AlcanceDatos::Region)
        ->and($servicio->alcanzaColaborador($regional, $enB, 'expedientes'))->toBeTrue()
        ->and($servicio->alcanzaColaborador($regional, $enC, 'expedientes'))->toBeFalse()
        ->and($servicio->nivel($gerente, 'expedientes'))->toBe(AlcanceDatos::Sucursal)
        ->and($servicio->alcanzaColaborador($gerente, $enB, 'expedientes'))->toBeFalse();
});

test('el sincronizador solo AGREGA permisos base y conserva personalizaciones', function () {
    $rol = Role::findByName('gerente');
    $rol->givePermissionTo('reportes.globales');
    $rol->revokePermissionTo('vacantes.crear');

    $this->artisan('people:sincronizar-permisos')->assertExitCode(0);

    $rol->refresh();
    expect($rol->hasPermissionTo('reportes.globales'))->toBeTrue()
        ->and($rol->hasPermissionTo('vacantes.crear'))->toBeTrue()
        ->and(Role::findByName('sistemas')->hasPermissionTo('dashboard.global.ver'))->toBeTrue()
        ->and(Role::findByName('super_admin')->hasPermissionTo('notificaciones.ver_todas'))->toBeTrue();
});
