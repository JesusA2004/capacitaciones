<?php

use App\Enums\EstadoUsuario;
use App\Models\ClienteIntegracion;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Integraciones\TokenIntegracionService;
use App\Services\Solicitudes\BajaColaboradorService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Artisan;

/**
 * API servidor-a-servidor People → Auditoría (contrato «people-auditoria/1»).
 */
function audToken(array $empresas = [], bool $todas = false, array $habilidades = [TokenIntegracionService::HABILIDAD_AUDITORIA_LEER]): string
{
    return app(TokenIntegracionService::class)->emitir('auditoria', 'Auditoría (prueba)', $habilidades, $todas, $empresas)['token'];
}

/**
 * @return array{empresa: Empresa, otra: Empresa, sucursal: Sucursal, sucursal2: Sucursal, ajena: Sucursal, puesto: Puesto, puesto2: Puesto}
 */
function audEstructura(): array
{
    $empresa = Empresa::factory()->create(['nombre' => 'Productos y Servicios Mr Lana']);
    $otra = Empresa::factory()->create(['nombre' => 'Otra empresa']);

    return [
        'empresa' => $empresa,
        'otra' => $otra,
        'sucursal' => Sucursal::factory()->create(['empresa_id' => $empresa->id, 'nombre' => 'Cordoba', 'clave' => 'COR01']),
        'sucursal2' => Sucursal::factory()->create(['empresa_id' => $empresa->id, 'nombre' => 'Orizaba', 'clave' => 'ORI01']),
        'ajena' => Sucursal::factory()->create(['empresa_id' => $otra->id, 'nombre' => 'Ajena', 'clave' => 'AJE01']),
        'puesto' => Puesto::factory()->create(['nombre' => 'Gestor de cobranza']),
        'puesto2' => Puesto::factory()->create(['nombre' => 'Gerente de sucursal']),
    ];
}

function audColaborador(Sucursal $sucursal, Puesto $puesto, array $extra = []): Colaborador
{
    return Colaborador::factory()->create([
        'sucursal_principal_id' => $sucursal->id,
        'puesto_id' => $puesto->id,
        'curp' => 'AAAA000000HDFRRR01',
        'rfc' => 'AAAA000000AA1',
        'nss' => '12345678901',
        'correo_personal' => 'personal@example.test',
        'telefono' => '5551234567',
        'domicilio' => 'Calle Falsa 123',
        'salario_diario' => 500,
        'sueldo_mensual' => 15000,
        ...$extra,
    ]);
}

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 1)->setTime(8, 0));
    $this->e = audEstructura();
});

test('sin token responde 401 con codigo', function () {
    $this->getJson('/api/v1/auditoria/catalogo')
        ->assertStatus(401)
        ->assertJson(['codigo' => 'no_autenticado'])
        ->assertJsonStructure(['message', 'codigo']);
});

test('un token invalido o revocado responde 401', function () {
    $token = audToken([$this->e['empresa']->id]);

    $this->withToken('ppl_aud_inventado')->getJson('/api/v1/auditoria/catalogo')->assertStatus(401);
    $this->withToken($token.'x')->getJson('/api/v1/auditoria/catalogo')->assertStatus(401);

    app(TokenIntegracionService::class)->revocar('auditoria');
    $this->withToken($token)->getJson('/api/v1/auditoria/catalogo')->assertStatus(401);
});

test('un token Sanctum de usuario movil normal no sirve en la API de integracion', function () {
    $this->seed(RolesYPermisosSeeder::class);
    $usuario = User::factory()->create();
    $usuario->assignRole('super_admin');
    $tokenMovil = $usuario->createToken('app')->plainTextToken;

    $this->withToken($tokenMovil)->getJson('/api/v1/auditoria/colaboradores')->assertStatus(401)->assertJson(['codigo' => 'no_autenticado']);
});

test('el token de integracion no sirve en la API movil', function () {
    $token = audToken([$this->e['empresa']->id]);

    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
});

test('sin la habilidad auditoria:leer responde 403', function () {
    $token = audToken([$this->e['empresa']->id], false, ['otra:cosa']);

    $this->withToken($token)->getJson('/api/v1/auditoria/catalogo')
        ->assertStatus(403)
        ->assertJson(['codigo' => 'sin_permiso']);
});

test('el token se guarda solo como hash y rotar invalida el anterior', function () {
    $token = audToken([$this->e['empresa']->id]);
    $cliente = ClienteIntegracion::query()->firstOrFail();

    expect($cliente->token_hash)->toBe(hash('sha256', $token))
        ->and(ClienteIntegracion::query()->where('token_hash', $token)->exists())->toBeFalse();

    $nuevo = audToken([$this->e['empresa']->id]);

    $this->withToken($token)->getJson('/api/v1/auditoria/catalogo')->assertStatus(401);
    $this->withToken($nuevo)->getJson('/api/v1/auditoria/catalogo')->assertOk();
});

test('el catalogo lista solo las empresas permitidas', function () {
    $token = audToken([$this->e['empresa']->id]);

    $this->withToken($token)->getJson('/api/v1/auditoria/catalogo')
        ->assertOk()
        ->assertJsonPath('contrato', 'people-auditoria/1')
        ->assertJsonPath('capacidades', ['colaboradores', 'delta_updated_since', 'sucursales'])
        ->assertJsonCount(1, 'empresas')
        ->assertJsonPath('empresas.0.id', $this->e['empresa']->id)
        ->assertJsonPath('empresas.0.nombre', 'Productos y Servicios Mr Lana');
});

test('el listado devuelve la forma del contrato sin campos sensibles', function () {
    $c = audColaborador($this->e['sucursal'], $this->e['puesto'], ['name' => 'Ana', 'apellidos' => 'López García', 'numero_empleado' => 'EMP-0123', 'clave_legacy' => '00123', 'fecha_ingreso' => '2024-03-01']);
    audColaborador($this->e['ajena'], $this->e['puesto']);
    $token = audToken([$this->e['empresa']->id]);

    $respuesta = $this->withToken($token)->getJson('/api/v1/auditoria/colaboradores')->assertOk();

    $respuesta->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $c->id)
        ->assertJsonPath('data.0.numero_empleado', 'EMP-0123')
        ->assertJsonPath('data.0.clave_noi', '00123')
        ->assertJsonPath('data.0.nombres', 'Ana')
        ->assertJsonPath('data.0.apellidos', 'López García')
        ->assertJsonPath('data.0.apellido_paterno', null)
        ->assertJsonPath('data.0.nombre_completo', 'Ana López García')
        ->assertJsonPath('data.0.empresa', ['id' => $this->e['empresa']->id, 'nombre' => 'Productos y Servicios Mr Lana'])
        ->assertJsonPath('data.0.sucursal', ['id' => $this->e['sucursal']->id, 'clave' => 'COR01', 'nombre' => 'Cordoba'])
        ->assertJsonPath('data.0.puesto', ['id' => $this->e['puesto']->id, 'nombre' => 'Gestor de cobranza'])
        ->assertJsonPath('data.0.activo', true)
        ->assertJsonPath('data.0.estatus', 'activo')
        ->assertJsonPath('data.0.fecha_alta', '2024-03-01')
        ->assertJsonPath('data.0.fecha_baja', null)
        ->assertJsonPath('meta.has_more', false)
        ->assertJsonPath('meta.next_cursor', null)
        ->assertJsonPath('meta.per_page', 100)
        ->assertJsonStructure(['meta' => ['server_time']]);

    $fila = $respuesta->json('data.0');
    expect(array_keys($fila))->toBe([
        'id', 'numero_empleado', 'clave_noi', 'nombres', 'apellido_paterno', 'apellido_materno', 'apellidos',
        'nombre_completo', 'empresa', 'sucursal', 'puesto', 'estatus', 'activo', 'fecha_alta', 'fecha_baja', 'updated_at',
    ]);

    $sensibles = ['curp', 'rfc', 'nss', 'sueldo_mensual', 'salario_diario', 'salario_diario_integrado', 'telefono', 'telefono_corporativo',
        'correo_personal', 'correo_corporativo', 'domicilio', 'foto_path', 'foto_url', 'expediente_storage_path', 'contacto_emergencia_nombre',
        'contacto_emergencia_telefono', 'fecha_nacimiento', 'contratos', 'documentos', 'datos_medicos', 'user', 'password', 'email'];
    foreach ($sensibles as $clave) {
        expect($fila)->not->toHaveKey($clave);
    }

    $json = $respuesta->getContent();
    expect($json)->not->toContain('AAAA000000HDFRRR01')->not->toContain('12345678901')->not->toContain('personal@example.test')->not->toContain('5551234567')->not->toContain('Calle Falsa');

    $this->withToken($token)->getJson("/api/v1/auditoria/colaboradores/{$c->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $c->id)
        ->assertJsonPath('data.updated_at', $fila['updated_at']);
});

test('detalle de otra empresa o inexistente responde 404', function () {
    $ajeno = audColaborador($this->e['ajena'], $this->e['puesto']);
    $token = audToken([$this->e['empresa']->id]);

    $this->withToken($token)->getJson("/api/v1/auditoria/colaboradores/{$ajeno->id}")->assertNotFound()->assertJson(['codigo' => 'no_encontrado']);
    $this->withToken($token)->getJson('/api/v1/auditoria/colaboradores/999999')->assertNotFound();
    $this->withToken($token)->getJson("/api/v1/auditoria/colaboradores?empresa_id={$this->e['otra']->id}")->assertNotFound()->assertJson(['codigo' => 'empresa_no_encontrada']);
});

test('parametros invalidos responden 422 con codigo', function () {
    $token = audToken([$this->e['empresa']->id]);

    $this->withToken($token)->getJson('/api/v1/auditoria/colaboradores?per_page=500')->assertStatus(422)->assertJson(['codigo' => 'parametros_invalidos'])->assertJsonValidationErrors(['per_page']);
    $this->withToken($token)->getJson('/api/v1/auditoria/colaboradores?updated_since=ayer')->assertStatus(422)->assertJsonValidationErrors(['updated_since']);
    $this->withToken($token)->getJson('/api/v1/auditoria/colaboradores?cursor=basura')->assertStatus(422)->assertJsonValidationErrors(['cursor']);
});

test('paginacion por cursor estable sin repetir ni saltar', function () {
    $ids = [];
    for ($i = 0; $i < 5; $i++) {
        $ids[] = audColaborador($this->e['sucursal'], $this->e['puesto'])->id;
    }
    $token = audToken([$this->e['empresa']->id]);

    $vistos = [];
    $url = '/api/v1/auditoria/colaboradores?per_page=2';
    $paginas = 0;
    do {
        $r = $this->withToken($token)->getJson($url)->assertOk();
        $vistos = [...$vistos, ...array_column($r->json('data'), 'id')];
        $cursor = $r->json('meta.next_cursor');
        expect($r->json('meta.has_more'))->toBe($cursor !== null);
        $url = '/api/v1/auditoria/colaboradores?per_page=2&cursor='.urlencode((string) $cursor);
        $paginas++;
    } while ($cursor !== null && $paginas < 10);

    expect($paginas)->toBe(3)->and($vistos)->toBe($ids);

    // Un cursor no sirve con otros filtros.
    $primero = $this->withToken($token)->getJson('/api/v1/auditoria/colaboradores?per_page=2')->json('meta.next_cursor');
    $this->withToken($token)->getJson('/api/v1/auditoria/colaboradores?per_page=2&updated_since=2026-01-01T00:00:00Z&cursor='.urlencode($primero))->assertStatus(422);
});

test('delta updated_since: baja sigue listada con activo false, reingreso, cambio de sucursal y de puesto', function () {
    $this->seed(RolesYPermisosSeeder::class);
    $sinCambio = audColaborador($this->e['sucursal'], $this->e['puesto']);
    $baja = audColaborador($this->e['sucursal'], $this->e['puesto']);
    $reingreso = audColaborador($this->e['sucursal'], $this->e['puesto']);
    $muda = audColaborador($this->e['sucursal'], $this->e['puesto']);
    $asciende = audColaborador($this->e['sucursal'], $this->e['puesto']);
    $token = audToken([$this->e['empresa']->id]);
    $actor = User::factory()->create();

    $servicioBaja = app(BajaColaboradorService::class);
    $servicioBaja->ejecutar($reingreso, $actor, 'Prueba');

    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(9, 0));
    $corte = '2026-10-10T09:00:00+00:00';

    $servicioBaja->ejecutar($baja, $actor, 'Renuncia');
    $servicioBaja->reactivar(Colaborador::withTrashed()->findOrFail($reingreso->id));
    $muda->update(['sucursal_principal_id' => $this->e['sucursal2']->id]);
    $asciende->update(['puesto_id' => $this->e['puesto2']->id]);

    // Desde el corte: los cuatro cambios, nunca el que no cambió.
    $r = $this->withToken($token)->getJson('/api/v1/auditoria/colaboradores?updated_since='.urlencode($corte))->assertOk();
    $porId = collect($r->json('data'))->keyBy('id');

    expect($porId->keys()->sort()->values()->all())->toBe(collect([$baja->id, $reingreso->id, $muda->id, $asciende->id])->sort()->values()->all())
        ->and($porId[$baja->id]['activo'])->toBeFalse()
        ->and($porId[$baja->id]['estatus'])->toBe(EstadoUsuario::Inactivo->value)
        ->and($porId[$baja->id]['fecha_baja'])->toBe('2026-10-10')
        ->and($porId[$reingreso->id]['activo'])->toBeTrue()
        ->and($porId[$reingreso->id]['fecha_baja'])->toBeNull()
        ->and($porId[$muda->id]['sucursal']['id'])->toBe($this->e['sucursal2']->id)
        ->and($porId[$asciende->id]['puesto']['nombre'])->toBe('Gerente de sucursal')
        ->and($porId[$baja->id]['updated_at'])->toBe($corte);

    // La baja nunca desaparece del listado completo ni del detalle.
    $completo = collect($this->withToken($token)->getJson('/api/v1/auditoria/colaboradores')->json('data'))->pluck('id');
    expect($completo)->toContain($baja->id)->toContain($sinCambio->id);
    $this->withToken($token)->getJson("/api/v1/auditoria/colaboradores/{$baja->id}")->assertOk()->assertJsonPath('data.activo', false);
});

test('renombrar el puesto o la sucursal o cambiar la sucursal de empresa avanza el updated_at efectivo', function () {
    $c = audColaborador($this->e['sucursal'], $this->e['puesto']);
    $otro = audColaborador($this->e['sucursal2'], $this->e['puesto2']);
    $token = audToken([], true);

    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));
    $corte = '2026-10-15T12:00:00Z';
    $this->e['puesto']->update(['nombre' => 'Gestor de campo']);

    $r = $this->withToken($token)->getJson('/api/v1/auditoria/colaboradores?updated_since='.$corte)->assertOk();
    expect(array_column($r->json('data'), 'id'))->toBe([$c->id])
        ->and($r->json('data.0.puesto.nombre'))->toBe('Gestor de campo')
        ->and($r->json('data.0.updated_at'))->toBe('2026-10-15T12:00:00+00:00');

    $this->travelTo(now()->setDate(2026, 10, 16)->setTime(12, 0));
    $this->e['sucursal2']->update(['empresa_id' => $this->e['otra']->id]);

    $r = $this->withToken($token)->getJson('/api/v1/auditoria/colaboradores?updated_since=2026-10-16T12:00:00Z')->assertOk();
    expect(array_column($r->json('data'), 'id'))->toBe([$otro->id])
        ->and($r->json('data.0.empresa.id'))->toBe($this->e['otra']->id);

    // «+» sin codificar en la URL llega como espacio: se acepta igual.
    $this->withToken($token)->getJson('/api/v1/auditoria/colaboradores?updated_since=2026-10-16T18:00:00+06:00')->assertOk()->assertJsonCount(1, 'data');
});

test('filtro empresa_id y sucursales sin datos sensibles', function () {
    audColaborador($this->e['sucursal'], $this->e['puesto']);
    $this->e['sucursal2']->delete();
    $token = audToken([], true);

    $this->withToken($token)->getJson("/api/v1/auditoria/colaboradores?empresa_id={$this->e['otra']->id}")->assertOk()->assertJsonCount(0, 'data');

    $r = $this->withToken($token)->getJson("/api/v1/auditoria/sucursales?empresa_id={$this->e['empresa']->id}")->assertOk();
    $r->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.clave', 'COR01')
        ->assertJsonPath('data.0.activo', true)
        ->assertJsonPath('data.1.activo', false);
    expect(array_keys($r->json('data.0')))->toBe(['id', 'empresa_id', 'clave', 'nombre', 'activo', 'updated_at'])
        ->and($r->getContent())->not->toContain('telefono')->not->toContain('direccion');
});

test('rate limit propio responde 429 con Retry-After y codigo', function () {
    config()->set('integraciones.auditoria.limite_por_minuto', 2);
    $token = audToken([$this->e['empresa']->id]);

    $this->withToken($token)->getJson('/api/v1/auditoria/catalogo')->assertOk();
    $this->withToken($token)->getJson('/api/v1/auditoria/catalogo')->assertOk();
    $r = $this->withToken($token)->getJson('/api/v1/auditoria/catalogo')->assertStatus(429)->assertJson(['codigo' => 'limite_excedido']);

    expect((int) $r->headers->get('Retry-After'))->toBeGreaterThan(0);
});

test('los intentos fallidos repetidos se limitan por IP', function () {
    for ($i = 0; $i < 20; $i++) {
        $this->withToken('ppl_aud_malo'.$i)->getJson('/api/v1/auditoria/catalogo')->assertStatus(401);
    }

    $r = $this->withToken('ppl_aud_malo')->getJson('/api/v1/auditoria/catalogo')->assertStatus(429)->assertJson(['codigo' => 'limite_excedido']);
    expect((int) $r->headers->get('Retry-After'))->toBeGreaterThan(0);
});

test('el comando emite el token una vez, exige empresas explicitas, rota y revoca', function () {
    expect(Artisan::call('integraciones:token-auditoria', ['accion' => 'emitir']))->toBe(1);

    Artisan::call('integraciones:token-auditoria', ['accion' => 'emitir', '--empresa' => [(string) $this->e['empresa']->id]]);
    preg_match('/ppl_aud_[A-Za-z0-9]{48}/', Artisan::output(), $m);
    $token = $m[0] ?? '';
    expect($token)->not->toBe('');
    $this->withToken($token)->getJson('/api/v1/auditoria/catalogo')->assertOk()->assertJsonCount(1, 'empresas');

    // emitir otra vez con uno activo se rechaza; estado no muestra el token.
    expect(Artisan::call('integraciones:token-auditoria', ['accion' => 'emitir', '--empresa' => [(string) $this->e['empresa']->id]]))->toBe(1);
    Artisan::call('integraciones:token-auditoria', ['accion' => 'estado']);
    expect(Artisan::output())->not->toContain($token);

    Artisan::call('integraciones:token-auditoria', ['accion' => 'rotar']);
    preg_match('/ppl_aud_[A-Za-z0-9]{48}/', Artisan::output(), $m2);
    $this->withToken($token)->getJson('/api/v1/auditoria/catalogo')->assertStatus(401);
    $this->withToken($m2[0])->getJson('/api/v1/auditoria/catalogo')->assertOk()->assertJsonCount(1, 'empresas');

    Artisan::call('integraciones:token-auditoria', ['accion' => 'revocar']);
    $this->withToken($m2[0])->getJson('/api/v1/auditoria/catalogo')->assertStatus(401);
});
