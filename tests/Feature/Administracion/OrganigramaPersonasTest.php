<?php

use App\Enums\TipoAsignacionNodoComercial;
use App\Models\AsignacionNodoComercial;
use App\Models\Colaborador;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Colaboradores\FotoColaboradorService;
use App\Services\Cumpleanos\CumpleanosService;
use App\Services\Organigrama\OrganigramaPersonasService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;

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
    // requiere_ruta gatea si el nodo trae 'rutas' en la respuesta (solo
    // gestores/volantes tienen cartera, ver OrganigramaPersonasService) —
    // el default de la columna es false, así que el fixture debe marcarlo.
    $gestor = Puesto::factory()->create(['nombre' => 'Gestor de crédito', 'nivel_jerarquico' => 6, 'puesto_superior_id' => $subgerente->id, 'requiere_ruta' => true]);
    $volante = Puesto::factory()->create(['nombre' => 'Volante', 'nivel_jerarquico' => 7, 'puesto_superior_id' => $gestor->id, 'requiere_ruta' => true]);

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

test('el organigrama por personas manda la foto oficial (URL protegida) de quien la tiene, nunca foto_path', function () {
    Storage::fake(config('expedientes.disk'));
    $puesto = Puesto::factory()->create(['nivel_jerarquico' => 2]);
    $sucursal = Sucursal::factory()->create();
    $conFoto = Colaborador::factory()->create([
        'puesto_id' => $puesto->id,
        'sucursal_principal_id' => $sucursal->id,
        'estatus' => 'activo',
        'foto_path' => 'expedientes/77/foto/perfil.jpg',
    ]);
    $sinFoto = Colaborador::factory()->create([
        'puesto_id' => $puesto->id,
        'sucursal_principal_id' => $sucursal->id,
        'estatus' => 'activo',
        'foto_path' => null,
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $nodos = organigramaPorPersonas($admin);
    $porPersona = collect($nodos)->filter(fn (array $n) => isset($n['persona']['id']))->keyBy(fn (array $n) => $n['persona']['id']);

    expect($porPersona->get($conFoto->id)['persona']['foto_url'] ?? null)->toBe(app(FotoColaboradorService::class)->url($conFoto))
        ->and($porPersona->get($sinFoto->id)['persona']['foto_url'] ?? 'falta')->toBeNull()
        ->and(json_encode($nodos))->not->toContain('foto_path')
        ->and(json_encode($nodos))->not->toContain('perfil.jpg');
});

test('la foto del organigrama, cumpleaños y celebraciones solo se manda dentro del alcance de Expedientes', function () {
    $puesto = Puesto::factory()->create(['nivel_jerarquico' => 2]);
    $sucursal = Sucursal::factory()->create();
    $conFoto = Colaborador::factory()->create([
        'puesto_id' => $puesto->id,
        'sucursal_principal_id' => $sucursal->id,
        'estatus' => 'activo',
        'foto_path' => 'expedientes/78/foto/perfil.jpg',
        'fecha_nacimiento' => now()->subYears(30),
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    // Colaborador sin alcance sobre expedientes ajenos (solo el propio).
    $ajeno = clUsuario('colaborador');

    $organigrama = app(OrganigramaPersonasService::class);
    expect($organigrama->fotosVisiblesPara($admin))->toHaveKey($conFoto->id)
        ->and($organigrama->fotosVisiblesPara($ajeno))->not->toHaveKey($conFoto->id);

    $cumpleanos = app(CumpleanosService::class);
    expect($cumpleanos->tarjetaColaborador($conFoto, false, $admin)['foto_url'])->toBe(app(FotoColaboradorService::class)->url($conFoto))
        ->and($cumpleanos->tarjetaColaborador($conFoto, false, $ajeno)['foto_url'])->toBeNull();

    // Aunque arme la URL a mano, la ruta protegida la rechaza.
    $this->actingAs($ajeno)->get(route('rh.expedientes.foto', $conFoto->id))->assertForbidden();
});
