<?php

use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\Puesto;
use App\Services\Colaboradores\FotoColaboradorService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Evaluaciones de periodo de prueba y ficha del ciclo laboral muestran la
| fotografía oficial del expediente (URL protegida rh.expedientes.foto,
| dentro del alcance); sin foto → iniciales; nunca `foto_path`.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake(config('expedientes.disk'));
    Notification::fake();
    $estructura = clEstructura();
    $sucursal = $estructura['sucursal']->id;
    $hoy = CarbonImmutable::now('America/Mexico_City')->startOfDay();

    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $sucursal]);
    $puesto = Puesto::factory()->create(['nombre' => 'Gestor', 'meses_periodo_prueba' => 2]);
    $ingreso = $hoy->subDays(50);

    $crear = function () use ($puesto, $sucursal, $ingreso): Colaborador {
        $colaborador = Colaborador::factory()->create([
            'estatus' => 'activo',
            'estado_alta' => null,
            'puesto_id' => $puesto->id,
            'sucursal_principal_id' => $sucursal,
            'fecha_ingreso' => $ingreso->toDateString(),
        ]);
        ContratoLaboral::factory()->create([
            'colaborador_id' => $colaborador->id,
            'fecha_inicio' => $ingreso->toDateString(),
            'fecha_fin' => $ingreso->addMonthsNoOverflow(2)->subDay()->toDateString(),
        ]);

        return $colaborador;
    };

    $this->conFoto = $crear();
    $ruta = sprintf('expedientes/%d/foto/perfil.jpg', $this->conFoto->id);
    Storage::disk(config('expedientes.disk'))->put($ruta, 'jpeg-falso');
    $this->conFoto->update(['foto_path' => $ruta]);

    $this->sinFoto = $crear();
});

test('Próximas / por asignar trae la foto oficial a quien la tiene e iniciales (null) a quien no', function () {
    $esperada = app(FotoColaboradorService::class)->url($this->conFoto->refresh());
    $filas = null;

    $this->actingAs($this->rh)
        ->get(route('rh.evaluaciones.index'))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$filas) {
            $filas = collect($page->toArray()['props']['porAsignar']['data'])->keyBy('id');

            return $page;
        });

    expect($filas->get($this->conFoto->id)['foto_url'] ?? null)->toBe($esperada)
        ->and($esperada)->toStartWith(route('rh.expedientes.foto', $this->conFoto->id))
        ->and($filas->get($this->sinFoto->id))->toHaveKey('foto_url', null);
});

test('la ficha del ciclo laboral trae la foto oficial en el encabezado', function () {
    $this->actingAs($this->rh)
        ->get(route('rh.colaboradores.ciclo', $this->conFoto->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('colaborador.foto_url', app(FotoColaboradorService::class)->url($this->conFoto->refresh())));

    $this->actingAs($this->rh)
        ->get(route('rh.colaboradores.ciclo', $this->sinFoto->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('colaborador.foto_url', null));
});

test('nunca se expone foto_path (ruta física del NAS) en Evaluaciones ni en el ciclo laboral', function () {
    $ruta = (string) $this->conFoto->refresh()->foto_path;

    foreach ([route('rh.evaluaciones.index'), route('rh.colaboradores.ciclo', $this->conFoto->id)] as $url) {
        $html = $this->actingAs($this->rh)->get($url)->assertOk()->getContent();

        expect($html)->not->toContain('foto_path')
            ->and($html)->not->toContain($ruta)
            ->and($html)->not->toContain(str_replace('/', '\\/', $ruta));
    }
});
