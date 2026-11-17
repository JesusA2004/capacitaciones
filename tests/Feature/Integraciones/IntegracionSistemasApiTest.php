<?php

use App\Enums\EstadoUsuario;
use App\Enums\EstatusBaja;
use App\Enums\FormaSalida;
use App\Enums\HabilidadIntegracion;
use App\Models\Baja;
use App\Models\ClienteIntegracion;
use App\Models\Colaborador;
use App\Models\ConsultaIntegracion;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\Integraciones\TokenIntegracionService;
use Illuminate\Support\Facades\Artisan;

/**
 * API de integración multi-sistema /api/integracion/v1
 * (docs/API_INTEGRACION_SISTEMAS.md).
 */
function sisToken(array $empresas, array $habilidades, ?int $limite = null, string $clave = 'reportes'): string
{
    return app(TokenIntegracionService::class)->emitir($clave, 'Reportes (prueba)', $habilidades, false, $empresas, $limite)['token'];
}

function sisColaborador(Sucursal $sucursal, Puesto $puesto, array $extra = []): Colaborador
{
    return Colaborador::factory()->create([
        'sucursal_principal_id' => $sucursal->id,
        'puesto_id' => $puesto->id,
        'curp' => 'AAAA000000HDFRRR01',
        'rfc' => 'AAAA000000AA1',
        'nss' => '12345678901',
        'telefono' => '5551234567',
        'correo_personal' => 'personal@example.test',
        'domicilio' => 'Calle Falsa 123',
        'salario_diario' => 500,
        'sueldo_mensual' => 15000,
        'fecha_ingreso' => '2025-01-15',
        ...$extra,
    ]);
}

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 10)->setTime(9, 0));
    $this->empresa = Empresa::factory()->create(['nombre' => 'Mr Lana']);
    $this->otra = Empresa::factory()->create(['nombre' => 'Otra']);
    $this->sucursal = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Córdoba', 'clave' => 'COR01']);
    $this->ajena = Sucursal::factory()->create(['empresa_id' => $this->otra->id, 'nombre' => 'Ajena', 'clave' => 'AJE01']);
    $this->puesto = Puesto::factory()->create(['nombre' => 'Gestor de cobranza']);
});

test('sin token responde 401 y sin la habilidad 403 con la habilidad requerida', function () {
    $this->getJson('/api/integracion/v1/sucursales')->assertStatus(401)->assertJson(['codigo' => 'no_autenticado']);

    $token = sisToken([$this->empresa->id], [HabilidadIntegracion::ColaboradoresLeer->value]);

    $this->withToken($token)->getJson('/api/integracion/v1/rotacion?desde=2026-10-01&hasta=2026-10-31')
        ->assertStatus(403)
        ->assertJson(['codigo' => 'sin_permiso', 'habilidad_requerida' => 'rotacion:leer']);

    // /capacidades sólo exige un token válido.
    $this->withToken($token)->getJson('/api/integracion/v1/capacidades')
        ->assertOk()
        ->assertJsonPath('data.cliente.clave', 'reportes')
        ->assertJsonPath('data.limite_por_minuto', 600);
});

test('un token revocado deja de funcionar y el de auditoria no lee la API de sistemas', function () {
    $token = sisToken([$this->empresa->id], HabilidadIntegracion::paraReportes());
    $auditoria = app(TokenIntegracionService::class)->emitir('auditoria', 'Auditoría', [HabilidadIntegracion::AuditoriaLeer->value], false, [$this->empresa->id])['token'];

    expect($token)->toStartWith('ppl_int_');
    $this->withToken($auditoria)->getJson('/api/integracion/v1/sucursales')->assertStatus(403);

    app(TokenIntegracionService::class)->revocar('reportes');
    $this->withToken($token)->getJson('/api/integracion/v1/sucursales')->assertStatus(401);
});

test('sucursales sólo de las empresas permitidas, con clave y nombre normalizado', function () {
    $token = sisToken([$this->empresa->id], [HabilidadIntegracion::CatalogosLeer->value]);

    $r = $this->withToken($token)->getJson('/api/integracion/v1/sucursales')->assertOk();

    expect($r->json('data'))->toHaveCount(1)
        ->and($r->json('data.0.clave'))->toBe('COR01')
        ->and($r->json('data.0.nombre_normalizado'))->toBe('CORDOBA')
        ->and($r->json('contrato'))->toBe('people-integracion/1');

    $this->withToken($token)->getJson(sprintf('/api/integracion/v1/sucursales?empresa_id=%d', $this->otra->id))->assertStatus(404);
});

test('colaboradores no exponen datos sensibles sin la habilidad separada', function () {
    sisColaborador($this->sucursal, $this->puesto);
    sisColaborador($this->ajena, $this->puesto);
    $token = sisToken([$this->empresa->id], [HabilidadIntegracion::ColaboradoresLeer->value]);

    $r = $this->withToken($token)->getJson('/api/integracion/v1/colaboradores')->assertOk();
    $json = $r->getContent();

    expect($r->json('data'))->toHaveCount(1)
        ->and($r->json('data.0'))->not->toHaveKeys(['identificacion', 'salario', 'curp', 'telefono'])
        ->and($json)->not->toContain('AAAA000000HDFRRR01')
        ->and($json)->not->toContain('12345678901')
        ->and($json)->not->toContain('5551234567')
        ->and($json)->not->toContain('Calle Falsa')
        ->and($json)->not->toContain('15000');

    $this->withToken($token)->getJson('/api/integracion/v1/colaboradores?incluir=identificacion')
        ->assertStatus(403)
        ->assertJson(['habilidad_requerida' => 'colaboradores:identificacion']);
    $this->withToken($token)->getJson(sprintf('/api/integracion/v1/colaboradores?sucursal_id=%d', $this->ajena->id))->assertStatus(404);
});

test('con la habilidad de identificacion y pidiendola explicitamente sale la CURP sin el salario y sin cache', function () {
    sisColaborador($this->sucursal, $this->puesto);
    $token = sisToken([$this->empresa->id], [HabilidadIntegracion::ColaboradoresLeer->value, HabilidadIntegracion::ColaboradoresIdentificacion->value]);

    $r = $this->withToken($token)->getJson('/api/integracion/v1/colaboradores?incluir=identificacion')->assertOk();

    expect($r->json('data.0.identificacion.curp'))->toBe('AAAA000000HDFRRR01')
        ->and($r->json('data.0'))->not->toHaveKey('salario')
        ->and((string) $r->headers->get('Cache-Control'))->toContain('no-store');

    $this->withToken($token)->getJson('/api/integracion/v1/colaboradores?incluir=salario')->assertStatus(403);
});

test('paginacion por cursor y sincronizacion incremental', function () {
    $a = sisColaborador($this->sucursal, $this->puesto);
    $b = sisColaborador($this->sucursal, $this->puesto);
    $token = sisToken([$this->empresa->id], [HabilidadIntegracion::ColaboradoresLeer->value]);

    $p1 = $this->withToken($token)->getJson('/api/integracion/v1/colaboradores?por_pagina=1')->assertOk();
    expect($p1->json('meta.hay_mas'))->toBeTrue();

    $p2 = $this->withToken($token)->getJson('/api/integracion/v1/colaboradores?por_pagina=1&cursor='.urlencode((string) $p1->json('meta.siguiente_cursor')))->assertOk();
    expect([$p1->json('data.0.id'), $p2->json('data.0.id')])->toEqualCanonicalizing([$a->id, $b->id])
        ->and($p2->json('meta.hay_mas'))->toBeFalse();

    // Un cursor de otros filtros se rechaza.
    $this->withToken($token)->getJson('/api/integracion/v1/colaboradores?por_pagina=1&estatus=activos&cursor='.urlencode((string) $p1->json('meta.siguiente_cursor')))
        ->assertStatus(422)->assertJson(['codigo' => 'parametros_invalidos']);

    $this->travel(1)->hours();
    $b->forceFill(['name' => 'Renombrado'])->save();
    $desde = now()->subMinute()->toIso8601String();

    $r = $this->withToken($token)->getJson('/api/integracion/v1/colaboradores?actualizado_desde='.urlencode($desde))->assertOk();
    expect(array_column($r->json('data'), 'id'))->toBe([$b->id]);
});

test('ETag: la misma consulta sin cambios responde 304', function () {
    $token = sisToken([$this->empresa->id], [HabilidadIntegracion::CatalogosLeer->value]);

    $etag = (string) $this->withToken($token)->getJson('/api/integracion/v1/sucursales')->assertOk()->headers->get('ETag');
    expect($etag)->not->toBe('');

    $this->withToken($token)->withHeaders(['If-None-Match' => $etag])->getJson('/api/integracion/v1/sucursales')->assertStatus(304);
});

test('rotacion por periodo y sucursal con datos de factory', function () {
    $sale = sisColaborador($this->sucursal, $this->puesto, ['estatus' => EstadoUsuario::Inactivo->value, 'fecha_baja' => '2026-10-15']);
    sisColaborador($this->sucursal, $this->puesto);
    sisColaborador($this->sucursal, $this->puesto);
    sisColaborador($this->sucursal, $this->puesto);
    sisColaborador($this->sucursal, $this->puesto, ['fecha_ingreso' => '2026-10-10']);
    // Otra empresa: nunca entra en la cuenta.
    sisColaborador($this->ajena, $this->puesto, ['fecha_ingreso' => '2026-10-12']);

    Baja::query()->create([
        'folio' => 'BAJ-T-1', 'colaborador_id' => $sale->id, 'nombre_colaborador' => 'Sale', 'sucursal_id' => $this->sucursal->id,
        'puesto_id' => $this->puesto->id, 'estatus' => EstatusBaja::Cerrada, 'fecha_ingreso' => '2025-01-15', 'fecha_baja' => '2026-10-15',
        'forma_salida' => FormaSalida::RenunciaVoluntaria, 'es_rotacion' => true, 'motivo_original' => 'Texto libre privado',
    ]);

    $token = sisToken([$this->empresa->id], HabilidadIntegracion::paraReportes());

    $r = $this->withToken($token)->getJson('/api/integracion/v1/rotacion?desde=2026-10-01&hasta=2026-10-31')->assertOk();
    $totales = $r->json('data.0.totales');

    expect($totales['plantilla_inicial'])->toBe(4)
        ->and($totales['altas'])->toBe(1)
        ->and($totales['bajas'])->toBe(1)
        ->and($totales['bajas_voluntarias'])->toBe(1)
        ->and($totales['plantilla_final'])->toBe(4)
        ->and((float) $totales['plantilla_promedio'])->toBe(4.0)
        ->and((float) $totales['rotacion_pct'])->toBe(25.0)
        ->and($r->json('data.0.sucursales.0.sucursal.clave'))->toBe('COR01')
        ->and($r->json('meta.fuente_plantilla'))->toBe('reconstruida_por_fechas');

    // Semanas lunes-domingo: la baja cae en la semana del 12 de octubre.
    $s = $this->withToken($token)->getJson('/api/integracion/v1/rotacion?agrupacion=semanal&desde=2026-10-05&hasta=2026-10-25&desglose=total')->assertOk();
    expect(array_column($s->json('data'), 'clave'))->toBe(['S-2026-10-05', 'S-2026-10-12', 'S-2026-10-19'])
        ->and(array_column(array_column($s->json('data'), 'totales'), 'bajas'))->toBe([0, 1, 0])
        ->and($s->json('data.0.sucursales'))->toBeNull();

    $this->withToken($token)->getJson('/api/integracion/v1/rotacion?agrupacion=semanal&desde=2026-10-06&hasta=2026-10-25')
        ->assertStatus(422)->assertJson(['codigo' => 'parametros_invalidos']);

    // Bajas: forma de salida por Enum, sin el motivo en texto libre.
    $b = $this->withToken($token)->getJson('/api/integracion/v1/movimientos/bajas?desde=2026-10-01&hasta=2026-10-31')->assertOk();
    expect($b->json('data'))->toHaveCount(1)
        ->and($b->json('data.0.forma_salida'))->toBe(['valor' => 'RV', 'etiqueta' => 'Renuncia voluntaria'])
        ->and($b->getContent())->not->toContain('Texto libre privado');

    $a = $this->withToken($token)->getJson('/api/integracion/v1/movimientos/altas?desde=2026-10-01&hasta=2026-10-31')->assertOk();
    expect($a->json('data'))->toHaveCount(1)->and($a->json('data.0.fecha_ingreso'))->toBe('2026-10-10');
});

test('el limite por minuto es configurable, por cliente y con encabezados X-RateLimit', function () {
    config()->set('integraciones.sistemas.limite_por_minuto', 2);
    $token = sisToken([$this->empresa->id], [HabilidadIntegracion::CatalogosLeer->value]);

    $r = $this->withToken($token)->getJson('/api/integracion/v1/empresas')->assertOk();
    expect($r->headers->get('X-RateLimit-Limit'))->toBe('2');
    $this->withToken($token)->getJson('/api/integracion/v1/empresas')->assertOk();
    $this->withToken($token)->getJson('/api/integracion/v1/empresas')->assertStatus(429)->assertJson(['codigo' => 'limite_excedido']);

    // Otro sistema con límite propio tiene su propio cubo.
    $otro = sisToken([$this->empresa->id], [HabilidadIntegracion::CatalogosLeer->value], 5, 'tesoreria');
    expect($this->withToken($otro)->getJson('/api/integracion/v1/empresas')->assertOk()->headers->get('X-RateLimit-Limit'))->toBe('5');
});

test('cada consulta queda en la bitacora sin el token', function () {
    $token = sisToken([$this->empresa->id], [HabilidadIntegracion::CatalogosLeer->value]);

    $this->withToken($token)->getJson('/api/integracion/v1/sucursales?empresa_id='.$this->empresa->id)->assertOk();
    $this->withToken($token)->getJson('/api/integracion/v1/colaboradores')->assertStatus(403);

    $consultas = ConsultaIntegracion::query()->orderBy('id')->get();
    $cliente = ClienteIntegracion::query()->where('clave', 'reportes')->firstOrFail();

    expect($consultas)->toHaveCount(2)
        ->and($consultas[0]->cliente_integracion_id)->toBe($cliente->id)
        ->and($consultas[0]->ruta)->toBe('api.integracion.v1.sucursales.index')
        ->and($consultas[0]->estado)->toBe(200)
        ->and($consultas[0]->registros)->toBe(1)
        ->and($consultas[0]->parametros)->toBe(['empresa_id' => (string) $this->empresa->id])
        ->and($consultas[1]->estado)->toBe(403)
        ->and(json_encode($consultas->toArray()))->not->toContain($token);
});

test('el comando emite un token de sistema con el perfil de reportes y valida habilidades', function () {
    expect(Artisan::call('integraciones:clientes', ['accion' => 'emitir', 'clave' => 'reportes', '--perfil' => 'reportes', '--empresa' => [(string) $this->empresa->id], '--limite' => '900']))->toBe(0);
    preg_match('/ppl_int_[A-Za-z0-9]{48}/', Artisan::output(), $m);

    $cliente = ClienteIntegracion::query()->where('clave', 'reportes')->firstOrFail();
    expect($cliente->habilidades)->toBe(HabilidadIntegracion::paraReportes())
        ->and($cliente->limitePorMinuto())->toBe(900);

    $this->withToken($m[0])->getJson('/api/integracion/v1/capacidades')->assertOk()->assertJsonPath('data.limite_por_minuto', 900);

    expect(Artisan::call('integraciones:clientes', ['accion' => 'emitir', 'clave' => 'otro', '--habilidad' => ['inventada:leer'], '--empresa' => [(string) $this->empresa->id]]))->toBe(1)
        ->and(Artisan::call('integraciones:clientes', ['accion' => 'revocar', 'clave' => 'reportes']))->toBe(0);

    $this->withToken($m[0])->getJson('/api/integracion/v1/capacidades')->assertStatus(401);
});
