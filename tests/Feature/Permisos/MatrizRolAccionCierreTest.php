<?php

use App\Models\Prestamo;
use App\Models\Sucursal;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
| Matriz rol × acción, cierre de Fase 1 (2026-11-04): celdas críticas que
| no cubrían SidebarPorRolTest ni AuditoriaRolesGerenciaTest.
|  - Cambio de contraseña / edición / revocación de cuentas: nunca sobre una
|    cuenta de rol superior (mismo nivel que «Asignar roles»).
|  - Nadie gestiona lo propio desde el expediente de RH (préstamo, IMSS).
|  - Sistemas sin importes: tampoco la descarga del lote de recibos.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    $this->sucursal = Sucursal::factory()->create();
    $this->base = ['estatus' => 'activo', 'sucursal_principal_id' => $this->sucursal->id];
});

function mraMigracion(string $archivo): void
{
    $migracion = require database_path("migrations/{$archivo}.php");
    $migracion->up();
}

test('nadie restablece la contraseña de una cuenta de rol superior al suyo (web y API)', function (string $actorRol, string $objetivoRol) {
    $actor = clUsuario($actorRol, $this->base);
    $objetivo = clUsuario($objetivoRol, $this->base);
    $hashAntes = (string) $objetivo->password;

    $this->actingAs($actor)
        ->postJson(route('administracion.usuarios.establecer-password', $objetivo), ['password' => 'NuevaClave2026!x'])
        ->assertForbidden();

    Sanctum::actingAs($actor);
    $this->postJson("/api/v1/rh/colaboradores/{$objetivo->colaborador_id}/credenciales", ['password' => 'NuevaClave2026!x'])
        ->assertForbidden();

    expect((string) $objetivo->refresh()->password)->toBe($hashAntes)
        ->and(Hash::check('NuevaClave2026!x', (string) $objetivo->password))->toBeFalse();
})->with([
    'RH → super_admin' => ['rh_admin', 'super_admin'],
    'RH → Sistemas' => ['rh_admin', 'sistemas'],
    'RH → Dirección' => ['rh_admin', 'direccion'],
    'Sistemas → super_admin' => ['sistemas', 'super_admin'],
    'Admin. capacitación → RH' => ['administrador_capacitacion', 'rh_admin'],
    'Admin. capacitación → super_admin' => ['administrador_capacitacion', 'super_admin'],
]);

test('RH sí restablece la contraseña de un colaborador y de otra cuenta de RH; super_admin, de cualquiera', function () {
    $rh = clUsuario('rh_admin', $this->base);

    foreach ([clUsuario('colaborador', $this->base), clUsuario('rh_admin', $this->base), clUsuario('gerente_regional', $this->base)] as $objetivo) {
        $this->actingAs($rh)->postJson(route('administracion.usuarios.establecer-password', $objetivo))->assertOk();
    }

    $super = clUsuario('super_admin', $this->base);
    $this->actingAs($super)->postJson(route('administracion.usuarios.establecer-password', clUsuario('sistemas', $this->base)))->assertOk();
});

test('RH no edita el usuario/correo ni revoca el acceso de una cuenta superior', function () {
    $rh = clUsuario('rh_admin', $this->base);
    $super = clUsuario('super_admin', $this->base);

    $this->actingAs($rh)
        ->put(route('administracion.usuarios.update', $super), ['username' => 'tomada', 'email' => 'tomada@mrlana.test', 'roles' => ['super_admin']])
        ->assertForbidden();
    $this->actingAs($rh)->post(route('administracion.usuarios.revocar-acceso', $super))->assertForbidden();

    expect($super->refresh()->username)->not->toBe('tomada')
        ->and($super->email)->not->toBe('tomada@mrlana.test')
        ->and($super->puedeAccederPortal())->toBeTrue();
});

test('RH no confirma la entrega ni registra abonos de su propio préstamo; sí los de otra persona', function () {
    $rh = clUsuario('rh_admin', $this->base);
    $propio = Prestamo::factory()->create(['colaborador_id' => $rh->colaborador_id]);
    $ajeno = Prestamo::factory()->create(['colaborador_id' => clUsuario('colaborador', $this->base)->colaborador_id]);
    $entrega = ['fecha_otorgamiento' => now()->toDateString(), 'fecha_primer_descuento' => now()->addDays(15)->toDateString(), 'periodicidad' => 'quincenal', 'pago_programado' => 500];

    $this->actingAs($rh)->post(route('rh.expedientes.prestamos.activar', $propio), $entrega)->assertForbidden();
    $this->actingAs($rh)->post(route('rh.expedientes.prestamos.movimientos.store', $propio), ['monto' => 100, 'tipo' => 'manual'])->assertForbidden();
    expect($propio->refresh()->estado)->toBe('pendiente_entrega');

    $this->actingAs($rh)->post(route('rh.expedientes.prestamos.activar', $ajeno), $entrega)->assertSessionHasNoErrors()->assertRedirect();
});

test('RH no sube su propio acuse de alta IMSS', function () {
    Storage::fake('nas');
    $rh = clUsuario('rh_admin', $this->base);

    $this->actingAs($rh)
        ->post(route('rh.expedientes.alta-imss.store', $rh->colaborador_id), ['archivo' => UploadedFile::fake()->create('acuse.pdf', 20, 'application/pdf')])
        ->assertForbidden();
});

test('la migración quita a Sistemas la descarga y el alcance de recibos y finiquitos que quedaron de la herencia', function () {
    $sobrantes = ['nomina.recibos.descargar', 'nomina.recibos.ver_todos', 'finiquitos.ver_todos'];
    $sistemas = Role::findByName('sistemas');
    $sistemas->givePermissionTo($sobrantes);
    Role::findByName('rh_admin')->givePermissionTo($sobrantes);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    mraMigracion('2026_11_13_500200_sistemas_sin_descarga_ni_alcance_de_recibos_y_finiquitos');
    mraMigracion('2026_11_13_500200_sistemas_sin_descarga_ni_alcance_de_recibos_y_finiquitos');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $permisos = $sistemas->fresh()->permissions()->pluck('name')->all();
    expect(array_values(array_filter($permisos, fn (string $p): bool => str_starts_with($p, 'nomina.recibos.') || str_starts_with($p, 'finiquitos.'))))->toBe([])
        ->and(Role::findByName('rh_admin')->hasPermissionTo('nomina.recibos.descargar'))->toBeTrue();

    $usuario = clUsuario('sistemas', $this->base);
    expect($usuario->can('nomina.recibos.descargar'))->toBeFalse();
});
