<?php

use App\Enums\EstadoCierreLaboral;
use App\Enums\TipoBaja;
use App\Models\ActaAdministrativa;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\EmployeeDocument;
use App\Models\SolicitudInterna;
use App\Models\SolicitudVacaciones;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

/*
 * super_admin nunca recibe 403 por segregación de actor (ser el titular del
 * recurso), aunque las Policies bloqueen esa condición para cualquier otro
 * rol. sistemas, en cambio, solo tiene lectura global: ve todo pero no
 * decide nada de RH. Ver app/Policies/Concerns/ConSuperAdminOperativo.php.
 */

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

function soaSuperAdminConColaborador(): User
{
    $admin = User::factory()->create(['colaborador_id' => Colaborador::factory()->create()->id]);
    $admin->assignRole('super_admin');

    return $admin->fresh();
}

test('super_admin aprueba y rechaza su propia solicitud interna', function () {
    $admin = soaSuperAdminConColaborador();
    $solicitud = SolicitudInterna::factory()->create(['user_id' => $admin->id]);

    expect($admin->can('revisar', $solicitud))->toBeTrue()
        ->and($admin->can('aprobar', $solicitud))->toBeTrue()
        ->and($admin->can('rechazar', $solicitud))->toBeTrue();
});

test('super_admin levanta un acta sobre su propio colaborador vinculado', function () {
    $admin = soaSuperAdminConColaborador();

    expect($admin->can('crearActa', $admin->colaborador))->toBeTrue();
});

test('super_admin gestiona (incluye aprobar/cerrar) su propia acta administrativa', function () {
    $admin = soaSuperAdminConColaborador();
    $acta = ActaAdministrativa::factory()->create(['colaborador_id' => $admin->colaborador_id]);

    expect($admin->can('ver', $acta))->toBeTrue()
        ->and($admin->can('gestionar', $acta))->toBeTrue();
});

test('super_admin aprueba y rechaza su propia solicitud de vacaciones', function () {
    $admin = soaSuperAdminConColaborador();
    $solicitud = SolicitudVacaciones::factory()->create(['user_id' => $admin->id]);

    expect($admin->can('aprobar', $solicitud))->toBeTrue()
        ->and($admin->can('rechazar', $solicitud))->toBeTrue();
});

test('super_admin revisa y aprueba su propio documento de expediente', function () {
    $admin = soaSuperAdminConColaborador();
    $documento = EmployeeDocument::factory()->create(['user_id' => $admin->id, 'colaborador_id' => $admin->colaborador_id]);

    expect($admin->can('revisar', $documento))->toBeTrue()
        ->and($admin->can('aprobar', $documento))->toBeTrue()
        ->and($admin->can('rechazar', $documento))->toBeTrue();
});

test('super_admin sin colaborador vinculado no finge tener expediente propio', function () {
    $admin = User::factory()->create(['colaborador_id' => null]);
    $admin->assignRole('super_admin');

    expect($admin->can('modo-colaborador'))->toBeFalse();
});

test('sistemas consulta solicitudes, actas, vacaciones y documentos sin poder decidir sobre ellos', function () {
    $sistemas = User::factory()->create(['colaborador_id' => null]);
    $sistemas->assignRole('sistemas');

    $otro = User::factory()->create(['colaborador_id' => Colaborador::factory()->create()->id]);
    $solicitud = SolicitudInterna::factory()->create(['user_id' => $otro->id]);
    $vacacion = SolicitudVacaciones::factory()->create(['user_id' => $otro->id]);
    $acta = ActaAdministrativa::factory()->create(['colaborador_id' => $otro->colaborador_id]);
    $documento = EmployeeDocument::factory()->create(['user_id' => $otro->id, 'colaborador_id' => $otro->colaborador_id]);

    // Ve todo (lectura global del catálogo `sistemas`).
    expect($sistemas->can('view', $solicitud))->toBeTrue()
        ->and($sistemas->can('ver', $acta))->toBeTrue();

    // No decide nada de RH: sin los permisos de aprobar/gestionar, cae en
    // false por la Policy — nunca por falta de alcance.
    expect($sistemas->can('aprobar', $solicitud))->toBeFalse()
        ->and($sistemas->can('rechazar', $solicitud))->toBeFalse()
        ->and($sistemas->can('aprobar', $vacacion))->toBeFalse()
        ->and($sistemas->can('gestionar', $acta))->toBeFalse()
        ->and($sistemas->can('aprobar', $documento))->toBeFalse()
        ->and($sistemas->can('rechazar', $documento))->toBeFalse();
});

test('sistemas nunca ejecuta una baja ni autoriza un finiquito aunque vea el cierre laboral', function () {
    $sistemas = User::factory()->create(['colaborador_id' => null]);
    $sistemas->assignRole('sistemas');

    $colaborador = Colaborador::factory()->create();
    $cierre = CierreLaboral::create([
        'colaborador_id' => $colaborador->id,
        'tipo_baja' => TipoBaja::Renuncia->value,
        'motivo' => 'Renuncia voluntaria.',
        'estado' => EstadoCierreLaboral::PendienteRh->value,
        'fecha_efectiva' => now()->addWeeks(2)->toDateString(),
    ]);

    expect($sistemas->can('ver', $cierre))->toBeTrue()
        ->and($sistemas->can('ejecutarBaja', $cierre))->toBeFalse()
        ->and($sistemas->can('gestionar', $cierre))->toBeFalse()
        ->and($sistemas->can('calcularFiniquito', $cierre))->toBeFalse();
});

test('una cuenta con roles sistemas + super_admin manda super_admin y no queda bloqueada', function () {
    $admin = soaSuperAdminConColaborador();
    $admin->assignRole('sistemas');

    $solicitud = SolicitudInterna::factory()->create(['user_id' => $admin->id]);

    expect($admin->can('aprobar', $solicitud))->toBeTrue();
});
