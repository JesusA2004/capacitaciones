<?php

use App\Models\Colaborador;
use App\Models\ColaboradorPerfilIngreso;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\Integraciones\TokenIntegracionService;

/**
 * Contrato «people-auditoria/3» (aditivo, a petición con `?contrato=3`):
 * departamento del puesto, catálogos de puestos y departamentos, e
 * identificación oficial del acta. Los contratos 1 y 2 no cambian
 * (AuditoriaIntegracionApiTest, AuditoriaActaIntegracionApiTest).
 */
function c3Token(array $empresas): string
{
    return app(TokenIntegracionService::class)->emitir('auditoria', 'Auditoría (prueba contrato 3)', [TokenIntegracionService::HABILIDAD_AUDITORIA_LEER], false, $empresas)['token'];
}

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 10)->setTime(9, 0));
    $this->empresa = Empresa::factory()->create(['nombre' => 'Productos y Servicios Mr Lana']);
    $this->sucursal = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Atlixco', 'clave' => 'ATL01']);
    $this->departamento = Departamento::factory()->create(['nombre' => 'Cobranza']);
    $this->puesto = Puesto::factory()->create(['nombre' => 'Gestor', 'departamento_id' => $this->departamento->id]);
    $this->gestor = Colaborador::factory()->create([
        'sucursal_principal_id' => $this->sucursal->id, 'puesto_id' => $this->puesto->id,
        'name' => 'Rafael', 'apellidos' => 'Perez Chao', 'clave_elector' => 'PRCHRF94073021H400',
    ]);
    $this->token = c3Token([$this->empresa->id]);
});

test('el catalogo anuncia el contrato 3 sin cambiar el contrato principal', function () {
    $this->withToken($this->token)->getJson('/api/v1/auditoria/catalogo')
        ->assertOk()
        ->assertJsonPath('contrato', 'people-auditoria/1')
        ->assertJsonPath('contratos', ['people-auditoria/1', 'people-auditoria/2'])
        ->assertJsonPath('extensiones', ['people-auditoria/3']);
});

test('con contrato=3 el colaborador trae el departamento de su puesto; sin el parametro no', function () {
    $this->withToken($this->token)->getJson("/api/v1/auditoria/colaboradores?empresa_id={$this->empresa->id}&contrato=3")
        ->assertOk()
        ->assertJsonPath('data.0.puesto', ['id' => $this->puesto->id, 'nombre' => 'Gestor'])
        ->assertJsonPath('data.0.departamento', ['id' => $this->departamento->id, 'nombre' => 'Cobranza']);

    $v1 = $this->withToken($this->token)->getJson("/api/v1/auditoria/colaboradores?empresa_id={$this->empresa->id}")->assertOk();
    expect($v1->json('data.0'))->not->toHaveKey('departamento');
});

test('renombrar el departamento adelanta la marca de cambio del colaborador', function () {
    $antes = $this->withToken($this->token)->getJson("/api/v1/auditoria/colaboradores/{$this->gestor->id}?contrato=3")->json('data.updated_at');

    $this->travel(10)->minutes();
    $this->departamento->update(['nombre' => 'Cobranza y Recuperación']);

    $r = $this->withToken($this->token)->getJson('/api/v1/auditoria/colaboradores?contrato=3&updated_since='.urlencode(now()->subMinute()->toIso8601String()))
        ->assertOk()
        ->assertJsonPath('data.0.id', $this->gestor->id)
        ->assertJsonPath('data.0.departamento.nombre', 'Cobranza y Recuperación');
    expect($r->json('data.0.updated_at'))->not->toBe($antes);
});

test('los catalogos de puestos y departamentos listan tambien lo inactivo y lo borrado como no vigente', function () {
    $inactivo = Puesto::factory()->create(['nombre' => 'Cajero', 'activo' => false, 'departamento_id' => null]);
    $borrado = Departamento::factory()->create(['nombre' => 'Archivo']);
    $borrado->delete();

    $p = $this->withToken($this->token)->getJson('/api/v1/auditoria/puestos')->assertOk()->assertJsonPath('contrato', 'people-auditoria/3');
    $porId = collect($p->json('data'))->keyBy('id');
    expect(array_keys($p->json('data.0')))->toBe(['id', 'nombre', 'departamento', 'activo', 'updated_at'])
        ->and($porId[$this->puesto->id]['departamento'])->toBe(['id' => $this->departamento->id, 'nombre' => 'Cobranza'])
        ->and($porId[$this->puesto->id]['activo'])->toBeTrue()
        ->and($porId[$inactivo->id]['activo'])->toBeFalse()
        ->and($porId[$inactivo->id]['departamento'])->toBeNull();

    $d = $this->withToken($this->token)->getJson('/api/v1/auditoria/departamentos')->assertOk();
    $porId = collect($d->json('data'))->keyBy('id');
    expect(array_keys($d->json('data.0')))->toBe(['id', 'nombre', 'activo', 'updated_at'])
        ->and($porId[$this->departamento->id]['activo'])->toBeTrue()
        ->and($porId[$borrado->id]['activo'])->toBeFalse();

    foreach (['descripcion', 'responsabilidades', 'requisitos', 'esquema_comisiones', 'nivel_jerarquico'] as $prohibido) {
        expect($p->getContent())->not->toContain($prohibido);
    }
});

test('los catalogos exigen el token de integracion', function () {
    $this->getJson('/api/v1/auditoria/puestos')->assertUnauthorized();
    $this->getJson('/api/v1/auditoria/departamentos')->assertUnauthorized();
});

test('el acta v3 dice que identificacion presento la persona y nunca el numero sin la bandera', function () {
    $r = $this->withToken($this->token)->getJson("/api/v1/auditoria/colaboradores/{$this->gestor->id}/acta?contrato=3")
        ->assertOk()
        ->assertJsonPath('contrato', 'people-auditoria/3')
        ->assertJsonPath('data.identificacion', [
            'clave_elector_registrada' => true, 'tipo' => 'ine', 'tipo_etiqueta' => 'INE / IFE',
            'institucion' => 'Instituto Nacional Electoral', 'clave_elector' => null,
        ]);
    expect($r->getContent())->not->toContain('PRCHRF94073021H400');
});

test('el acta v3 toma el tipo y la institucion del perfil de ingreso y nunca su folio', function () {
    (new ColaboradorPerfilIngreso)->forceFill([
        'colaborador_id' => $this->gestor->id, 'identificacion_tipo' => 'licencia',
        'identificacion_institucion' => 'Secretaría de Movilidad de Puebla', 'identificacion_folio' => 'LIC-998877',
    ])->save();

    $r = $this->withToken($this->token)->getJson("/api/v1/auditoria/colaboradores/{$this->gestor->id}/acta?contrato=3")
        ->assertOk()
        ->assertJsonPath('data.identificacion.tipo', 'licencia')
        ->assertJsonPath('data.identificacion.tipo_etiqueta', 'Licencia de conducir')
        ->assertJsonPath('data.identificacion.institucion', 'Secretaría de Movilidad de Puebla');
    expect($r->getContent())->not->toContain('LIC-998877')->not->toContain('folio');
});

test('el numero de la clave de elector solo sale con la bandera del propietario y solo en el contrato 3', function () {
    config(['integraciones.auditoria.enviar_clave_elector' => true]);

    $this->withToken($this->token)->getJson("/api/v1/auditoria/colaboradores/{$this->gestor->id}/acta?contrato=3")
        ->assertOk()->assertJsonPath('data.identificacion.clave_elector', 'PRCHRF94073021H400');

    $v2 = $this->withToken($this->token)->getJson("/api/v1/auditoria/colaboradores/{$this->gestor->id}/acta")
        ->assertOk()->assertJsonPath('contrato', 'people-auditoria/2')
        ->assertJsonPath('data.identificacion', ['clave_elector_registrada' => true]);
    expect($v2->getContent())->not->toContain('PRCHRF94073021H400');
});
