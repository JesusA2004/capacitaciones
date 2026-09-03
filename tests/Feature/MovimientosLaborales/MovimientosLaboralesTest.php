<?php

use App\Models\AltaDigital;
use App\Models\Candidato;
use App\Models\MovimientoLaboral;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vacante;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('aprobar un alta digital registra un movimiento de alta', function () {
    $candidato = Candidato::factory()->create([
        'estado' => 'aprobado_rh',
        'cv_disk' => 'nas',
        'cv_path' => 'candidatos/test/cv.pdf',
        'cv_original_name' => 'cv.pdf',
    ]);
    Storage::disk('nas')->put($candidato->cv_path, 'contenido');

    $alta = AltaDigital::factory()->create([
        'candidato_id' => $candidato->id,
        'estado' => 'en_revision_rh',
        'correo' => 'alta.movimiento@example.com',
    ]);

    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $this->actingAs($rh)
        ->post(route('rh.altas.aprobar', $alta))
        ->assertSessionHasNoErrors();

    $colaborador = User::where('email', 'alta.movimiento@example.com')->firstOrFail();

    $movimiento = MovimientoLaboral::where('user_id', $colaborador->id)->first();

    expect($movimiento)->not->toBeNull()
        ->and($movimiento->tipo_movimiento->value)->toBe('alta')
        ->and($movimiento->puesto_nuevo_id)->toBe($colaborador->puesto_id);
});

test('cambiar el puesto de un colaborador registra un movimiento de cambio de puesto', function () {
    $sucursal = Sucursal::factory()->create();
    $puestoOrigen = Puesto::factory()->create(['nivel_jerarquico' => 5]);
    $puestoDestino = Puesto::factory()->create(['nivel_jerarquico' => 5]);

    $colaborador = User::factory()->create([
        'sucursal_principal_id' => $sucursal->id,
        'puesto_id' => $puestoOrigen->id,
    ]);
    $colaborador->assignRole('colaborador');

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)
        ->put(route('administracion.usuarios.update', $colaborador), [
            'name' => $colaborador->name,
            'apellidos' => $colaborador->apellidos,
            'email' => $colaborador->email,
            'sucursal_principal_id' => $sucursal->id,
            'puesto_id' => $puestoDestino->id,
        ])
        ->assertSessionHasNoErrors();

    $movimiento = MovimientoLaboral::where('user_id', $colaborador->id)->first();

    expect($movimiento)->not->toBeNull()
        ->and($movimiento->tipo_movimiento->value)->toBe('cambio_puesto')
        ->and($movimiento->puesto_anterior_id)->toBe($puestoOrigen->id)
        ->and($movimiento->puesto_nuevo_id)->toBe($puestoDestino->id);
});

test('subir a un puesto de mayor nivel registra una promoción y puede generar la vacante del puesto que se deja', function () {
    $sucursal = Sucursal::factory()->create();
    $puestoOrigen = Puesto::factory()->create(['nivel_jerarquico' => 5]);
    $puestoDestino = Puesto::factory()->create(['nivel_jerarquico' => 3]);

    $colaborador = User::factory()->create([
        'sucursal_principal_id' => $sucursal->id,
        'puesto_id' => $puestoOrigen->id,
    ]);
    $colaborador->assignRole('colaborador');

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)
        ->put(route('administracion.usuarios.update', $colaborador), [
            'name' => $colaborador->name,
            'apellidos' => $colaborador->apellidos,
            'email' => $colaborador->email,
            'sucursal_principal_id' => $sucursal->id,
            'puesto_id' => $puestoDestino->id,
            'crear_vacante_reemplazo' => true,
            'motivo_movimiento' => 'Promoción interna',
        ])
        ->assertSessionHasNoErrors();

    $movimiento = MovimientoLaboral::where('user_id', $colaborador->id)->first();
    $vacante = Vacante::where('puesto_id', $puestoOrigen->id)->first();

    expect($movimiento->tipo_movimiento->value)->toBe('promocion')
        ->and($vacante)->not->toBeNull()
        ->and($vacante->motivo->value)->toBe('promocion')
        ->and($movimiento->vacante_id)->toBe($vacante->id);
});

test('dar de baja a un colaborador registra el movimiento y puede generar una vacante de reemplazo', function () {
    $sucursal = Sucursal::factory()->create();
    $puesto = Puesto::factory()->create();

    $colaborador = User::factory()->create([
        'sucursal_principal_id' => $sucursal->id,
        'puesto_id' => $puesto->id,
    ]);
    $colaborador->assignRole('colaborador');

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)
        ->delete(route('administracion.usuarios.destroy', $colaborador), [
            'motivo' => 'Renuncia voluntaria',
            'crear_vacante' => true,
        ])
        ->assertSessionHasNoErrors();

    $movimiento = MovimientoLaboral::where('user_id', $colaborador->id)->first();
    $vacante = Vacante::where('puesto_id', $puesto->id)->first();

    expect($movimiento->tipo_movimiento->value)->toBe('baja')
        ->and($vacante)->not->toBeNull()
        ->and($vacante->motivo->value)->toBe('baja_colaborador')
        ->and($colaborador->fresh()->estatus->value)->toBe('inactivo');
});

test('cubrir una vacante con un colaborador interno mueve su puesto y cierra la vacante', function () {
    $puestoDestino = Puesto::factory()->create();
    $vacante = Vacante::factory()->create(['puesto_id' => $puestoDestino->id, 'estado' => 'abierta']);

    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $this->actingAs($rh)
        ->post(route('rh.vacantes.cubrir', $vacante), [
            'modo' => 'colaborador_interno',
            'user_id' => $colaborador->id,
        ])
        ->assertSessionHasNoErrors();

    expect($colaborador->fresh()->puesto_id)->toBe($puestoDestino->id)
        ->and($vacante->fresh()->estado->value)->toBe('cubierta');

    $movimiento = MovimientoLaboral::where('user_id', $colaborador->id)->first();
    expect($movimiento->vacante_id)->toBe($vacante->id);
});

test('cubrir una vacante con cobertura temporal no cambia el puesto definitivo del colaborador', function () {
    $puestoDestino = Puesto::factory()->create();
    $vacante = Vacante::factory()->create(['puesto_id' => $puestoDestino->id, 'estado' => 'abierta']);

    $puestoOriginal = Puesto::factory()->create();
    $colaborador = User::factory()->create(['puesto_id' => $puestoOriginal->id]);
    $colaborador->assignRole('colaborador');

    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $this->actingAs($rh)
        ->post(route('rh.vacantes.cubrir', $vacante), [
            'modo' => 'cobertura_temporal',
            'user_id' => $colaborador->id,
            'fecha_inicio' => now()->toDateString(),
        ])
        ->assertSessionHasNoErrors();

    expect($colaborador->fresh()->puesto_id)->toBe($puestoOriginal->id)
        ->and($vacante->fresh()->estado->value)->toBe('abierta');

    $movimiento = MovimientoLaboral::where('user_id', $colaborador->id)->first();
    expect($movimiento->tipo_movimiento->value)->toBe('cobertura_temporal');
});
