<?php

use App\Models\Colaborador;
use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Headcount\HeadcountService;
use App\Services\Reportes\ReportesRhService;
use App\Services\Reportes\TableroRhService;
use Database\Seeders\RolesYPermisosSeeder;

/*
| Etapa 1, plantilla única: Tablero, Directorio y Reportes muestran las mismas
| cifras de HeadcountService::poblacion(), y nadie se oculta para cuadrarlas.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->corporativo = Sucursal::factory()->create(['nombre' => 'Corporativo', 'activo' => true, 'es_corporativo' => true]);
    $this->tula = Sucursal::factory()->create(['nombre' => 'Tula', 'activo' => true, 'es_corporativo' => false]);
    $this->gestor = Puesto::factory()->create(['nombre' => 'Gestor', 'activo' => true]);
    $this->analista = Puesto::factory()->create(['nombre' => 'Analista', 'activo' => true]);
    $this->sinPlaza = Puesto::factory()->create(['nombre' => 'Auxiliar', 'activo' => true]);

    HeadcountTarget::factory()->create(['sucursal_id' => $this->tula->id, 'puesto_id' => $this->gestor->id, 'plantilla_autorizada' => 3]);
    HeadcountTarget::factory()->create(['sucursal_id' => $this->corporativo->id, 'puesto_id' => $this->analista->id, 'plantilla_autorizada' => 2]);

    $crear = fn (int $n, Sucursal $s, Puesto $p, string $estatus = 'activo') => Colaborador::factory()->count($n)->create(['sucursal_principal_id' => $s->id, 'puesto_id' => $p->id, 'estatus' => $estatus]);

    $crear(1, $this->tula, $this->gestor);              // 1 activo.
    $crear(1, $this->tula, $this->gestor, 'en_incorporacion');  // Ocupa plaza; no es activo.
    $crear(1, $this->tula, $this->sinPlaza);            // Fuera de plantilla.
    $crear(3, $this->corporativo, $this->analista);     // 2 plazas: 1 excedente.
    $crear(2, $this->tula, $this->gestor, 'inactivo');  // Una baja nunca cuenta.

    $this->rh = User::factory()->create();
    $this->rh->assignRole('rh_admin');
    // La cuenta de prueba trae una persona sin sucursal: no es parte del escenario.
    Colaborador::query()->whereNull('sucursal_principal_id')->update(['estatus' => 'inactivo']);
});

test('concilia cada cifra por par sin sobrecontar excedentes', function () {
    $p = app(HeadcountService::class)->poblacion();

    expect($p['total'])->toMatchArray([
        'autorizadas' => 5, 'ocupadas' => 4, 'vacantes' => 1, 'excedentes' => 1,
        'fuera_de_plantilla' => 1, 'sin_ubicacion' => 0, 'activos' => 5, 'en_incorporacion' => 1,
        'vigentes' => 6, 'cobertura' => 80.0,
    ])
        ->and($p['corporativo'])->toMatchArray(['autorizadas' => 2, 'ocupadas' => 2, 'vacantes' => 0, 'excedentes' => 1, 'activos' => 3, 'cobertura' => 100.0])
        ->and($p['sucursales'])->toMatchArray(['autorizadas' => 3, 'ocupadas' => 2, 'vacantes' => 1, 'fuera_de_plantilla' => 1, 'activos' => 2]);
});

test('se cumplen los invariantes de conciliación en cada segmento', function () {
    // Sin sucursal y sin puesto: sigue contando, en «sin ubicación».
    Colaborador::factory()->create(['sucursal_principal_id' => null, 'puesto_id' => $this->gestor->id, 'estatus' => 'activo']);
    Colaborador::factory()->create(['sucursal_principal_id' => $this->tula->id, 'puesto_id' => null, 'estatus' => 'activo']);

    $p = app(HeadcountService::class)->poblacion();

    expect($p['total']['sin_ubicacion'])->toBe(2);

    foreach ($p as $s) {
        expect($s['vigentes'])->toBe($s['activos'] + $s['en_incorporacion'])
            ->and($s['vigentes'])->toBe($s['ocupadas'] + $s['excedentes'] + $s['fuera_de_plantilla'] + $s['sin_ubicacion'])
            ->and($s['autorizadas'])->toBe($s['ocupadas'] + $s['vacantes']);
    }

    foreach (['autorizadas', 'ocupadas', 'vacantes', 'activos', 'vigentes', 'excedentes', 'fuera_de_plantilla', 'sin_ubicacion'] as $campo) {
        expect($p['total'][$campo])->toBe($p['corporativo'][$campo] + $p['sucursales'][$campo]);
    }
});

test('una sucursal sin plazas autorizadas no divide entre cero y conserva a su gente', function () {
    $nueva = Sucursal::factory()->create(['nombre' => 'Nueva', 'activo' => true]);
    Colaborador::factory()->create(['sucursal_principal_id' => $nueva->id, 'puesto_id' => $this->gestor->id, 'estatus' => 'activo']);

    $p = app(HeadcountService::class)->poblacion(collect([$nueva->id]));

    expect($p['total'])->toMatchArray(['autorizadas' => 0, 'cobertura' => 0.0, 'activos' => 1, 'fuera_de_plantilla' => 1]);
});

test('sin ningún dato todas las cifras son cero', function () {
    $vacia = Sucursal::factory()->create(['nombre' => 'Vacía', 'activo' => true]);

    expect(app(HeadcountService::class)->poblacion(collect([$vacia->id]))['total'])
        ->toMatchArray(['autorizadas' => 0, 'vigentes' => 0, 'cobertura' => 0.0]);
});

test('Tablero, Directorio y Reportes muestran las mismas cifras', function () {
    $p = app(HeadcountService::class)->poblacion();

    $tablero = app(TableroRhService::class)->construir($this->rh)['summary']['plantilla_activa']['poblacion'];
    expect($tablero)->toBe($p);

    $this->actingAs($this->rh)->get(route('directorio.index'))->assertOk()
        ->assertInertia(function ($page) use ($p) {
            $filas = collect($page->toArray()['props']['colaboradores']);

            expect($filas)->toHaveCount($p['total']['activos'])
                ->and($filas->where('es_corporativo', true))->toHaveCount($p['corporativo']['activos'])
                ->and($filas->where('es_corporativo', false))->toHaveCount($p['sucursales']['activos']);
        });

    $reporte = app(ReportesRhService::class)->generar('plantilla_conciliacion', $this->rh, []);
    $total = collect($reporte['filas'])->firstWhere(0, 'Total');
    $corporativo = collect($reporte['filas'])->firstWhere(0, 'Subtotal Corporativo');

    expect($total[1])->toBe($p['total']['autorizadas'])
        ->and($total[2])->toBe($p['total']['activos'])
        ->and($total[5])->toBe($p['total']['vacantes'])
        ->and($total[9])->toBe($p['total']['cobertura'])
        ->and($corporativo[2])->toBe($p['corporativo']['activos']);

    $empleados = app(ReportesRhService::class)->generar('empleados_total', $this->rh, []);
    expect(collect($empleados['filas'])->firstWhere(0, 'Activos')[1])->toBe($p['total']['activos']);
});

test('el filtro de sucursal del tablero acota la población', function () {
    $tablero = app(TableroRhService::class)->construir($this->rh, ['sucursal_id' => $this->tula->id])['summary']['plantilla_activa']['poblacion'];

    expect($tablero['total']['autorizadas'])->toBe(3)
        ->and($tablero['corporativo']['activos'])->toBe(0);
});

test('un cambio de puesto o de sucursal mueve a la persona sin duplicarla', function () {
    $persona = Colaborador::query()->where('puesto_id', $this->sinPlaza->id)->firstOrFail();
    $persona->update(['puesto_id' => $this->gestor->id]);

    $tras = app(HeadcountService::class)->poblacion();
    expect($tras['total'])->toMatchArray(['fuera_de_plantilla' => 0, 'ocupadas' => 5, 'vacantes' => 0, 'activos' => 5]);

    $persona->update(['sucursal_principal_id' => $this->corporativo->id, 'puesto_id' => $this->analista->id]);

    $final = app(HeadcountService::class)->poblacion();
    expect($final['total']['activos'])->toBe(5)
        ->and($final['corporativo']['activos'])->toBe(4)
        ->and($final['corporativo']['excedentes'])->toBe(2);
});
