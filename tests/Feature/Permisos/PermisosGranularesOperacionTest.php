<?php

use App\Enums\EstadoEvaluacionPrueba;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Permisos por acción de Inicio, Reclutamiento, Onboarding, Periodo de
 * prueba y Reportes (config/permisos_modulos.php → herencia): un rol con
 * el ACCESO del módulo pero sin la acción recibe 403; los roles base
 * conservan lo que ya podían hacer.
 */

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

/**
 * @param  list<string>  $permisos
 */
function usuarioConPermisos(array $permisos): User
{
    $rol = Role::query()->create(['name' => 'rol_prueba_'.uniqid(), 'guard_name' => 'web']);
    $rol->givePermissionTo($permisos);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $usuario = User::factory()->create();
    $usuario->assignRole($rol);

    return $usuario;
}

function usuarioDeRol(string $rol): User
{
    $usuario = User::factory()->create();
    $usuario->assignRole($rol);

    return $usuario;
}

test('los permisos nuevos heredan de su permiso general en la base de cada rol', function () {
    $base = RolesYPermisosSeeder::permisosBasePorRol();

    expect($base['rh_admin'])->toContain('reclutamiento.campanas.crear', 'reclutamiento.campanas.eliminar', 'onboarding.calificar', 'onboarding.configurar', 'evaluaciones.asignar', 'evaluaciones.devolver')
        ->and($base['rh_auxiliar'])->toContain('reclutamiento.campanas.exportar', 'vacantes.exportar', 'perfiles_puesto.descargar', 'rotacion.descargar')
        ->and($base['rh_auxiliar'])->not->toContain('reclutamiento.campanas.crear')
        ->and($base['gerente'])->toContain('evaluaciones.descargar', 'vacantes.exportar')
        ->and($base['gerente'])->not->toContain('evaluaciones.asignar', 'onboarding.calificar')
        ->and(RolesYPermisosSeeder::catalogoPermisos())->toContain('onboarding.presentar_induccion', 'onboarding.induccion_puesto', 'onboarding.descargar_pdf');
});

test('vacantes: exportar exige vacantes.exportar', function () {
    $this->actingAs(usuarioConPermisos(['vacantes.ver', 'vacantes.ver_todos']))
        ->get(route('rh.vacantes.exportarExcel'))->assertForbidden();

    $this->actingAs(usuarioDeRol('rh_admin'))
        ->get(route('rh.vacantes.exportarExcel'))->assertOk();
});

test('campañas: exportar costos exige reclutamiento.campanas.exportar y crear exige crear', function () {
    $usuario = usuarioConPermisos(['reclutamiento.campanas.ver', 'reclutamiento.campanas.administrar']);

    $this->actingAs($usuario)->get(route('rh.campanas.costos.exportar'))->assertForbidden();
    $this->actingAs($usuario)->post(route('rh.campanas.store'), [])->assertForbidden();
    $this->actingAs($usuario)->get(route('rh.campanas.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('permisos.crear', false)->where('permisos.exportar', false));

    $this->actingAs(usuarioDeRol('rh_admin'))->get(route('rh.campanas.costos.exportar'))->assertOk();
    $this->actingAs(usuarioDeRol('rh_admin'))->get(route('rh.campanas.index'))
        ->assertInertia(fn ($page) => $page->where('permisos.crear', true)->where('permisos.eliminar', true));
});

test('rotación: el formato exige rotacion.descargar', function () {
    $this->actingAs(usuarioConPermisos(['rotacion.ver', 'rotacion.ver_todos']))
        ->get(route('reportes.rotacion.formato'))->assertForbidden();

    $this->actingAs(usuarioDeRol('rh_admin'))
        ->get(route('reportes.rotacion.formato'))->assertOk();
});

test('onboarding: configuración, inducción al puesto y calificaciones exigen su acción', function () {
    $usuario = usuarioConPermisos(['onboarding.ver', 'onboarding.ver_todos', 'onboarding.gestionar']);

    $this->actingAs($usuario)->get(route('rh.onboarding.configuracion'))->assertForbidden();
    $this->actingAs($usuario)->get(route('rh.induccion-puesto.index'))->assertForbidden();
    $this->actingAs($usuario)->get(route('rh.onboarding.calificaciones'))->assertForbidden();
    $this->actingAs($usuario)->get(route('induccion.presentacion'))->assertForbidden();

    $rh = usuarioDeRol('rh_admin');
    $this->actingAs($rh)->get(route('rh.onboarding.configuracion'))->assertOk();
    $this->actingAs($rh)->get(route('rh.induccion-puesto.index'))->assertOk();
    $this->actingAs($rh)->get(route('rh.onboarding.calificaciones'))->assertOk();
});

test('evaluaciones: asignar exige evaluaciones.asignar además de autorizar', function () {
    $this->actingAs(usuarioConPermisos(['evaluaciones.ver', 'evaluaciones.ver_todos', 'evaluaciones.autorizar']))
        ->post(route('rh.evaluaciones.asignar'), ['colaboradores' => [1]])->assertForbidden();

    $this->actingAs(usuarioConPermisos(['evaluaciones.ver', 'evaluaciones.ver_todos', 'evaluaciones.autorizar']))
        ->get(route('rh.evaluaciones.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('puedeAsignar', false));

    $this->actingAs(usuarioDeRol('rh_admin'))
        ->get(route('rh.evaluaciones.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('puedeAsignar', true));
});

/**
 * rh_admin (alcance global por rol) SIN las acciones dadas: el botón que
 * depende de ellas no se ofrece (la bandera sale del mismo chequeo que el
 * endpoint).
 */
function rhSinPermisos(string ...$permisos): User
{
    Role::findByName('rh_admin', 'web')->revokePermissionTo($permisos);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return usuarioDeRol('rh_admin');
}

test('ciclo laboral: «Devolver evaluación» solo con evaluaciones.devolver', function () {
    $colaborador = Colaborador::factory()->create();
    $contrato = ContratoLaboral::factory()->create(['colaborador_id' => $colaborador->id, 'fecha_fin' => now()->addDays(5)->toDateString()]);
    EvaluacionPeriodoPrueba::factory()->create([
        'colaborador_id' => $colaborador->id,
        'contrato_laboral_id' => $contrato->id,
        'estado' => EstadoEvaluacionPrueba::Capturada,
        'fecha_limite' => $contrato->fecha_fin,
    ]);

    $this->actingAs(usuarioDeRol('rh_admin'))
        ->get(route('rh.colaboradores.ciclo', $colaborador))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->whereNot('evaluacion', null)->where('puede_devolver_evaluacion', true));

    $this->actingAs(rhSinPermisos('evaluaciones.devolver'))
        ->get(route('rh.colaboradores.ciclo', $colaborador))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->whereNot('evaluacion', null)->where('puede_devolver_evaluacion', false));
});

test('expediente → Cuenta: la contraseña solo se ofrece con usuarios.restablecer_password', function () {
    $colaborador = Colaborador::factory()->create();
    User::factory()->create(['colaborador_id' => $colaborador->id])->assignRole('colaborador');

    $this->actingAs(usuarioDeRol('rh_admin'))
        ->get(route('rh.expedientes.show', $colaborador))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('puedeGestionarPassword', true));

    // Conserva usuarios.editar (antes bastaba para ver el botón) pero no
    // restablecer contraseña: el endpoint de credenciales respondería 403.
    $rh = rhSinPermisos('usuarios.restablecer_password');
    expect($rh->can('usuarios.editar'))->toBeTrue();

    $this->actingAs($rh)
        ->get(route('rh.expedientes.show', $colaborador))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('puedeGestionarPassword', false));
    $this->actingAs($rh)->postJson(route('rh.colaboradores.credenciales', $colaborador))->assertForbidden();
});

test('documentos maestros: guardar diseño y fondos solo con documentos_maestros.diseno', function () {
    $this->actingAs(usuarioDeRol('rh_admin'))
        ->get(route('rh.documentos-maestros.fondos.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('puedeEditar', true));

    $rh = rhSinPermisos('documentos_maestros.diseno');

    $this->actingAs($rh)
        ->get(route('rh.documentos-maestros.fondos.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('puedeEditar', false));
    $this->actingAs($rh)
        ->get(route('rh.documentos-maestros.administrativos.editar', 'recibo_nomina'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('puedeEditar', false));
    $this->actingAs($rh)->post(route('rh.documentos-maestros.administrativos.borrador', 'recibo_nomina'))->assertForbidden();
});
