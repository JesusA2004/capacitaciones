<?php

use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\MovimientoLaboral;
use App\Models\Puesto;
use App\Models\Sucursal;
use Database\Seeders\RolesYPermisosSeeder;

/*
| Edición rápida del Directorio: solo `directorio.editar` (super_admin),
| nunca la propia fila, con las mismas reglas de validación que el
| expediente y la misma escritura (DatosExpedienteService).
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);

    $this->persona = Colaborador::factory()->create([
        'name' => 'Lucía', 'apellidos' => 'Mendoza Ríos',
        'estatus' => 'activo',
        'sucursal_principal_id' => Sucursal::factory()->create(['activo' => true])->id,
        'correo_corporativo' => 'lucia.vieja@mrlana.test',
        'telefono_corporativo' => '7770000000',
    ]);
});

test('super_admin edita la fila de otra persona y se guarda con el mismo service del expediente', function () {
    $admin = clUsuario('super_admin', ['estatus' => 'activo']);
    $sucursal = Sucursal::factory()->create(['activo' => true]);
    $departamento = Departamento::factory()->create();
    $puesto = Puesto::factory()->create(['activo' => true]);

    $this->actingAs($admin)
        ->patchJson(route('directorio.actualizar', $this->persona), [
            'correo_corporativo' => 'lucia.mendoza@mrlana.test',
            'telefono_corporativo' => '7771234567',
            'sucursal_principal_id' => $sucursal->id,
            'departamento_id' => $departamento->id,
            'puesto_id' => $puesto->id,
        ])
        ->assertOk()
        ->assertJsonPath('mensaje', 'Cambios guardados.');

    $this->persona->refresh();
    expect($this->persona->correo_corporativo)->toBe('lucia.mendoza@mrlana.test')
        ->and($this->persona->telefono_corporativo)->toBe('7771234567')
        ->and($this->persona->sucursal_principal_id)->toBe($sucursal->id)
        ->and($this->persona->departamento_id)->toBe($departamento->id)
        ->and($this->persona->puesto_id)->toBe($puesto->id);

    // Auditable igual que en el expediente: queda el movimiento laboral.
    expect(MovimientoLaboral::query()->where('colaborador_id', $this->persona->id)->exists())->toBeTrue();
});

test('el directorio solo manda los controles de edición a quien puede editar', function () {
    $admin = clUsuario('super_admin', ['estatus' => 'activo']);

    $this->actingAs($admin)->get(route('directorio.index'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('edicion.editables', fn ($ids) => collect($ids)->contains($this->persona->id)
                && ! collect($ids)->contains($admin->colaborador_id)));

    $this->actingAs(clUsuario('rh_admin', ['estatus' => 'activo']))->get(route('directorio.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('edicion', null));
});

test('nadie edita su propia fila, ni super_admin', function () {
    $admin = clUsuario('super_admin', ['estatus' => 'activo']);

    $this->actingAs($admin)
        ->patchJson(route('directorio.actualizar', $admin->colaborador_id), ['telefono_corporativo' => '7779999999'])
        ->assertForbidden();
});

test('rh_admin y colaborador sin directorio.editar reciben 403', function (string $rol) {
    $this->actingAs(clUsuario($rol, ['estatus' => 'activo']))
        ->patchJson(route('directorio.actualizar', $this->persona), ['telefono_corporativo' => '7779999999'])
        ->assertForbidden();

    expect($this->persona->refresh()->telefono_corporativo)->toBe('7770000000');
})->with(['rh_admin', 'colaborador']);

test('datos inválidos responden 422 con las reglas del expediente', function () {
    $this->actingAs(clUsuario('super_admin', ['estatus' => 'activo']))
        ->patchJson(route('directorio.actualizar', $this->persona), [
            'correo_corporativo' => 'no-es-correo',
            'puesto_id' => 999999,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['correo_corporativo', 'puesto_id']);

    expect($this->persona->refresh()->correo_corporativo)->toBe('lucia.vieja@mrlana.test');
});
