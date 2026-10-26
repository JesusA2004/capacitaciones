<?php

use App\Models\Colaborador;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

/*
| Directorio (web y API móvil): para cualquier colaborador autenticado
| dentro de su alcance, pero SOLO contacto corporativo — nunca salario, RFC, CURP,
| NSS, domicilio ni correo/teléfono personal (DirectorioColaboradoresService).
| El contacto corporativo solo a quien puede verlo (DirectorioPrivacidadTest).
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);

    $this->sensible = Colaborador::factory()->create([
        'name' => 'Sofía', 'apellidos' => 'Herrera Paz',
        'estatus' => 'activo',
        'sucursal_principal_id' => Sucursal::factory()->create(['activo' => true])->id,
        'correo_corporativo' => 'sofia.herrera@mrlana.test',
        'telefono_corporativo' => '7771112233',
        'correo_personal' => 'sofi.personal@gmail.test',
        'telefono' => '5559998877',
        'rfc' => 'HEPS900101AB1',
        'curp' => 'HEPS900101MMSRZF09',
        'nss' => '12345678901',
        'domicilio' => 'Calle Secreta 123',
        'domicilio_cp' => '62000',
        'salario_diario' => 456.78,
        'sueldo_mensual' => 13703.40,
    ]);
});

const DIRECTORIO_CAMPOS = ['id', 'nombre', 'numero_empleado', 'puesto', 'sucursal', 'departamento', 'correo_corporativo', 'telefono_corporativo', 'contacto_visible', 'es_mi_jefe'];
const DIRECTORIO_PROHIBIDOS = ['sofi.personal@gmail.test', '5559998877', 'HEPS900101AB1', 'HEPS900101MMSRZF09', '12345678901', 'Calle Secreta', '62000', '456.78', '13703'];

test('API móvil: el directorio solo serializa contacto corporativo, nunca datos sensibles', function () {
    // Con permiso de ver todos los contactos (el operativo solo ve el de su jefe).
    $usuario = User::factory()->for(Colaborador::factory()->state(['estatus' => 'activo']))->create();
    $usuario->assignRole('rh_admin');
    $token = $usuario->createToken('test')->plainTextToken;

    $respuesta = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->getJson('/api/v1/directorio?busqueda=Sofía Herrera')
        ->assertOk()
        ->assertJsonPath('data.0.correo_corporativo', 'sofia.herrera@mrlana.test')
        ->assertJsonPath('data.0.telefono_corporativo', '7771112233');

    expect(array_keys($respuesta->json('data.0')))->toEqualCanonicalizing(DIRECTORIO_CAMPOS);

    foreach (DIRECTORIO_PROHIBIDOS as $dato) {
        expect($respuesta->getContent())->not->toContain($dato);
    }
});

test('web: el directorio solo serializa contacto corporativo, nunca datos sensibles', function () {
    // Compañero de la misma sucursal (el personal de sucursal solo ve la suya).
    $respuesta = $this->actingAs(clUsuario('colaborador', ['sucursal_principal_id' => $this->sensible->sucursal_principal_id]))->get(route('directorio.index'))->assertOk();
    // La web agrega solo datos de agrupación (puesto, nivel, sucursal, área).
    $permitidos = [...DIRECTORIO_CAMPOS, 'puesto_id', 'nivel', 'sucursal_id', 'departamento_id', 'es_corporativo', 'area'];

    $respuesta->assertInertia(fn ($page) => $page->where(
        'colaboradores',
        fn ($data) => collect($data)->contains('nombre', 'Sofía Herrera Paz')
            && collect($data)->every(fn ($fila) => array_diff(array_keys($fila), $permitidos) === []),
    ));

    foreach (DIRECTORIO_PROHIBIDOS as $dato) {
        expect($respuesta->getContent())->not->toContain($dato);
    }
});

test('API móvil: el directorio exige sesión y tolera filtros inválidos sin error', function () {
    $this->getJson('/api/v1/directorio')->assertUnauthorized();

    $usuario = User::factory()->for(Colaborador::factory()->state(['estatus' => 'activo']))->create();
    $usuario->assignRole('colaborador');
    $token = $usuario->createToken('test')->plainTextToken;

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->getJson('/api/v1/directorio?puesto_id=abc&sucursal_id=-1')
        ->assertOk();

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->getJson('/api/v1/directorio/opciones')
        ->assertOk()
        ->assertJsonStructure(['data' => ['puestos', 'sucursales', 'departamentos']]);
});
