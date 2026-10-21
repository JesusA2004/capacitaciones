<?php

use App\Models\Colaborador;
use App\Models\ColaboradorDatosMedicos;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Expedientes\DatosExpedienteService;
use Database\Seeders\RolesYPermisosSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
| Punto 2 / 17 del cierre: edición COMPLETA de datos personales desde el
| expediente (super admin global, RH por alcance), motivo obligatorio en
| datos críticos, auditoría antes/después y historial visible. Nadie edita
| su propio expediente.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
});

function ecPersona(array $atributos = []): Colaborador
{
    return Colaborador::factory()->create([
        'name' => 'Laura',
        'apellidos' => 'Pérez Soto',
        'curp' => 'PESL900101MMSRTR05',
        'rfc' => 'PESL900101AB1',
        'nss' => '12345678901',
        'telefono' => '7771112233',
        ...$atributos,
    ]);
}

function ecUltimaEdicion(Colaborador $colaborador): ?Activity
{
    return Activity::query()
        ->where('log_name', 'rh')
        ->where('event', DatosExpedienteService::EVENTO_PERSONALES)
        ->where('subject_id', $colaborador->id)
        ->latest('id')
        ->first();
}

test('H · super admin edita datos personales críticos con motivo: se guarda y queda en la bitácora con antes/después', function () {
    $admin = clUsuario('super_admin');
    $persona = ecPersona();

    $this->actingAs($admin)
        ->put(route('rh.expedientes.datos-personales.update', $persona), [
            'name' => 'Laura Elena',
            'curp' => 'pesl900101mmsrtr09',
            'nacionalidad' => 'Mexicana',
            'motivo' => 'Corrección según acta de nacimiento',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $persona->refresh();
    expect($persona->name)->toBe('Laura Elena')
        ->and($persona->curp)->toBe('PESL900101MMSRTR09')
        ->and($persona->nacionalidad)->toBe('Mexicana');

    $registro = ecUltimaEdicion($persona);
    expect($registro)->not->toBeNull()
        ->and($registro->causer_id)->toBe($admin->id)
        ->and($registro->properties['motivo'])->toBe('Corrección según acta de nacimiento')
        ->and($registro->properties['modulo'])->toBe('expediente');

    $cambios = collect($registro->properties['cambios'])->keyBy('campo');
    expect($cambios['curp']['antes'])->toBe('PESL900101MMSRTR05')
        ->and($cambios['curp']['despues'])->toBe('PESL900101MMSRTR09')
        ->and($cambios['curp']['critico'])->toBeTrue()
        ->and($cambios['name']['antes'])->toBe('Laura')
        ->and($cambios['nacionalidad']['critico'])->toBeFalse();
});

test('el historial de cambios es visible en el expediente con quién, motivo y antes → después', function () {
    $admin = clUsuario('super_admin');
    $persona = ecPersona();

    $this->actingAs($admin)->put(route('rh.expedientes.datos-personales.update', $persona), [
        'rfc' => 'PESL900101XY9',
        'motivo' => 'Constancia de situación fiscal',
    ])->assertSessionHasNoErrors();

    $this->actingAs($admin)
        ->get(route('rh.expedientes.show', $persona))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Rh/Expedientes/Show')
            ->has('historialCambios', 1)
            ->where('historialCambios.0.por', $admin->nombreCompleto())
            ->where('historialCambios.0.motivo', 'Constancia de situación fiscal')
            ->where('historialCambios.0.modulo', 'Expediente')
            ->where('historialCambios.0.cambios.0.campo', 'rfc')
            ->where('historialCambios.0.cambios.0.antes', 'PESL900101AB1')
            ->where('historialCambios.0.cambios.0.despues', 'PESL900101XY9')
            ->has('camposCriticos')
        );
});

test('motivo obligatorio en datos críticos; un dato no crítico se guarda sin motivo', function () {
    $rh = clUsuario('rh_admin');
    $persona = ecPersona();

    $this->actingAs($rh)
        ->put(route('rh.expedientes.datos-personales.update', $persona), ['nss' => '98765432109'])
        ->assertSessionHasErrors('motivo');
    expect($persona->fresh()->nss)->toBe('12345678901');

    $this->actingAs($rh)
        ->put(route('rh.expedientes.datos-personales.update', $persona), ['telefono' => '7779998877'])
        ->assertSessionHasNoErrors();
    expect($persona->fresh()->telefono)->toBe('7779998877')
        ->and(ecUltimaEdicion($persona)?->properties['motivo'])->toBeNull();
});

test('valida el formato de CURP, RFC y NSS solo cuando cambian', function () {
    $rh = clUsuario('rh_admin');
    $persona = ecPersona(['curp' => 'CURP-LEGADA-MAL']);

    $this->actingAs($rh)
        ->put(route('rh.expedientes.datos-personales.update', $persona), ['curp' => 'ABC123', 'rfc' => 'X', 'nss' => '12AB', 'motivo' => 'Prueba'])
        ->assertSessionHasErrors(['curp', 'rfc', 'nss']);

    // El valor legado (mal formado) que ya estaba no bloquea guardar otro dato.
    $this->actingAs($rh)
        ->put(route('rh.expedientes.datos-personales.update', $persona), ['curp' => 'CURP-LEGADA-MAL', 'telefono' => '7770001122'])
        ->assertSessionHasNoErrors();
    expect($persona->fresh()->telefono)->toBe('7770001122');
});

test('cambiar el sueldo desde el expediente exige motivo y queda en el historial', function () {
    $rh = clUsuario('rh_admin');
    $persona = ecPersona(['sueldo_mensual' => 9000, 'salario_diario' => 300]);

    $this->actingAs($rh)
        ->put(route('rh.expedientes.datos-laborales.update', $persona), ['sueldo_mensual' => 12000])
        ->assertSessionHasErrors('motivo');

    $this->actingAs($rh)
        ->put(route('rh.expedientes.datos-laborales.update', $persona), ['sueldo_mensual' => 12000, 'motivo' => 'Ajuste anual'])
        ->assertSessionHasNoErrors();

    $registro = Activity::query()->where('event', DatosExpedienteService::EVENTO_LABORALES)->where('subject_id', $persona->id)->latest('id')->first();
    $sueldo = collect($registro?->properties['cambios'] ?? [])->firstWhere('campo', 'sueldo_mensual');
    expect($registro?->properties['motivo'])->toBe('Ajuste anual')
        ->and($sueldo['antes'])->toBe('$9,000.00')
        ->and($sueldo['despues'])->toBe('$12,000.00');
});

test('nadie edita su propio expediente, ni el super admin: pide corrección', function () {
    $admin = clUsuario('super_admin', ['name' => 'Admin']);

    $this->actingAs($admin)
        ->put(route('rh.expedientes.datos-personales.update', $admin->colaborador_id), ['name' => 'Otro nombre', 'motivo' => 'x'])
        ->assertForbidden();

    expect($admin->colaborador->fresh()->name)->toBe('Admin');
});

test('RH con alcance de sucursal edita dentro de su alcance y recibe 403 fuera de él', function () {
    $norte = Sucursal::factory()->create();
    $sur = Sucursal::factory()->create();
    $rh = User::factory()->create(['colaborador_id' => Colaborador::factory()->create(['sucursal_principal_id' => $norte->id])->id]);
    $rh->givePermissionTo(['expedientes.ver', 'expedientes.ver_sucursal', 'expedientes.editar']);

    $propia = ecPersona(['sucursal_principal_id' => $norte->id]);
    $ajena = ecPersona(['sucursal_principal_id' => $sur->id]);

    $this->actingAs($rh)
        ->put(route('rh.expedientes.datos-personales.update', $propia), ['telefono' => '7771234567'])
        ->assertSessionHasNoErrors();

    $this->actingAs($rh)
        ->put(route('rh.expedientes.datos-personales.update', $ajena), ['telefono' => '7771234567'])
        ->assertForbidden();
    expect($ajena->fresh()->telefono)->toBe('7771112233');
});

test('datos médicos: se guardan cifrados, la bitácora no los deja en claro y sin permiso no se pueden cambiar', function () {
    $admin = clUsuario('super_admin');
    $persona = ecPersona();

    $this->actingAs($admin)
        ->put(route('rh.expedientes.datos-personales.update', $persona), ['alergias' => 'Penicilina', 'motivo' => 'Reporte médico'])
        ->assertSessionHasNoErrors();

    expect(ColaboradorDatosMedicos::query()->where('colaborador_id', $persona->id)->first()?->alergias)->toBe('Penicilina');

    $registro = ecUltimaEdicion($persona);
    expect(json_encode($registro?->properties))->not->toContain('Penicilina');

    // Historial: quien tiene el permiso lo ve descifrado.
    $this->actingAs($admin)->get(route('rh.expedientes.show', $persona))
        ->assertInertia(fn (Assert $page) => $page->where('historialCambios.0.cambios.0.despues', 'Penicilina'));

    // Sin permiso de datos médicos: prohibido modificarlos.
    $sinMedicos = User::factory()->create(['colaborador_id' => Colaborador::factory()->create()->id]);
    $sinMedicos->givePermissionTo(['expedientes.ver', 'expedientes.ver_todos', 'expedientes.editar']);

    $this->actingAs($sinMedicos)
        ->put(route('rh.expedientes.datos-personales.update', $persona), ['alergias' => 'Ninguna', 'motivo' => 'x'])
        ->assertSessionHasErrors('alergias');
});

test('la edición rápida del Directorio también queda auditada con su módulo', function () {
    $admin = clUsuario('super_admin');
    $persona = ecPersona();

    app(DatosExpedienteService::class)->actualizarPersonales($persona, ['telefono' => '7775550000'], $admin, 'Edición rápida desde el Directorio.', 'directorio');

    expect(ecUltimaEdicion($persona)?->properties['modulo'])->toBe('directorio');
});
