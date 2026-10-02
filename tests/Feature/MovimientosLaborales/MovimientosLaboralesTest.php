<?php

use App\Models\AltaDigital;
use App\Models\Candidato;
use App\Models\HeadcountTarget;
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
        'estado' => 'autorizado_rh',
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

    // administracion.usuarios.update solo administra la CUENTA de acceso
    // (email/roles) desde el refactor Usuario/Colaborador — el cambio de
    // puesto vive en rh.expedientes.datos-laborales.update
    // (ExpedienteController::actualizarDatosLaborales), ver
    // docs/ROLES_Y_NAVEGACION.md.
    $this->actingAs($admin)
        ->put(route('rh.expedientes.datos-laborales.update', $colaborador->colaborador_id), [
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

test('subir a un puesto de mayor nivel jerárquico registra el movimiento como promoción', function () {
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

    // Las vacantes se derivan de headcount, nunca se crean a mano al mover
    // a alguien de puesto (docs/HEADCOUNT_Y_VACANTES.md): este movimiento
    // solo registra la promoción, no abre una vacante de reemplazo por sí
    // mismo (eso lo hace VacanteAutoGenerationService cuando corresponda).
    $this->actingAs($admin)
        ->put(route('rh.expedientes.datos-laborales.update', $colaborador->colaborador_id), [
            'sucursal_principal_id' => $sucursal->id,
            'puesto_id' => $puestoDestino->id,
            'motivo' => 'Promoción interna',
        ])
        ->assertSessionHasNoErrors();

    $movimiento = MovimientoLaboral::where('user_id', $colaborador->id)->first();

    expect($movimiento->tipo_movimiento->value)->toBe('promocion')
        ->and($movimiento->puesto_anterior_id)->toBe($puestoOrigen->id)
        ->and($movimiento->puesto_nuevo_id)->toBe($puestoDestino->id)
        ->and($movimiento->motivo)->toBe('Promoción interna');
});

test('dar de baja a un colaborador registra el movimiento, bloquea su acceso y sincroniza la vacante automática si el headcount la requiere', function () {
    $sucursal = Sucursal::factory()->create();
    $puesto = Puesto::factory()->create();
    HeadcountTarget::factory()->create([
        'sucursal_id' => $sucursal->id,
        'puesto_id' => $puesto->id,
        'plantilla_autorizada' => 1,
    ]);

    $colaborador = User::factory()->create([
        'sucursal_principal_id' => $sucursal->id,
        'puesto_id' => $puesto->id,
    ]);
    $colaborador->assignRole('colaborador');

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    // administracion.usuarios.destroy no existe (Nunca borres usuarios ni
    // expedientes — CLAUDE.md): la baja laboral directa es
    // rh.expedientes.dar-de-baja (soft-delete + bloqueo de acceso), ver
    // ExpedienteController::darDeBaja()/BajaColaboradorService.
    $this->actingAs($admin)
        ->delete(route('rh.expedientes.dar-de-baja', $colaborador->colaborador_id), [
            'motivo' => 'Renuncia voluntaria',
        ])
        ->assertRedirect();

    $movimiento = MovimientoLaboral::where('user_id', $colaborador->id)->first();
    // No se crea una vacante "de reemplazo" a mano: BajaColaboradorService
    // sincroniza la vacante automática de (sucursal, puesto) contra el
    // headcount real, así que aparece sola si la plantilla la sigue exigiendo.
    $vacante = Vacante::where('sucursal_id', $sucursal->id)->where('puesto_id', $puesto->id)->first();

    expect($movimiento->tipo_movimiento->value)->toBe('baja')
        ->and($colaborador->colaborador->fresh()->estatus->value)->toBe('inactivo')
        ->and($vacante)->not->toBeNull()
        ->and($vacante->generada_automaticamente)->toBeTrue()
        ->and($vacante->estado->value)->toBe('abierta');
});

test('guardar datos laborales sin sueldo asigna el salario mínimo SOLO si el colaborador nunca tuvo uno', function () {
    config(['nomina.salario_minimo_diario' => 278.80, 'nomina.salario_minimo_mensual' => 278.80 * 30]);

    $sucursal = Sucursal::factory()->create();
    $puesto = Puesto::factory()->create();
    $colaborador = User::factory()->create([
        'sucursal_principal_id' => $sucursal->id,
        'puesto_id' => $puesto->id,
    ]);
    $colaborador->assignRole('colaborador');
    // ColaboradorFactory pone un sueldo aleatorio por defecto (8000-25000):
    // se limpia a mano para probar de verdad el caso "nunca tuvo sueldo".
    $colaborador->colaborador->update(['sueldo_mensual' => null]);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)
        ->put(route('rh.expedientes.datos-laborales.update', $colaborador->colaborador_id), [
            'sucursal_principal_id' => $sucursal->id,
            'puesto_id' => $puesto->id,
        ])
        ->assertSessionHasNoErrors();

    expect((float) $colaborador->colaborador->fresh()->sueldo_mensual)->toBe(278.80 * 30);
});

test('guardar datos laborales nunca sobrescribe un sueldo que ya existía, aunque el campo llegue vacío', function () {
    config(['nomina.salario_minimo_diario' => 278.80, 'nomina.salario_minimo_mensual' => 278.80 * 30]);

    $sucursal = Sucursal::factory()->create();
    $puesto = Puesto::factory()->create();
    $colaborador = User::factory()->create([
        'sucursal_principal_id' => $sucursal->id,
        'puesto_id' => $puesto->id,
    ]);
    $colaborador->assignRole('colaborador');
    // sueldo_mensual no se sincroniza desde User::factory() (ver
    // UserFactory::configure()): se fija a mano en el Colaborador real.
    $colaborador->colaborador->update(['sueldo_mensual' => 15000]);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)
        ->put(route('rh.expedientes.datos-laborales.update', $colaborador->colaborador_id), [
            'sucursal_principal_id' => $sucursal->id,
            'puesto_id' => $puesto->id,
            'sueldo_mensual' => '',
        ])
        ->assertSessionHasNoErrors();

    expect((float) $colaborador->colaborador->fresh()->sueldo_mensual)->toBe(15000.0);
});

test('sin SALARIO_MINIMO_DIARIO configurado, un colaborador sin sueldo se queda sin sueldo y con aviso — nunca se inventa un monto', function () {
    config(['nomina.salario_minimo_diario' => null, 'nomina.salario_minimo_mensual' => null]);

    $sucursal = Sucursal::factory()->create();
    $puesto = Puesto::factory()->create();
    $colaborador = User::factory()->create([
        'sucursal_principal_id' => $sucursal->id,
        'puesto_id' => $puesto->id,
    ]);
    $colaborador->assignRole('colaborador');
    $colaborador->colaborador->update(['sueldo_mensual' => null]);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)
        ->put(route('rh.expedientes.datos-laborales.update', $colaborador->colaborador_id), [
            'sucursal_principal_id' => $sucursal->id,
            'puesto_id' => $puesto->id,
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('toast', fn (array $toast) => $toast['type'] === 'warning');

    expect($colaborador->colaborador->fresh()->sueldo_mensual)->toBeNull();
});
