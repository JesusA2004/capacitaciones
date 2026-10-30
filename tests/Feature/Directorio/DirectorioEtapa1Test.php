<?php

use App\Models\Colaborador;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\Directorio\DirectorioColaboradoresService;
use Database\Seeders\RolesYPermisosSeeder;

/*
| Directorio, propuesta Etapa 1 pág. 5: quien no tenga correo o teléfono aparece
| igual con «Datos por completar», y las exportaciones respetan permisos.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    $this->tula = Sucursal::factory()->create(['nombre' => 'Tula', 'activo' => true]);
    $this->gestor = Puesto::factory()->create(['nombre' => 'Gestor', 'grupo_documental' => 'gestor', 'activo' => true]);
    $this->base = ['puesto_id' => $this->gestor->id, 'sucursal_principal_id' => $this->tula->id, 'estatus' => 'activo'];
    $this->completo = Colaborador::factory()->create([...$this->base, 'name' => 'Ana', 'apellidos' => 'Completa', 'correo_corporativo' => 'ana@mrlana.test', 'telefono_corporativo' => '5550001111', 'curp' => 'CURPSENSIBLE000001']);
    $this->sinTelefono = Colaborador::factory()->create([...$this->base, 'name' => 'Beto', 'apellidos' => 'SinTelefono', 'correo_corporativo' => 'beto@mrlana.test', 'telefono_corporativo' => null]);
    $this->sinNada = Colaborador::factory()->create([...$this->base, 'name' => 'Carla', 'apellidos' => 'SinNada', 'correo_corporativo' => null, 'telefono_corporativo' => null]);
});

test('falta correo o teléfono: aparece con Datos por completar, nunca se oculta', function () {
    $this->actingAs(clUsuario('rh_admin'))->get(route('directorio.index'))->assertOk()
        ->assertInertia(function ($page) {
            $filas = collect($page->toArray()['props']['colaboradores'])->keyBy('id');

            expect($filas[$this->completo->id]['datos_por_completar'])->toBeFalse()
                ->and($filas[$this->sinTelefono->id]['datos_por_completar'])->toBeTrue()
                ->and($filas[$this->sinNada->id]['datos_por_completar'])->toBeTrue();
        });
});

test('sin sucursal también aparece y queda por completar', function () {
    $sinSucursal = Colaborador::factory()->create(['estatus' => 'activo', 'puesto_id' => $this->gestor->id, 'sucursal_principal_id' => null, 'correo_corporativo' => 'x@mrlana.test', 'telefono_corporativo' => '5550002222']);

    $fila = app(DirectorioColaboradoresService::class)->aArray($sinSucursal->load(['puesto', 'sucursalPrincipal', 'departamento']), clUsuario('rh_admin'));

    expect($fila['datos_por_completar'])->toBeTrue();
});

test('el filtro de datos por completar de la API funciona para RH y se ignora sin permiso', function () {
    $idsRh = collect($this->actingAs(clUsuario('rh_admin'), 'sanctum')->getJson('/api/v1/directorio?solo_por_completar=1&per_page=50')->assertOk()->json('data'))->pluck('id');

    expect($idsRh)->toContain($this->sinNada->id)->toContain($this->sinTelefono->id)->not->toContain($this->completo->id);

    $idsOperativo = collect($this->actingAs(clUsuario('colaborador', ['sucursal_principal_id' => $this->tula->id]), 'sanctum')->getJson('/api/v1/directorio?solo_por_completar=1&per_page=50')->assertOk()->json('data'))->pluck('id');

    expect($idsOperativo)->toContain($this->completo->id);
});

test('la exportación excluye campos sensibles y aplica filtros', function () {
    $tabla = app(DirectorioColaboradoresService::class)->tablaExportable(['solo_por_completar' => '1'], clUsuario('rh_admin'));
    $texto = json_encode($tabla);

    expect(collect($tabla['filas'])->pluck(0))->toContain('Carla SinNada')->not->toContain('Ana Completa')
        ->and($texto)->not->toContain('CURPSENSIBLE')
        ->and($tabla['columnas'])->not->toContain('CURP');
});

test('exportar Excel y PDF: RH descarga; un colaborador recibe 403', function () {
    $rh = clUsuario('rh_admin');

    expect($this->actingAs($rh)->get(route('directorio.exportar.excel'))->assertOk()->headers->get('content-disposition'))->toContain('.xlsx')
        ->and($this->actingAs($rh)->get(route('directorio.exportar.pdf'))->assertOk()->headers->get('content-type'))->toContain('pdf');

    $colaborador = clUsuario('colaborador', ['sucursal_principal_id' => $this->tula->id]);
    $this->actingAs($colaborador)->get(route('directorio.exportar.excel'))->assertForbidden();
    $this->actingAs($colaborador)->get(route('directorio.exportar.pdf'))->assertForbidden();
});

test('los que no ven todos los contactos no reciben contacto ni bandera en la pantalla', function () {
    $this->actingAs(clUsuario('colaborador', ['sucursal_principal_id' => $this->tula->id]))->get(route('directorio.index'))->assertOk()
        ->assertInertia(function ($page) {
            $props = $page->toArray()['props'];
            $fila = collect($props['colaboradores'])->firstWhere('id', $this->completo->id);

            expect($props['puedeExportar'])->toBeFalse()
                ->and($fila['correo_corporativo'])->toBeNull()
                ->and($fila['telefono_corporativo'])->toBeNull()
                ->and($fila['datos_por_completar'])->toBeNull()
                ->and($fila)->not->toHaveKey('curp');
        });
});
