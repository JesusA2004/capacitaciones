<?php

use App\Models\Colaborador;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

/*
| Directorio (web y API móvil): para cualquier colaborador autenticado
| con acceso y dentro de su alcance: contacto (teléfono y correos) — nunca salario, RFC, CURP,
| NSS ni domicilio (DirectorioColaboradoresService).
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

const DIRECTORIO_CAMPOS = ['id', 'nombre', 'numero_empleado', 'puesto', 'sucursal', 'departamento', 'correo_corporativo', 'correo_personal', 'telefono', 'telefono_corporativo', 'lleva_correo_corporativo', 'contacto_visible', 'datos_por_completar', 'es_mi_jefe'];
const DIRECTORIO_PROHIBIDOS = ['HEPS900101AB1', 'HEPS900101MMSRZF09', '12345678901', 'Calle Secreta', '62000', '456.78', '13703'];

test('API móvil: el directorio solo serializa contacto corporativo, nunca datos sensibles', function () {
    // Con permiso de ver todos los contactos (el operativo solo ve el de su jefe).
    $usuario = User::factory()->for(Colaborador::factory()->state(['estatus' => 'activo']))->create();
    $usuario->assignRole('rh_admin');
    $token = $usuario->createToken('test')->plainTextToken;

    $respuesta = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->getJson('/api/v1/directorio?'.http_build_query(['busqueda' => 'Sofía Herrera']))
        ->assertOk()
        ->assertJsonPath('data.0.correo_corporativo', 'sofia.herrera@mrlana.test')
        ->assertJsonPath('data.0.correo_personal', 'sofi.personal@gmail.test')
        // El teléfono es el de la persona (la empresa no asigna líneas).
        ->assertJsonPath('data.0.telefono', '5559998877');

    // + foto_url: URL protegida (Bearer) de la foto, nunca foto_path (docs/FOTO_EXPEDIENTE.md).
    expect(array_keys($respuesta->json('data.0')))->toEqualCanonicalizing([...DIRECTORIO_CAMPOS, 'foto_url']);

    foreach (DIRECTORIO_PROHIBIDOS as $dato) {
        expect($respuesta->getContent())->not->toContain($dato);
    }
});

test('web: el directorio solo serializa contacto corporativo, nunca datos sensibles', function () {
    // Compañero de la misma sucursal (el personal de sucursal solo ve la suya).
    $respuesta = $this->actingAs(clUsuario('coordinadora', ['sucursal_principal_id' => $this->sensible->sucursal_principal_id]))->get(route('directorio.index'))->assertOk();
    // La web agrega solo datos de agrupación (puesto, nivel, sucursal, área)
    // y la URL protegida de la foto (null fuera del alcance de Expedientes).
    $permitidos = [...DIRECTORIO_CAMPOS, 'puesto_id', 'nivel', 'sucursal_id', 'departamento_id', 'es_corporativo', 'area', 'foto_url', 'expediente_url'];

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
    $usuario->assignRole('coordinadora');
    $token = $usuario->createToken('test')->plainTextToken;

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->getJson('/api/v1/directorio?puesto_id=abc&sucursal_id=-1')
        ->assertOk();

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->getJson('/api/v1/directorio/opciones')
        ->assertOk()
        ->assertJsonStructure(['data' => ['puestos', 'sucursales', 'departamentos']]);
});
