<?php

use App\Models\Colaborador;
use App\Models\Puesto;
use App\Models\Sucursal;
use Database\Seeders\RolesYPermisosSeeder;
use Laravel\Sanctum\Sanctum;

/*
| Capítulo 4 de Inducción Mr. Lana: el organigrama debe salir del catálogo
| REAL de puestos (Fase 3, MiniOrganigramaService), nunca de una imagen fija
| ni de personas hardcodeadas — ver config/organigrama.php 'vistas_induccion'.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('la vista "comercial" del organigrama de inducción trae la rama real de Dirección Comercial, con ocupantes activos', function () {
    $direccionComercial = Puesto::factory()->create(['nombre' => 'Dirección Comercial']);
    $gerenteSucursalPuesto = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal', 'puesto_superior_id' => $direccionComercial->id]);
    $sucursal = Sucursal::factory()->create();
    $gerente = Colaborador::factory()->create(['puesto_id' => $gerenteSucursalPuesto->id, 'sucursal_principal_id' => $sucursal->id, 'estatus' => 'activo']);

    $usuario = clUsuario('colaborador');

    $this->actingAs($usuario)
        ->getJson(route('portal.induccion.organigrama', ['vista' => 'comercial']))
        ->assertOk()
        ->assertJsonPath('data.id', $direccionComercial->id)
        ->assertJsonPath('data.nombre', 'Dirección Comercial')
        ->assertJsonPath('data.hijos.0.nombre', 'Gerente de Sucursal')
        ->assertJsonPath('data.hijos.0.ocupantes.0.nombre', trim($gerente->name.' '.$gerente->apellidos));
});

test('una vista de organigrama que no existe responde 404, nunca una imagen fija de respaldo', function () {
    $usuario = clUsuario('colaborador');

    $this->actingAs($usuario)
        ->getJson(route('portal.induccion.organigrama', ['vista' => 'no_existe']))
        ->assertNotFound();
});

test('la API móvil expone la misma vista de organigrama que la web (mismo service)', function () {
    $direccionGeneral = Puesto::factory()->create(['nombre' => 'Dirección General']);
    Puesto::factory()->create(['nombre' => 'Dirección Comercial', 'puesto_superior_id' => $direccionGeneral->id]);

    $usuario = clUsuario('colaborador');

    Sanctum::actingAs($usuario);
    $this->getJson('/api/v1/colaborador/induccion/organigrama/corporativo')
        ->assertOk()
        ->assertJsonPath('data.id', $direccionGeneral->id)
        ->assertJsonPath('data.nombre', 'Dirección General')
        ->assertJsonPath('data.hijos.0.nombre', 'Dirección Comercial');
});

/*
| Organigrama INSTITUCIONAL: la inducción muestra a cualquier colaborador
| autenticado los ocupantes reales de cada puesto — NO se acota por
| AlcanceOrganizacionalService (antes un colaborador normal veía todo como
| "Vacante") — y solo con datos organizacionales mínimos.
*/
test('un colaborador normal ve los ocupantes reales del organigrama corporativo en web, presentación y app, sin datos sensibles', function () {
    $direccionGeneral = Puesto::factory()->create(['nombre' => 'Dirección General']);
    $gerenciaRh = Puesto::factory()->create(['nombre' => 'Gerencia RH', 'puesto_superior_id' => $direccionGeneral->id]);
    $sucursal = Sucursal::factory()->create(['nombre' => 'Matriz']);

    $sensibles = [
        'rfc' => 'PEPJ800101AB1',
        'curp' => 'PEPJ800101HDFRRN09',
        'nss' => '12345678901',
        'salario_diario' => '987.65',
        'correo_personal' => 'juan.personal@example.com',
        'telefono' => '5512345678',
        'domicilio' => 'Calle Secreta 123',
    ];

    Colaborador::factory()->create(['name' => 'Juan', 'apellidos' => 'Pérez', 'puesto_id' => $direccionGeneral->id, 'sucursal_principal_id' => $sucursal->id, 'estatus' => 'activo'] + $sensibles);
    Colaborador::factory()->create(['name' => 'Ana', 'apellidos' => 'López', 'puesto_id' => $gerenciaRh->id, 'sucursal_principal_id' => $sucursal->id, 'estatus' => 'activo']);

    // Colaborador normal de OTRA sucursal: su alcance organizacional no
    // incluye a Juan ni a Ana.
    $usuario = clUsuario('colaborador', ['sucursal_principal_id' => Sucursal::factory()->create()->id]);

    $respuestas = [
        $this->actingAs($usuario)->getJson(route('portal.induccion.organigrama', ['vista' => 'corporativo'])),
        $this->actingAs($usuario)->getJson(route('induccion.presentacion.organigrama', ['vista' => 'corporativo'])),
    ];
    Sanctum::actingAs($usuario);
    $respuestas[] = $this->getJson('/api/v1/colaborador/induccion/organigrama/corporativo');

    foreach ($respuestas as $respuesta) {
        $respuesta->assertOk()
            ->assertJsonPath('data.nombre', 'Dirección General')
            ->assertJsonPath('data.ocupantes.0.nombre', 'Juan Pérez')
            ->assertJsonPath('data.ocupantes.0.sucursal', 'Matriz')
            ->assertJsonPath('data.hijos.0.nombre', 'Gerencia RH')
            ->assertJsonPath('data.hijos.0.ocupantes.0.nombre', 'Ana López');

        expect(array_keys($respuesta->json('data.ocupantes.0')))->toEqualCanonicalizing(['id', 'nombre', 'sucursal']);

        $json = $respuesta->getContent();
        foreach ($sensibles as $valor) {
            expect($json)->not->toContain($valor);
        }
        foreach (['rfc', 'curp', 'nss', 'salario', 'sueldo', 'correo', 'telefono', 'domicilio', 'numero_empleado', 'expediente'] as $campo) {
            expect($json)->not->toContain('"'.$campo);
        }
    }
});
