<?php

use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\Organigrama\LaminasOrganigramaService;
use Database\Seeders\RolesYPermisosSeeder;

/*
| Láminas del organigrama como el PDF de Inducción (hojas 17-19): cada área
| y puesto muestra a sus personas REALES con nombre completo; un puesto sin
| nadie queda vacante (nunca un nombre inventado) y Corporativo no aparece
| como sucursal.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('las láminas muestran a las personas reales de cada área y sucursal', function () {
    $corporativo = Sucursal::factory()->create(['nombre' => 'Corporativo', 'es_corporativo' => true, 'activo' => true]);
    $tula = Sucursal::factory()->create(['nombre' => 'Tula', 'es_corporativo' => false, 'activo' => true]);
    $sistemas = Puesto::factory()->create(['nombre' => 'Responsable de Sistemas']);
    $regional = Puesto::factory()->create(['nombre' => 'Gerente Regional Q3']);
    $gerente = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal']);
    Colaborador::factory()->create(['name' => 'Ana', 'apellidos' => 'Martínez Ruiz', 'puesto_id' => $sistemas->id, 'sucursal_principal_id' => $corporativo->id, 'estatus' => 'activo']);
    Colaborador::factory()->create(['name' => 'Mónica', 'apellidos' => 'Hernández Ortega', 'puesto_id' => $regional->id, 'sucursal_principal_id' => $tula->id, 'estatus' => 'activo']);
    Colaborador::factory()->create(['name' => 'Luis', 'apellidos' => 'Pérez Gómez', 'puesto_id' => $gerente->id, 'sucursal_principal_id' => $tula->id, 'estatus' => 'activo']);

    $laminas = $this->actingAs(clUsuario('rh_admin'))->get(route('administracion.jerarquia-puestos.index'))
        ->assertOk()->viewData('page')['props']['laminas'];

    $areas = collect($laminas['corporativo']['areas'])->keyBy('sigla');
    $regionalComercial = collect($laminas['comercial']['columnas'])->firstWhere('titulo', 'Regional Comercial');
    $sucursales = collect($laminas['sucursal']['sucursales'])->keyBy('nombre');

    expect($areas)->toHaveKeys(['DC', 'RH', 'CT', 'JE', 'SI'])
        ->and($areas['SI']['personas'][0]['nombre'])->toBe('Ana Martínez Ruiz')
        ->and($areas['JE']['personas'])->toBe([])
        // Nadie ocupa Dirección General: se usa el titular del PDF oficial.
        ->and($laminas['corporativo']['direccion_general']['personas'][0]['nombre'])->toBe('LIC. ZURIEL ÁVILA MARTÍNEZ')
        // La regional aparece con su sede.
        ->and($regionalComercial['personas'][0])->toMatchArray(['nombre' => 'Mónica Hernández Ortega', 'sucursal' => 'Tula'])
        ->and($sucursales)->not->toHaveKey('Corporativo')
        ->and($sucursales['Tula']['gerente'][0]['nombre'])->toBe('Luis Pérez Gómez')
        ->and($sucursales['Tula']['subgerente'])->toBe([]);
});

test('el área se llena aunque el puesto se llame distinto (sin acentos, otro nombre o solo por departamento)', function () {
    $corporativo = Sucursal::factory()->create(['nombre' => 'Corporativo', 'es_corporativo' => true, 'activo' => true]);
    $rh = Departamento::factory()->create(['nombre' => 'Recursos Humanos']);
    $encargadoSistemas = Puesto::factory()->create(['nombre' => 'ENCARGADO DE SISTEMAS']);
    $contralor = Puesto::factory()->create(['nombre' => 'Contraloria']);
    $jefaRh = Puesto::factory()->create(['nombre' => 'Jefa de Personal', 'departamento_id' => $rh->id, 'nivel_jerarquico' => 2]);
    $auxiliarRh = Puesto::factory()->create(['nombre' => 'Auxiliar de Personal', 'departamento_id' => $rh->id, 'nivel_jerarquico' => 4]);
    Colaborador::factory()->create(['name' => 'Raúl', 'apellidos' => 'Soto Vega', 'puesto_id' => $encargadoSistemas->id, 'sucursal_principal_id' => $corporativo->id, 'estatus' => 'activo']);
    Colaborador::factory()->create(['name' => 'Elena', 'apellidos' => 'Ríos Paz', 'puesto_id' => $contralor->id, 'sucursal_principal_id' => $corporativo->id, 'estatus' => 'activo']);
    Colaborador::factory()->create(['name' => 'Laura', 'apellidos' => 'Mena Cruz', 'puesto_id' => $jefaRh->id, 'sucursal_principal_id' => $corporativo->id, 'estatus' => 'activo']);
    Colaborador::factory()->create(['name' => 'Pablo', 'apellidos' => 'Luna Gil', 'puesto_id' => $auxiliarRh->id, 'sucursal_principal_id' => $corporativo->id, 'estatus' => 'activo']);

    $areas = collect(app(LaminasOrganigramaService::class)->laminas(clUsuario('rh_admin'))['corporativo']['areas'])->keyBy('sigla');

    expect(collect($areas['SI']['personas'])->pluck('nombre')->all())->toBe(['Raúl Soto Vega'])
        ->and(collect($areas['CT']['personas'])->pluck('nombre')->all())->toBe(['Elena Ríos Paz'])
        // Por departamento: solo la de mayor jerarquía, no todo RH.
        ->and(collect($areas['RH']['personas'])->pluck('nombre')->all())->toBe(['Laura Mena Cruz']);
});

test('Dirección Comercial: la Asistente y el Monitorista son apoyo de DC, nunca su titular ni parte de Sistemas', function () {
    $corporativo = Sucursal::factory()->create(['nombre' => 'Corporativo', 'es_corporativo' => true, 'activo' => true]);
    $director = Puesto::factory()->create(['nombre' => 'Dirección Comercial']);
    $asistente = Puesto::factory()->create(['nombre' => 'Asistente de Dirección Comercial']);
    $monitorista = Puesto::factory()->create(['nombre' => 'Monitorista de Cámaras']);
    $sistemas = Puesto::factory()->create(['nombre' => 'Responsable de Sistemas']);
    Colaborador::factory()->create(['name' => 'Jorge', 'apellidos' => 'Salas Mora', 'puesto_id' => $director->id, 'sucursal_principal_id' => $corporativo->id, 'estatus' => 'activo']);
    Colaborador::factory()->create(['name' => 'Dana', 'apellidos' => 'Juarez Hernandez', 'puesto_id' => $asistente->id, 'sucursal_principal_id' => $corporativo->id, 'estatus' => 'activo']);
    Colaborador::factory()->create(['name' => 'Iván', 'apellidos' => 'Cruz León', 'puesto_id' => $monitorista->id, 'sucursal_principal_id' => $corporativo->id, 'estatus' => 'activo']);
    Colaborador::factory()->create(['name' => 'Ana', 'apellidos' => 'Martínez Ruiz', 'puesto_id' => $sistemas->id, 'sucursal_principal_id' => $corporativo->id, 'estatus' => 'activo']);

    $laminas = app(LaminasOrganigramaService::class)->laminas(clUsuario('rh_admin'));
    $areas = collect($laminas['corporativo']['areas'])->keyBy('sigla');
    $apoyo = collect($laminas['comercial']['apoyo'])->keyBy('titulo');
    $todos = collect($laminas['corporativo']['areas'])->flatMap(fn ($a) => $a['personas'])
        ->merge(collect($laminas['comercial']['apoyo'])->flatMap(fn ($a) => $a['personas']))
        ->pluck('nombre');

    expect(collect($areas['DC']['personas'])->pluck('nombre')->all())->toBe(['Jorge Salas Mora'])
        ->and(collect($laminas['comercial']['director']['personas'])->pluck('nombre')->all())->toBe(['Jorge Salas Mora'])
        ->and(collect($apoyo['Asistente de Dirección Comercial']['personas'])->pluck('nombre')->all())->toBe(['Dana Juarez Hernandez'])
        ->and(collect($apoyo['Monitorista de Cámaras']['personas'])->pluck('nombre')->all())->toBe(['Iván Cruz León'])
        ->and(collect($areas['SI']['personas'])->pluck('nombre')->all())->toBe(['Ana Martínez Ruiz'])
        // Nadie aparece dos veces.
        ->and($todos->duplicates()->all())->toBe([]);
});

test('fotos: sin foto llega null (iniciales, nunca imagen rota) y la URL nunca es una ruta del NAS', function () {
    $corporativo = Sucursal::factory()->create(['nombre' => 'Corporativo', 'es_corporativo' => true, 'activo' => true]);
    $sistemas = Puesto::factory()->create(['nombre' => 'Responsable de Sistemas']);
    $contralor = Puesto::factory()->create(['nombre' => 'Contraloría']);
    Colaborador::factory()->create(['name' => 'Ana', 'apellidos' => 'Martínez Ruiz', 'puesto_id' => $sistemas->id, 'sucursal_principal_id' => $corporativo->id, 'estatus' => 'activo', 'foto_path' => null]);
    Colaborador::factory()->create(['name' => 'Elena', 'apellidos' => 'Ríos Paz', 'puesto_id' => $contralor->id, 'sucursal_principal_id' => $corporativo->id, 'estatus' => 'activo', 'foto_path' => 'colaboradores/fotos/elena.jpg']);

    $areas = collect(app(LaminasOrganigramaService::class)->laminas(clUsuario('rh_admin'))['corporativo']['areas'])->keyBy('sigla');

    expect($areas['SI']['personas'][0]['foto_url'])->toBeNull()
        ->and($areas['CT']['personas'][0]['foto_url'])->toBeString()
        ->and($areas['CT']['personas'][0]['foto_url'])->not->toContain('colaboradores/fotos/elena.jpg')
        ->and($areas['CT']['personas'][0]['foto_url'])->toStartWith('http');
});
