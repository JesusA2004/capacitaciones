<?php

use App\Models\Colaborador;
use App\Models\SolicitudInterna;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Mínimo privilegio (2026-10-15):
 *  - coordinadora / coordinadora_regional usan PEOPLE como COLABORADORAS
 *    (mismo catálogo que `colaborador`), nunca como operadoras de RH;
 *  - auditor: solo lectura, sin nómina, préstamos ni actas;
 *  - las migraciones 2026_10_15_180000/180100 revocan en bases existentes
 *    lo que el sincronizador (no destructivo) nunca quitaría.
 */

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->sucursal = Sucursal::factory()->create();
});

/** Corre una migración de datos de este cierre sobre la base ya sembrada. */
function rmpCorrerMigracion(string $archivo): void
{
    $migracion = require database_path("migrations/{$archivo}.php");
    $migracion->up();
}

test('la coordinadora tiene exactamente los permisos del colaborador', function (string $rol) {
    $base = RolesYPermisosSeeder::permisosBasePorRol();

    expect($base[$rol])->toEqualCanonicalizing($base['colaborador']);
})->with(['coordinadora', 'coordinadora_regional']);

test('la coordinadora recibe 403 en el expediente ajeno (web y API) y ve el suyo en Mi expediente', function (string $rol) {
    $coordinadora = clUsuario($rol, ['sucursal_principal_id' => $this->sucursal->id]);
    $companero = Colaborador::factory()->create(['sucursal_principal_id' => $this->sucursal->id]);

    $this->actingAs($coordinadora)->get(route('rh.expedientes.show', $companero))->assertForbidden();
    $this->actingAs($coordinadora)->get(route('rh.expedientes.index'))->assertForbidden();

    Sanctum::actingAs($coordinadora);
    $this->getJson("/api/v1/rh/expedientes/{$companero->id}")->assertForbidden();
    $this->getJson('/api/v1/rh/expedientes')->assertForbidden();
})->with(['coordinadora', 'coordinadora_regional']);

test('la coordinadora recibe 403 en las solicitudes de RH (web y API)', function (string $rol) {
    $coordinadora = clUsuario($rol, ['sucursal_principal_id' => $this->sucursal->id]);
    $otro = clUsuario('colaborador', ['sucursal_principal_id' => $this->sucursal->id]);
    $solicitud = SolicitudInterna::factory()->create(['user_id' => $otro->id]);

    $this->actingAs($coordinadora)->get(route('rh.solicitudes.show', $solicitud))->assertForbidden();
    $this->actingAs($coordinadora)->post(route('rh.solicitudes.aprobar', $solicitud))->assertForbidden();

    Sanctum::actingAs($coordinadora);
    $this->getJson('/api/v1/rh/solicitudes')->assertForbidden();
    $this->getJson("/api/v1/rh/solicitudes/{$solicitud->id}")->assertForbidden();
})->with(['coordinadora', 'coordinadora_regional']);

test('el bootstrap móvil de la coordinadora es de colaboradora: sin RH ni manager', function (string $rol) {
    Sanctum::actingAs(clUsuario($rol, ['sucursal_principal_id' => $this->sucursal->id]));

    $this->getJson('/api/v1/mobile/bootstrap')
        ->assertOk()
        ->assertJsonPath('capabilities.employee', true)
        ->assertJsonPath('capabilities.rh', false)
        ->assertJsonPath('capabilities.manager', false)
        ->assertJsonPath('capabilities.director', false);
})->with(['coordinadora', 'coordinadora_regional']);

test('la migración revoca los permisos operativos viejos de las coordinadoras y es idempotente', function () {
    $viejos = ['expedientes.ver_sucursal', 'documentos.revisar', 'solicitudes.revisar', 'rh.pendientes.ver', 'rh.mobile.dashboard.ver',
        'rh.documentos.ver_archivo', 'usuarios.ver', 'usuarios.restablecer_acceso', 'cierres.programar_pago', 'finiquitos.ver', 'dashboard.sucursal.ver'];

    foreach (['coordinadora', 'coordinadora_regional'] as $rol) {
        Role::findByName($rol)->givePermissionTo($viejos);
    }
    // Otro rol con un permiso "extra" agregado por un administrador: no se toca.
    Role::findByName('gerente')->givePermissionTo('reportes.globales');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $coordinadora = clUsuario('coordinadora_regional', ['sucursal_principal_id' => $this->sucursal->id]);
    expect($coordinadora->can('solicitudes.revisar'))->toBeTrue();

    rmpCorrerMigracion('2026_10_15_180000_coordinadoras_usan_people_como_colaboradoras');
    rmpCorrerMigracion('2026_10_15_180000_coordinadoras_usan_people_como_colaboradoras');

    $colaborador = Role::findByName('colaborador')->permissions()->pluck('name')->all();

    foreach (['coordinadora', 'coordinadora_regional'] as $rol) {
        $actuales = Role::findByName($rol)->permissions()->pluck('name')->all();

        expect($actuales)->toEqualCanonicalizing($colaborador)
            ->and(array_intersect($viejos, $actuales))->toBe([]);
    }

    $coordinadora = User::query()->where('id', $coordinadora->id)->firstOrFail();
    expect($coordinadora->can('solicitudes.revisar'))->toBeFalse()
        ->and($coordinadora->can('portal.ver'))->toBeTrue()
        ->and(Role::findByName('gerente')->hasPermissionTo('reportes.globales'))->toBeTrue();
});

test('la migración no hace nada si el rol no existe', function () {
    Role::findByName('coordinadora')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    rmpCorrerMigracion('2026_10_15_180000_coordinadoras_usan_people_como_colaboradoras');

    expect(Role::query()->where('name', 'coordinadora')->exists())->toBeFalse();
});

/*
 * Subgerente (2026-10-16): usa PEOPLE como COLABORADOR, EXACTAMENTE igual que
 * las coordinadoras. Deja de apoyar/cubrir operativamente al gerente. La
 * migración 2026_10_16_090000 revoca en bases existentes lo que el
 * sincronizador (no destructivo) nunca quitaría.
 */

test('el subgerente tiene exactamente los permisos del colaborador', function () {
    $base = RolesYPermisosSeeder::permisosBasePorRol();

    expect($base['subgerente'])->toEqualCanonicalizing($base['colaborador']);
});

test('el subgerente recibe 403 en el expediente ajeno (web y API) y ve el suyo en Mi expediente', function () {
    $subgerente = clUsuario('subgerente', ['sucursal_principal_id' => $this->sucursal->id]);
    $companero = Colaborador::factory()->create(['sucursal_principal_id' => $this->sucursal->id]);

    $this->actingAs($subgerente)->get(route('rh.expedientes.show', $companero))->assertForbidden();
    $this->actingAs($subgerente)->get(route('rh.expedientes.index'))->assertForbidden();

    Sanctum::actingAs($subgerente);
    $this->getJson("/api/v1/rh/expedientes/{$companero->id}")->assertForbidden();
    $this->getJson('/api/v1/rh/expedientes')->assertForbidden();
});

test('el subgerente recibe 403 en las solicitudes y vacaciones de RH (web y API)', function () {
    $subgerente = clUsuario('subgerente', ['sucursal_principal_id' => $this->sucursal->id]);
    $otro = clUsuario('colaborador', ['sucursal_principal_id' => $this->sucursal->id]);
    $solicitud = SolicitudInterna::factory()->create(['user_id' => $otro->id]);

    $this->actingAs($subgerente)->get(route('rh.solicitudes.show', $solicitud))->assertForbidden();
    $this->actingAs($subgerente)->post(route('rh.solicitudes.aprobar', $solicitud))->assertForbidden();

    Sanctum::actingAs($subgerente);
    $this->getJson('/api/v1/rh/solicitudes')->assertForbidden();
    $this->getJson("/api/v1/rh/solicitudes/{$solicitud->id}")->assertForbidden();
    $this->getJson('/api/v1/rh/vacaciones')->assertForbidden();
});

test('el bootstrap móvil del subgerente es de colaborador: sin RH ni manager pero sí empleado', function () {
    Sanctum::actingAs(clUsuario('subgerente', ['sucursal_principal_id' => $this->sucursal->id]));

    $this->getJson('/api/v1/mobile/bootstrap')
        ->assertOk()
        ->assertJsonPath('capabilities.employee', true)
        ->assertJsonPath('capabilities.rh', false)
        ->assertJsonPath('capabilities.manager', false)
        ->assertJsonPath('capabilities.director', false);
});

test('el subgerente puede ver su propio perfil, crear sus solicitudes/vacaciones y leer sus notificaciones', function () {
    $subgerente = clUsuario('subgerente', ['sucursal_principal_id' => $this->sucursal->id]);

    expect($subgerente->can('portal.perfil.ver'))->toBeTrue()
        ->and($subgerente->can('portal.solicitudes.crear'))->toBeTrue()
        ->and($subgerente->can('solicitudes.crear'))->toBeTrue()
        ->and($subgerente->can('vacaciones.solicitar'))->toBeTrue()
        ->and($subgerente->can('portal.notificaciones.ver'))->toBeTrue()
        ->and($subgerente->can('notificaciones.leer_todas'))->toBeTrue();
});

test('la migración revoca los permisos operativos viejos del subgerente y es idempotente', function () {
    $viejos = ['expedientes.ver_sucursal', 'solicitudes.revisar', 'solicitudes.aprobar', 'rh.pendientes.ver', 'rh.mobile.dashboard.ver',
        'usuarios.ver', 'reportes.sucursal', 'cierres.ver', 'dashboard.sucursal.ver'];

    Role::findByName('subgerente')->givePermissionTo($viejos);
    // Otro rol con un permiso "extra" agregado por un administrador: no se toca.
    Role::findByName('gerente')->givePermissionTo('reportes.globales');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $subgerente = clUsuario('subgerente', ['sucursal_principal_id' => $this->sucursal->id]);
    expect($subgerente->can('solicitudes.revisar'))->toBeTrue();

    rmpCorrerMigracion('2026_10_16_090000_subgerente_usa_people_como_colaborador');
    rmpCorrerMigracion('2026_10_16_090000_subgerente_usa_people_como_colaborador');

    $colaborador = Role::findByName('colaborador')->permissions()->pluck('name')->all();
    $actuales = Role::findByName('subgerente')->permissions()->pluck('name')->all();

    expect($actuales)->toEqualCanonicalizing($colaborador)
        ->and(array_intersect($viejos, $actuales))->toBe([]);

    $subgerente = User::query()->where('id', $subgerente->id)->firstOrFail();
    expect($subgerente->can('solicitudes.revisar'))->toBeFalse()
        ->and($subgerente->can('portal.ver'))->toBeTrue()
        ->and(Role::findByName('gerente')->hasPermissionTo('reportes.globales'))->toBeTrue();
});

test('la migración del subgerente no hace nada si el rol no existe', function () {
    Role::findByName('subgerente')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    rmpCorrerMigracion('2026_10_16_090000_subgerente_usa_people_como_colaborador');

    expect(Role::query()->where('name', 'subgerente')->exists())->toBeFalse();
});

test('el auditor solo lee: sin nómina, préstamos ni actas (web y API) y sin escribir', function () {
    $auditor = clUsuario('auditor');

    expect($auditor->can('nomina.recibos.ver'))->toBeFalse()
        ->and($auditor->can('prestamos.ver'))->toBeFalse()
        ->and($auditor->can('actas.ver'))->toBeFalse()
        ->and($auditor->can('expedientes.ver_todos'))->toBeTrue();

    $this->actingAs($auditor)->get(route('rh.nomina.index'))->assertForbidden();
    $this->actingAs($auditor)->get(route('rh.actas.index'))->assertForbidden();

    Sanctum::actingAs($auditor);
    $this->getJson('/api/v1/rh/prestamos')->assertForbidden();
    $this->getJson('/api/v1/rh/recibos')->assertForbidden();
    $this->getJson('/api/v1/rh/actas')->assertForbidden();

    // Ningún permiso de escritura/decisión en su catálogo.
    $escritura = '/\.(crear|editar|eliminar|subir|aprobar|rechazar|autorizar|gestionar|generar|administrar|ajustar|revisar|emitir|importar|capturar|cancelar|desactivar|reactivar|publicar)$/';
    $base = array_filter(RolesYPermisosSeeder::permisosBasePorRol()['auditor'], fn (string $p) => ! str_starts_with($p, 'dispositivos.'));

    expect(array_values(preg_grep($escritura, $base) ?: []))->toBe([]);
});

test('la migración del auditor le revoca nómina, préstamos y actas con su alcance', function () {
    $viejos = ['nomina.recibos.ver', 'prestamos.ver', 'prestamos.ver_todos', 'actas.ver', 'actas.ver_todos'];

    foreach ($viejos as $permiso) {
        Permission::findOrCreate($permiso, 'web');
    }

    Role::findByName('auditor')->givePermissionTo($viejos);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    rmpCorrerMigracion('2026_10_15_180100_auditor_minimo_privilegio');

    $actuales = Role::findByName('auditor')->permissions()->pluck('name')->all();

    expect(array_intersect($viejos, $actuales))->toBe([])
        ->and($actuales)->toEqualCanonicalizing(RolesYPermisosSeeder::permisosBasePorRol()['auditor']);
});

/*
 * Sistemas (hallazgo de auditoría 2026-10-16): soporte técnico ve
 * ESTADO/METADATA (cuentas, roles, auditoría, conteos, listas), NUNCA el
 * CONTENIDO sensible de RH (expediente con PII, documentos, contratos, actas,
 * recibos, finiquitos). La migración 2026_10_16_110000 revoca en bases
 * existentes lo que el sincronizador (aditivo) nunca quitaría.
 */

test('sistemas NO tiene permisos de contenido sensible de RH, pero SÍ los técnicos y de metadata', function () {
    $sistemas = clUsuario('sistemas');

    // Contenido sensible / PII: retirado por mínimo privilegio.
    foreach (['expedientes.ver', 'expedientes.ver_todos', 'documentos.ver', 'documentos_laborales.ver',
        'contratos.ver', 'actas.ver', 'nomina.recibos.ver', 'finiquitos.ver', 'formatos_oficiales.ver',
        'rh.expedientes.detalle', 'rh.expedientes.documentos.ver', 'rh.documentos.detalle'] as $permiso) {
        expect($sistemas->can($permiso))->toBeFalse("sistemas no debería tener {$permiso}");
    }

    // Técnico/metadata: conservado (ver estado, no contenido).
    foreach (['usuarios.ver', 'roles.administrar', 'auditoria.ver', 'app_releases.ver', 'app.config.ver',
        'mobile.bootstrap.ver', 'configuracion.ver', 'dashboard.global.ver',
        'rh.expedientes.ver', 'rh.documentos.ver', 'rh.pendientes.ver'] as $permiso) {
        expect($sistemas->can($permiso))->toBeTrue("sistemas debería conservar {$permiso}");
    }
});

test('sistemas recibe 403 en el CONTENIDO sensible de RH (web y API) y 200 en la metadata técnica', function () {
    $sistemas = clUsuario('sistemas');
    $ajeno = Colaborador::factory()->create(['sucursal_principal_id' => $this->sucursal->id]);

    // Contenido/PII: expediente, actas y recibos de nómina → 403 (web).
    $this->actingAs($sistemas)->get(route('rh.expedientes.index'))->assertForbidden();
    $this->actingAs($sistemas)->get(route('rh.expedientes.show', $ajeno))->assertForbidden();
    $this->actingAs($sistemas)->get(route('rh.actas.index'))->assertForbidden();
    $this->actingAs($sistemas)->get(route('rh.nomina.index'))->assertForbidden();

    // Metadata/administración de plataforma → 200.
    $this->actingAs($sistemas)->get(route('administracion.usuarios.index'))->assertSuccessful();
    $this->actingAs($sistemas)->get(route('administracion.roles.index'))->assertSuccessful();

    // API móvil: el DETALLE con PII y los módulos de contenido → 403; la LISTA
    // de expedientes (metadata/conteos) sigue disponible para diagnóstico.
    Sanctum::actingAs($sistemas);
    $this->getJson('/api/v1/rh/expedientes')->assertOk();
    $this->getJson("/api/v1/rh/expedientes/{$ajeno->id}")->assertForbidden();
    $this->getJson('/api/v1/rh/recibos')->assertForbidden();
    $this->getJson('/api/v1/rh/actas')->assertForbidden();
});

test('la migración revoca el contenido sensible histórico de sistemas y lo deja en su base, idempotente', function () {
    $viejos = ['expedientes.ver', 'expedientes.ver_todos', 'documentos.ver', 'documentos_laborales.ver',
        'contratos.ver', 'actas.ver', 'nomina.recibos.ver', 'finiquitos.ver', 'formatos_oficiales.ver',
        'rh.expedientes.detalle', 'rh.expedientes.documentos.ver', 'rh.documentos.detalle'];

    foreach ($viejos as $permiso) {
        Permission::findOrCreate($permiso, 'web');
    }

    Role::findByName('sistemas')->givePermissionTo($viejos);
    // Otro rol con un permiso "extra" agregado por un administrador: no se toca.
    Role::findByName('gerente')->givePermissionTo('reportes.globales');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $sistemas = clUsuario('sistemas');
    expect($sistemas->can('expedientes.ver'))->toBeTrue();

    rmpCorrerMigracion('2026_10_16_110000_sistemas_minimo_privilegio');
    rmpCorrerMigracion('2026_10_16_110000_sistemas_minimo_privilegio');

    $actuales = Role::findByName('sistemas')->permissions()->pluck('name')->all();

    expect(array_intersect($viejos, $actuales))->toBe([])
        ->and($actuales)->toEqualCanonicalizing(RolesYPermisosSeeder::permisosBasePorRol()['sistemas'])
        ->and(Role::findByName('gerente')->hasPermissionTo('reportes.globales'))->toBeTrue();

    $sistemas = User::query()->where('id', $sistemas->id)->firstOrFail();
    expect($sistemas->can('expedientes.ver'))->toBeFalse()
        ->and($sistemas->can('auditoria.ver'))->toBeTrue();
});

test('la migración de sistemas no hace nada si el rol no existe', function () {
    Role::findByName('sistemas')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    rmpCorrerMigracion('2026_10_16_110000_sistemas_minimo_privilegio');

    expect(Role::query()->where('name', 'sistemas')->exists())->toBeFalse();
});
