<?php

use App\Enums\EstadoCandidato;
use App\Enums\EstadoVacante;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\HeadcountTarget;
use App\Models\HeadcountTargetHistorial;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vacante;
use App\Services\Headcount\HeadcountService;
use App\Services\Headcount\PlantillaAutorizadaService;
use App\Services\Headcount\PlazasEmpresaService;
use App\Services\Puestos\EliminacionPuestoService;
use App\Services\Vacantes\VacanteAutoGenerationService;
use App\Services\Vacantes\VacantesListadoService;
use Database\Seeders\RolesYPermisosSeeder;

/*
| Eliminar un puesto (SoftDeletes) nunca deja plazas ni vacantes vivas:
| caso real, un puesto corporativo sin nadie recibió su plaza automática en
| Corporativo y, ya eliminado, RH → Vacantes seguía mostrando «Puesto sin
| definir · 1 plaza por cubrir · 0 de 1 ocupadas».
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $empresa = Empresa::factory()->create();
    $this->corporativo = Sucursal::factory()->create(['empresa_id' => $empresa->id, 'es_corporativo' => true, 'activo' => true]);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');

    // Puesto de empresa sin titular: la regla de plazas le abre 1 plaza y su vacante.
    $this->puesto = Puesto::factory()->create(['nombre' => 'Analista de Proyectos', 'activo' => true, 'puesto_superior_id' => null]);
    app(PlazasEmpresaService::class)->asegurar();

    $this->vacante = Vacante::query()->where('puesto_id', $this->puesto->id)->firstOrFail();

    $this->listadoActivo = fn (): array => app(VacantesListadoService::class)->filas(
        app(VacantesListadoService::class)->consulta($this->admin)->get(),
    );
});

test('el escenario de partida tiene plaza y vacante automática abiertas', function () {
    expect($this->vacante->estado)->toBe(EstadoVacante::Abierta)
        ->and($this->vacante->generada_automaticamente)->toBeTrue()
        ->and(collect(($this->listadoActivo)())->pluck('id'))->toContain($this->vacante->id);
});

test('eliminar un puesto sin nadie cancela su vacante, libera su plaza y no deja «Puesto sin definir»', function () {
    $this->actingAs($this->admin)
        ->delete(route('administracion.puestos.destroy', $this->puesto))
        ->assertRedirect(route('administracion.puestos.index'))
        ->assertSessionHasNoErrors();

    expect(Puesto::withTrashed()->find($this->puesto->id)->trashed())->toBeTrue();

    $vacante = $this->vacante->fresh();
    expect($vacante->estado)->toBe(EstadoVacante::Cancelada)
        ->and($vacante->motivo_cancelacion)->toContain('Analista de Proyectos')
        ->and($vacante->plazas_disponibles)->toBe(0);

    $target = HeadcountTarget::query()->where('puesto_id', $this->puesto->id)->firstOrFail();
    expect($target->plantilla_autorizada)->toBe(0);
    expect(HeadcountTargetHistorial::query()->where('puesto_id', $this->puesto->id)->where('fuente', EliminacionPuestoService::FUENTE_PLANTILLA)->where('valor_nuevo', 0)->exists())->toBeTrue();

    expect(($this->listadoActivo)())->toBe([]);

    // Los procesos diarios no la recrean.
    app(PlazasEmpresaService::class)->asegurar();
    app(VacanteAutoGenerationService::class)->sincronizarTodo();
    expect(Vacante::query()->where('puesto_id', $this->puesto->id)->whereIn('estado', EstadoVacante::valoresAbiertos())->exists())->toBeFalse();

    // El historial conserva el nombre del puesto.
    $canceladas = app(VacantesListadoService::class)->filas(
        app(VacantesListadoService::class)->consulta($this->admin, ['estado' => EstadoVacante::Cancelada->value])->get(),
    );
    expect(collect($canceladas)->firstWhere('id', $this->vacante->id)['puesto'])->toBe('Analista de Proyectos (puesto eliminado)');
});

test('no se elimina un puesto que tiene puestos que le reportan en el organigrama', function () {
    Puesto::factory()->create(['nombre' => 'Auxiliar de Proyectos', 'puesto_superior_id' => $this->puesto->id, 'activo' => true]);

    $this->actingAs($this->admin)
        ->from(route('administracion.puestos.show', $this->puesto))
        ->delete(route('administracion.puestos.destroy', $this->puesto))
        ->assertSessionHasErrors(['puesto' => 'No se puede eliminar «Analista de Proyectos»: En el organigrama le reportan: Auxiliar de Proyectos. Cambia primero a quién reportan (Organigrama → Por puestos).']);

    expect($this->puesto->fresh()->trashed())->toBeFalse()
        ->and($this->vacante->fresh()->estado)->toBe(EstadoVacante::Abierta);
});

test('no se elimina un puesto con personas vigentes', function () {
    Colaborador::factory()->create(['puesto_id' => $this->puesto->id, 'sucursal_principal_id' => $this->corporativo->id, 'estatus' => 'activo']);

    $this->actingAs($this->admin)
        ->delete(route('administracion.puestos.destroy', $this->puesto))
        ->assertSessionHasErrors('puesto');

    expect($this->puesto->fresh()->trashed())->toBeFalse();
});

test('no se elimina un puesto cuya vacante tiene candidatos en proceso', function () {
    Candidato::factory()->create(['vacante_id' => $this->vacante->id, 'estado' => EstadoCandidato::EntrevistaPendiente->value]);

    $this->actingAs($this->admin)
        ->delete(route('administracion.puestos.destroy', $this->puesto))
        ->assertSessionHasErrors('puesto');

    expect($this->puesto->fresh()->trashed())->toBeFalse()
        ->and($this->vacante->fresh()->estado)->toBe(EstadoVacante::Abierta);
});

test('un puesto eliminado antes de la regla deja de contar y el comando de reparación lo cierra de forma idempotente', function () {
    // Como antes del arreglo: borrado lógico directo, sin liberar nada.
    $this->puesto->delete();

    // Defensa en vivo: el listado ya no la muestra ni el headcount la cuenta.
    expect(($this->listadoActivo)())->toBe([])
        ->and(app(HeadcountService::class)->plantillaDePar($this->corporativo->id, $this->puesto->id)['autorizada'])->toBe(0);

    $this->artisan('people:reparar-puestos-eliminados', ['--simular' => true])->assertSuccessful();
    expect($this->vacante->fresh()->estado)->toBe(EstadoVacante::Abierta);

    $this->artisan('people:reparar-puestos-eliminados')->assertSuccessful();
    expect($this->vacante->fresh()->estado)->toBe(EstadoVacante::Cancelada)
        ->and(HeadcountTarget::query()->where('puesto_id', $this->puesto->id)->value('plantilla_autorizada'))->toBe(0);

    $historial = HeadcountTargetHistorial::query()->where('puesto_id', $this->puesto->id)->count();
    $this->artisan('people:reparar-puestos-eliminados')
        ->expectsOutputToContain('Nada que reparar')
        ->assertSuccessful();
    expect(HeadcountTargetHistorial::query()->where('puesto_id', $this->puesto->id)->count())->toBe($historial);
});

test('la sincronización diaria cierra la automática de un puesto eliminado con su motivo', function () {
    $this->puesto->delete();

    app(VacanteAutoGenerationService::class)->sincronizarTodo();

    expect($this->vacante->fresh()->estado)->toBe(EstadoVacante::Cancelada)
        ->and($this->vacante->fresh()->motivo_cancelacion)->toBe('Cerrada automáticamente: el puesto se eliminó del catálogo.');
});

test('cambiar las plazas de un puesto abre y cierra su vacante automática', function () {
    $sucursal = Sucursal::factory()->create(['empresa_id' => $this->corporativo->empresa_id, 'activo' => true]);
    $raiz = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal', 'activo' => true]);
    config(['organigrama.puestos_raiz_sucursal' => ['Gerente de Sucursal']]);
    $plantilla = app(PlantillaAutorizadaService::class);

    $plantilla->actualizar($sucursal, $raiz, 2, 'Crecimiento', $this->admin);
    $vacante = Vacante::query()->where('puesto_id', $raiz->id)->where('sucursal_id', $sucursal->id)->firstOrFail();
    expect($vacante->estado)->toBe(EstadoVacante::Abierta)->and($vacante->plazas_disponibles)->toBe(2);

    $plantilla->actualizar($sucursal, $raiz, 0, 'Ajuste', $this->admin);
    expect($vacante->fresh()->estado)->toBe(EstadoVacante::Cancelada);
});
