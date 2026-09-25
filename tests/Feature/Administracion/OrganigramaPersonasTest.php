<?php

use App\Enums\TipoAsignacionNodoComercial;
use App\Models\AsignacionNodoComercial;
use App\Models\Colaborador;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    config(['organigrama.puestos_raiz_sucursal' => ['Gerente de sucursal']]);
    Sucursal::query()->update(['activo' => false]);
});

/**
 * @return array<string, array<string, mixed>>
 */
function organigramaPorPersonas(User $usuario): array
{
    $respuesta = test()->actingAs($usuario)
        ->get(route('administracion.jerarquia-puestos.index'))
        ->assertOk();

    $nodos = [];

    $respuesta->assertInertia(function ($page) use (&$nodos) {
        $nodos = collect($page->toArray()['props']['personas'])->keyBy('clave')->all();
    });

    return $nodos;
}

function persona(string $nombre, Puesto $puesto, Sucursal $sucursal): Colaborador
{
    return Colaborador::factory()->create([
        'name' => $nombre,
        'apellidos' => null,
        'puesto_id' => $puesto->id,
        'sucursal_principal_id' => $sucursal->id,
        'estatus' => 'activo',
        'jefe_id' => null,
    ]);
}

function asignarRuta(Colaborador $colaborador, NodoComercial $ruta, TipoAsignacionNodoComercial $tipo): void
{
    AsignacionNodoComercial::factory()->create([
        'user_id' => null,
        'colaborador_id' => $colaborador->id,
        'nodo_comercial_id' => $ruta->id,
        'tipo_asignacion' => $tipo->value,
    ]);
}

test('cada sucursal es su propia rama: una tarjeta por persona, gerente → subgerente → gestor → volante', function () {
    $director = Puesto::factory()->create(['nombre' => 'Director comercial', 'nivel_jerarquico' => 2]);
    $gerente = Puesto::factory()->create(['nombre' => 'Gerente de sucursal', 'nivel_jerarquico' => 4, 'puesto_superior_id' => $director->id]);
    $subgerente = Puesto::factory()->create(['nombre' => 'Subgerente', 'nivel_jerarquico' => 5, 'puesto_superior_id' => $gerente->id]);
    $gestor = Puesto::factory()->create(['nombre' => 'Gestor de crédito', 'nivel_jerarquico' => 6, 'puesto_superior_id' => $subgerente->id]);
    $volante = Puesto::factory()->create(['nombre' => 'Volante', 'nivel_jerarquico' => 7, 'puesto_superior_id' => $gestor->id]);

    $norte = Sucursal::factory()->create(['nombre' => 'Norte']);
    $sur = Sucursal::factory()->create(['nombre' => 'Sur']);
    $vacia = Sucursal::factory()->create(['nombre' => 'Oriente']);

    $dir = persona('Dirección', $director, $norte);
    $gerenteNorte = persona('Gerente Norte', $gerente, $norte);
    $gerenteSur = persona('Gerente Sur', $gerente, $sur);
    $subNorte = persona('Sub Norte', $subgerente, $norte);
    $gestorUno = persona('Gestor Uno', $gestor, $norte);
    $gestorDos = persona('Gestor Dos', $gestor, $norte);
    $volanteNorte = persona('Volante Norte', $volante, $norte);
    $gestorSur = persona('Gestor Sur', $gestor, $sur);

    $rutaUno = NodoComercial::factory()->create(['nombre' => 'RUTA UNO']);
    $rutaDos = NodoComercial::factory()->create(['nombre' => 'RUTA DOS']);
    asignarRuta($gestorUno, $rutaUno, TipoAsignacionNodoComercial::Gestor);
    asignarRuta($gestorDos, $rutaDos, TipoAsignacionNodoComercial::Gestor);
    asignarRuta($volanteNorte, $rutaDos, TipoAsignacionNodoComercial::Volante);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $nodos = organigramaPorPersonas($admin);

    // Una tarjeta por gerente, cada uno colgando del director.
    expect($nodos["p{$gerenteNorte->id}"]['padre'])->toBe("p{$dir->id}")
        ->and($nodos["p{$gerenteSur->id}"]['padre'])->toBe("p{$dir->id}");

    // Norte: gerente → subgerente → gestores; el volante va con el gestor de SU ruta.
    expect($nodos["p{$subNorte->id}"]['padre'])->toBe("p{$gerenteNorte->id}")
        ->and($nodos["p{$gestorUno->id}"]['padre'])->toBe("p{$subNorte->id}")
        ->and($nodos["p{$gestorDos->id}"]['padre'])->toBe("p{$subNorte->id}")
        ->and($nodos["p{$volanteNorte->id}"]['padre'])->toBe("p{$gestorDos->id}")
        ->and($nodos["p{$gestorUno->id}"]['rutas'][0])->toBe(['nombre' => 'RUTA UNO', 'tipo' => 'gestor']);

    // Sur no tiene subgerente: el gestor NO cuelga del subgerente de Norte,
    // sino de un "Subgerente sin ocupar" de Sur, bajo el gerente de Sur.
    $vacanteSur = "v{$subgerente->id}-s{$sur->id}";
    expect($nodos["p{$gestorSur->id}"]['padre'])->toBe($vacanteSur)
        ->and($nodos[$vacanteSur]['tipo'])->toBe('vacante')
        ->and($nodos[$vacanteSur]['padre'])->toBe("p{$gerenteSur->id}");

    // Una sucursal sin personal aparece igual, con su gerente "sin ocupar".
    $vacanteOriente = "v{$gerente->id}-s{$vacia->id}";
    expect($nodos)->toHaveKey($vacanteOriente)
        ->and($nodos[$vacanteOriente]['sucursal']['nombre'])->toBe('Oriente')
        ->and($nodos[$vacanteOriente]['padre'])->toBe("p{$dir->id}");
});

test('el organigrama por personas nunca expone la ruta fisica de la foto', function () {
    $puesto = Puesto::factory()->create(['nivel_jerarquico' => 2]);
    $sucursal = Sucursal::factory()->create();
    Colaborador::factory()->create([
        'puesto_id' => $puesto->id,
        'sucursal_principal_id' => $sucursal->id,
        'estatus' => 'activo',
        'foto_path' => 'expedientes/9/foto/privada.jpg',
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    expect(json_encode(organigramaPorPersonas($admin)))->not->toContain('privada.jpg');
});
