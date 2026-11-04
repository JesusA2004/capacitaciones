<?php

use App\Models\Colaborador;
use App\Models\ColaboradorDatosMedicos;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Integraciones\TokenIntegracionService;
use Database\Seeders\RolesYPermisosSeeder;

/**
 * Contrato «people-auditoria/2»: contexto del acta de Auditoría (sucursal y
 * colaborador). El v1 sigue igual (AuditoriaIntegracionApiTest).
 */
function actaAudToken(array $empresas, array $habilidades = [TokenIntegracionService::HABILIDAD_AUDITORIA_LEER]): string
{
    return app(TokenIntegracionService::class)->emitir('auditoria', 'Auditoría (prueba acta)', $habilidades, false, $empresas)['token'];
}

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 3)->setTime(9, 0));
    $this->empresa = Empresa::factory()->create(['nombre' => 'Productos y Servicios Mr Lana']);
    $this->otra = Empresa::factory()->create(['nombre' => 'Otra empresa']);
    $this->sucursal = Sucursal::factory()->create([
        'empresa_id' => $this->empresa->id, 'nombre' => 'Atlixco', 'clave' => 'ATL01',
        'direccion' => 'Av. Independencia 512', 'colonia' => 'Centro', 'municipio' => 'Atlixco', 'ciudad' => 'Atlixco',
        'estado' => 'Puebla', 'codigo_postal' => '74200', 'telefono' => '2441234567',
    ]);
    $this->ajena = Sucursal::factory()->create(['empresa_id' => $this->otra->id, 'nombre' => 'Ajena', 'clave' => 'AJE01']);
    $this->puestoGestor = Puesto::factory()->create(['nombre' => 'Gestor']);
    $this->puestoGerente = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal']);

    $this->gerente = Colaborador::factory()->create([
        'sucursal_principal_id' => $this->sucursal->id, 'puesto_id' => $this->puestoGerente->id,
        'name' => 'Luis Angel', 'apellidos' => 'Lopez Ramirez', 'numero_empleado' => 'EMP-0200',
        'fecha_nacimiento' => '1985-02-14', 'curp' => 'LORL850214HPLPMS09', 'rfc' => 'LORL850214AB1', 'nss' => '98765432109',
    ]);
    $this->gestor = Colaborador::factory()->create([
        'sucursal_principal_id' => $this->sucursal->id, 'puesto_id' => $this->puestoGestor->id, 'gerente_id' => $this->gerente->id,
        'name' => 'Rafael', 'apellidos' => 'Perez Chao', 'numero_empleado' => 'EMP-0201',
        'fecha_nacimiento' => '1994-07-30', 'curp' => 'PECR940730HPLRHF01', 'rfc' => 'PECR940730XY2', 'nss' => '12345678901',
        'correo_personal' => 'rafael.personal@example.test', 'telefono' => '2449876543',
        'domicilio_calle' => 'Calle 5 de Mayo', 'domicilio_numero_exterior' => '14', 'domicilio_numero_interior' => 'B',
        'domicilio_colonia' => 'San Juan', 'domicilio_municipio' => 'Atlixco', 'domicilio_estado' => 'Puebla', 'domicilio_cp' => '74210',
        'clave_elector' => 'PRCHRF94073021H400', 'salario_diario' => 450, 'sueldo_mensual' => 13500,
        'contacto_emergencia_nombre' => 'Contacto Privado', 'contacto_emergencia_telefono' => '2440000000',
    ]);
    $cuentaGerente = User::factory()->create(['colaborador_id' => $this->gerente->id]);
    $this->sucursal->update(['responsable_id' => $cuentaGerente->id]);
});

test('la sucursal del acta trae su domicilio y su responsable, sin telefono', function () {
    $r = $this->withToken(actaAudToken([$this->empresa->id]))->getJson("/api/v1/auditoria/sucursales/{$this->sucursal->id}/acta")
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('contrato', 'people-auditoria/2')
        ->assertJsonPath('data.clave', 'ATL01')
        ->assertJsonPath('data.domicilio', [
            'direccion' => 'Av. Independencia 512', 'colonia' => 'Centro', 'municipio' => 'Atlixco',
            'ciudad' => 'Atlixco', 'estado' => 'Puebla', 'codigo_postal' => '74200',
        ])
        ->assertJsonPath('data.responsable_estado', 'asignado')
        ->assertJsonPath('data.responsable.id', $this->gerente->id)
        ->assertJsonPath('data.responsable.nombre_completo', 'Luis Angel Lopez Ramirez')
        ->assertJsonPath('data.responsable.numero_empleado', 'EMP-0200')
        ->assertJsonPath('data.responsable.puesto', 'Gerente de Sucursal')
        ->assertJsonPath('data.responsable.activo', true);

    expect(array_keys($r->json('data')))->toBe(['id', 'empresa_id', 'clave', 'nombre', 'activo', 'domicilio', 'responsable_estado', 'responsable', 'updated_at'])
        ->and(array_keys($r->json('data.responsable')))->toBe(['id', 'numero_empleado', 'nombre_completo', 'puesto', 'empresa_id', 'sucursal', 'activo'])
        ->and($r->getContent())->not->toContain('2441234567')->not->toContain('telefono')->not->toContain('LORL850214')->not->toContain('1985-02-14');
});

test('el colaborador del acta trae edad base, domicilio, gerente e identificacion sin numero ni datos prohibidos', function () {
    ColaboradorDatosMedicos::query()->create(['colaborador_id' => $this->gestor->id, 'condicion_medica' => 'Asma cronica', 'alergias' => 'Penicilina']);

    $r = $this->withToken(actaAudToken([$this->empresa->id]))->getJson("/api/v1/auditoria/colaboradores/{$this->gestor->id}/acta")
        ->assertOk()
        ->assertJsonPath('contrato', 'people-auditoria/2')
        ->assertJsonPath('data.nombre_completo', 'Rafael Perez Chao')
        ->assertJsonPath('data.numero_empleado', 'EMP-0201')
        ->assertJsonPath('data.puesto', 'Gestor')
        ->assertJsonPath('data.sucursal', ['id' => $this->sucursal->id, 'clave' => 'ATL01', 'nombre' => 'Atlixco'])
        ->assertJsonPath('data.fecha_nacimiento', '1994-07-30')
        ->assertJsonPath('data.domicilio', [
            'estructurado' => true, 'calle' => 'Calle 5 de Mayo', 'numero_exterior' => '14', 'numero_interior' => 'B',
            'colonia' => 'San Juan', 'municipio' => 'Atlixco', 'estado' => 'Puebla', 'codigo_postal' => '74210', 'texto' => null,
        ])
        ->assertJsonPath('data.identificacion', ['clave_elector_registrada' => true])
        ->assertJsonPath('data.gerente_estado', 'asignado')
        ->assertJsonPath('data.gerente.id', $this->gerente->id);

    expect(array_keys($r->json('data')))->toBe([
        'id', 'numero_empleado', 'nombre_completo', 'puesto', 'empresa_id', 'sucursal', 'activo',
        'fecha_nacimiento', 'domicilio', 'identificacion', 'gerente_estado', 'gerente', 'updated_at',
    ]);

    $json = $r->getContent();
    foreach (['PECR940730', '12345678901', 'PRCHRF94073021H400', 'rafael.personal', '2449876543', '13500', 'Contacto Privado', 'Penicilina', 'Asma cronica',
        'curp', 'rfc', 'nss', 'salario', 'sueldo', 'password', 'token', 'correo', 'telefono', 'foto', 'expediente', 'medic', 'LORL850214', '1985-02-14'] as $prohibido) {
        expect($json)->not->toContain($prohibido);
    }
});

test('un domicilio solo en texto legado se entrega tal cual, sin partirlo', function () {
    $legado = Colaborador::factory()->create([
        'sucursal_principal_id' => $this->sucursal->id, 'puesto_id' => $this->puestoGestor->id,
        'domicilio' => 'Priv. Hidalgo 22 int 3, Col. Centro, Atlixco, Pue.', 'domicilio_calle' => null, 'domicilio_cp' => null,
        'fecha_nacimiento' => null, 'clave_elector' => null,
    ]);

    $this->withToken(actaAudToken([$this->empresa->id]))->getJson("/api/v1/auditoria/colaboradores/{$legado->id}/acta")
        ->assertOk()
        ->assertJsonPath('data.domicilio.estructurado', false)
        ->assertJsonPath('data.domicilio.calle', null)
        ->assertJsonPath('data.domicilio.numero_exterior', null)
        ->assertJsonPath('data.domicilio.texto', 'Priv. Hidalgo 22 int 3, Col. Centro, Atlixco, Pue.')
        ->assertJsonPath('data.fecha_nacimiento', null)
        ->assertJsonPath('data.identificacion.clave_elector_registrada', false)
        ->assertJsonPath('data.gerente_estado', 'sin_asignar')
        ->assertJsonPath('data.gerente', null);
});

test('responsable sin persona o sin asignar se informa como estado, nunca se adivina', function () {
    $this->sucursal->update(['responsable_id' => User::factory()->create(['colaborador_id' => null])->id]);
    $token = actaAudToken([$this->empresa->id]);

    $this->withToken($token)->getJson("/api/v1/auditoria/sucursales/{$this->sucursal->id}/acta")
        ->assertOk()->assertJsonPath('data.responsable_estado', 'sin_colaborador')->assertJsonPath('data.responsable', null);

    $this->sucursal->update(['responsable_id' => null]);
    $this->withToken($token)->getJson("/api/v1/auditoria/sucursales/{$this->sucursal->id}/acta")
        ->assertOk()->assertJsonPath('data.responsable_estado', 'sin_asignar')->assertJsonPath('data.responsable', null);
});

test('un responsable dado de baja sale con activo false', function () {
    $this->gerente->update(['estatus' => 'inactivo']);

    $this->withToken(actaAudToken([$this->empresa->id]))->getJson("/api/v1/auditoria/sucursales/{$this->sucursal->id}/acta")
        ->assertOk()->assertJsonPath('data.responsable_estado', 'asignado')->assertJsonPath('data.responsable.activo', false);
});

test('otra empresa no es visible en el acta', function () {
    $ajeno = Colaborador::factory()->create(['sucursal_principal_id' => $this->ajena->id, 'puesto_id' => $this->puestoGestor->id]);
    $token = actaAudToken([$this->empresa->id]);

    $this->withToken($token)->getJson("/api/v1/auditoria/sucursales/{$this->ajena->id}/acta")->assertNotFound()->assertJson(['codigo' => 'no_encontrado']);
    $this->withToken($token)->getJson("/api/v1/auditoria/colaboradores/{$ajeno->id}/acta")->assertNotFound()->assertJson(['codigo' => 'no_encontrado']);
    $this->withToken($token)->getJson('/api/v1/auditoria/colaboradores/999999/acta')->assertNotFound();
    $this->withToken($token)->getJson('/api/v1/auditoria/sucursales/abc/acta')->assertNotFound();

    // Un gerente de otra empresa no se entrega aunque esté capturado.
    $this->gestor->update(['gerente_id' => $ajeno->id]);
    $this->withToken($token)->getJson("/api/v1/auditoria/colaboradores/{$this->gestor->id}/acta")
        ->assertOk()->assertJsonPath('data.gerente_estado', 'fuera_de_alcance')->assertJsonPath('data.gerente', null);
});

test('sin token, con token Sanctum o sin la habilidad el acta no se entrega', function () {
    $url = "/api/v1/auditoria/colaboradores/{$this->gestor->id}/acta";

    $this->getJson($url)->assertStatus(401)->assertJson(['codigo' => 'no_autenticado']);

    $this->seed(RolesYPermisosSeeder::class);
    $usuario = User::factory()->create(['colaborador_id' => $this->gestor->id]);
    $usuario->assignRole('super_admin');
    $this->withToken($usuario->createToken('app')->plainTextToken)->getJson($url)->assertStatus(401)->assertJson(['codigo' => 'no_autenticado']);

    $this->withToken(actaAudToken([$this->empresa->id], ['otra:cosa']))->getJson($url)->assertStatus(403)->assertJson(['codigo' => 'sin_permiso']);
    $this->withToken(actaAudToken([$this->empresa->id], ['otra:cosa']))->getJson("/api/v1/auditoria/sucursales/{$this->sucursal->id}/acta")->assertStatus(403);
});

test('el catalogo v1 no cambia su contrato y anuncia el v2 de forma aditiva', function () {
    $this->withToken(actaAudToken([$this->empresa->id]))->getJson('/api/v1/auditoria/catalogo')
        ->assertOk()
        ->assertJsonPath('contrato', 'people-auditoria/1')
        ->assertJsonPath('contratos', ['people-auditoria/1', 'people-auditoria/2'])
        ->assertJsonPath('capacidades', ['colaboradores', 'delta_updated_since', 'sucursales']);
});
