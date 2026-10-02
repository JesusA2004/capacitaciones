<?php

use App\Models\Colaborador;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\Organigrama\JefeDirectoService;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    config(['organigrama.puestos_raiz_sucursal' => ['Gerente de sucursal']]);
    Sucursal::query()->update(['activo' => false]);

    $this->director = Puesto::factory()->create(['nombre' => 'Director comercial', 'nivel_jerarquico' => 2]);
    $this->gerente = Puesto::factory()->create(['nombre' => 'Gerente de sucursal', 'nivel_jerarquico' => 4, 'puesto_superior_id' => $this->director->id]);
    $this->subgerente = Puesto::factory()->create(['nombre' => 'Subgerente', 'nivel_jerarquico' => 5, 'puesto_superior_id' => $this->gerente->id]);
    $this->gestor = Puesto::factory()->create(['nombre' => 'Gestor de crédito', 'nivel_jerarquico' => 6, 'puesto_superior_id' => $this->subgerente->id]);

    $this->norte = Sucursal::factory()->create(['nombre' => 'Norte']);
    $this->sur = Sucursal::factory()->create(['nombre' => 'Sur']);
});

function personaJefe(string $nombre, Puesto $puesto, Sucursal $sucursal, ?int $jefeId = null): Colaborador
{
    return Colaborador::factory()->create([
        'name' => $nombre,
        'apellidos' => null,
        'puesto_id' => $puesto->id,
        'sucursal_principal_id' => $sucursal->id,
        'estatus' => 'activo',
        'jefe_id' => $jefeId,
    ]);
}

test('el jefe directo sale del organigrama: superior de su sucursal y, si está vacante, el siguiente hacia arriba', function () {
    $dir = personaJefe('Dirección', $this->director, $this->norte);
    $gerenteNorte = personaJefe('Gerente Norte', $this->gerente, $this->norte);
    $gerenteSur = personaJefe('Gerente Sur', $this->gerente, $this->sur);
    $subNorte = personaJefe('Sub Norte', $this->subgerente, $this->norte);
    // Jefe capturado a mano y equivocado: se corrige solo.
    $gestorNorte = personaJefe('Gestor Norte', $this->gestor, $this->norte, $gerenteSur->id);
    // Sur no tiene subgerente: su gestor reporta al gerente de Sur.
    $gestorSur = personaJefe('Gestor Sur', $this->gestor, $this->sur);

    $resultado = app(JefeDirectoService::class)->sincronizar();

    expect($dir->fresh()->jefe_id)->toBeNull()
        ->and($gerenteNorte->fresh()->jefe_id)->toBe($dir->id)
        ->and($gerenteSur->fresh()->jefe_id)->toBe($dir->id)
        ->and($subNorte->fresh()->jefe_id)->toBe($gerenteNorte->id)
        ->and($gestorNorte->fresh()->jefe_id)->toBe($subNorte->id)
        ->and($gestorSur->fresh()->jefe_id)->toBe($gerenteSur->id)
        ->and($resultado['sin_jefe'])->toBe(1);

    $this->assertDatabaseHas('activity_log', ['description' => 'jefe_directo_cambiado', 'subject_id' => $gestorNorte->id]);

    // Idempotente: una segunda corrida no cambia nada.
    expect(app(JefeDirectoService::class)->sincronizar()['actualizados'])->toBe(0);
});

test('al cambiar a alguien de puesto desde su expediente, su jefe se recalcula solo', function () {
    $rh = clUsuario('rh_admin');
    $dir = personaJefe('Dirección', $this->director, $this->norte);
    $gerenteNorte = personaJefe('Gerente Norte', $this->gerente, $this->norte);
    $subNorte = personaJefe('Sub Norte', $this->subgerente, $this->norte);
    $persona = personaJefe('Promovida', $this->gestor, $this->norte);
    app(JefeDirectoService::class)->sincronizar();
    expect($persona->fresh()->jefe_id)->toBe($subNorte->id);

    // Sube a subgerente: ahora reporta al gerente.
    $this->actingAs($rh)
        ->put(route('rh.expedientes.datos-laborales.update', $persona), [
            'sucursal_principal_id' => $this->norte->id,
            'puesto_id' => $this->subgerente->id,
            'motivo' => 'Promoción',
        ])
        ->assertSessionHasNoErrors();

    expect($persona->fresh()->jefe_id)->toBe($gerenteNorte->id);
});

test('el jefe enviado a mano en datos laborales se ignora: manda el organigrama', function () {
    $rh = clUsuario('rh_admin');
    $dir = personaJefe('Dirección', $this->director, $this->norte);
    $gerenteNorte = personaJefe('Gerente Norte', $this->gerente, $this->norte);
    $persona = personaJefe('Sub', $this->subgerente, $this->norte);

    $this->actingAs($rh)
        ->put(route('rh.expedientes.datos-laborales.update', $persona), [
            'sucursal_principal_id' => $this->norte->id,
            'puesto_id' => $this->subgerente->id,
            'jefe_id' => $dir->id,
        ])
        ->assertSessionHasNoErrors();

    expect($persona->fresh()->jefe_id)->toBe($gerenteNorte->id);
});

test('la pantalla manual de jefes directos ya no existe', function () {
    $this->actingAs(clUsuario('rh_admin'))
        ->get('/administracion/configuracion/jerarquia')
        ->assertNotFound();
});
