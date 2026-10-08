<?php

use App\Models\SolicitudInterna;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;

/*
 * Fechas calculadas por el backend (FechasSolicitudService):
 *  - incapacidad/permisos: inicio + N días NATURALES (incluye fin de semana);
 *  - vacaciones: días específicos, sin domingos, sin repetidos, sin traslapes.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    $this->travelTo('2026-10-05 09:00:00');
    // 3 años de antigüedad → 16 días de vacaciones.
    $this->persona = clUsuario('colaborador', ['fecha_ingreso' => '2023-06-01']);
    $this->headers = ['Authorization' => 'Bearer '.$this->persona->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
});

test('permiso para faltar: inicio 2026-10-14 y 5 días termina el 2026-10-18, incluyendo sábado y domingo', function () {
    $respuesta = $this->withHeaders($this->headers)->postJson('/api/v1/colaborador/solicitudes', [
        'tipo' => 'permiso',
        'permiso_tipo' => 'faltar',
        'permiso_goce' => 'con_goce',
        'fecha_inicio' => '2026-10-14',
        'duracion_dias' => 5,
    ])->assertCreated();

    expect($respuesta->json('fecha_fin'))->toBe('2026-10-18')
        ->and($respuesta->json('dias_solicitados'))->toBe(5)
        ->and($respuesta->json('modo_fechas'))->toBe('duracion');
});

test('permiso para faltar: una fecha fin capturada a mano no manda; manda la duración', function () {
    $this->withHeaders($this->headers)->postJson('/api/v1/colaborador/solicitudes', [
        'tipo' => 'permiso',
        'permiso_tipo' => 'faltar',
        'permiso_goce' => 'con_goce',
        'fecha_inicio' => '2026-10-14',
        'duracion_dias' => 3,
        'fecha_fin' => '2026-12-31',
    ])->assertCreated()->assertJsonPath('fecha_fin', '2026-10-16');
});

test('permiso para faltar sin número de días se rechaza en español', function () {
    $this->withHeaders($this->headers)->postJson('/api/v1/colaborador/solicitudes', [
        'tipo' => 'permiso',
        'permiso_tipo' => 'faltar',
        'permiso_goce' => 'con_goce',
        'fecha_inicio' => '2026-10-14',
    ])->assertStatus(422)->assertJsonValidationErrors(['duracion_dias' => 'Indica el número de días.']);
});

test('vacaciones: 5 días sueltos (sin el jueves) descuentan 5 y se guardan como días', function () {
    $respuesta = $this->withHeaders($this->headers)->postJson('/api/v1/colaborador/solicitudes', [
        'tipo' => 'vacaciones',
        'motivo' => 'Viaje familiar',
        'dias' => ['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-16', '2026-10-17'],
    ])->assertCreated();

    expect($respuesta->json('dias_solicitados'))->toBe(5)
        ->and($respuesta->json('dias'))->toBe(['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-16', '2026-10-17'])
        ->and($respuesta->json('fecha_inicio'))->toBe('2026-10-12')
        ->and($respuesta->json('fecha_fin'))->toBe('2026-10-17');

    $saldo = $this->withHeaders($this->headers)->getJson('/api/v1/colaborador/vacaciones')->assertOk();
    expect($saldo->json('dias_en_solicitud'))->toBe(5)
        ->and($saldo->json('dias_disponibles'))->toBe(16 - 5);
});

test('vacaciones: el domingo 2026-10-18 se rechaza', function () {
    $this->withHeaders($this->headers)->postJson('/api/v1/colaborador/solicitudes', [
        'tipo' => 'vacaciones',
        'motivo' => 'Viaje',
        'dias' => ['2026-10-17', '2026-10-18'],
    ])->assertStatus(422)->assertJsonValidationErrors('dias');

    expect(SolicitudInterna::query()->count())->toBe(0);
});

test('vacaciones: días repetidos, pasados, ya pedidos o por encima del saldo se rechazan', function () {
    $post = fn (array $dias) => $this->withHeaders($this->headers)->postJson('/api/v1/colaborador/solicitudes', ['tipo' => 'vacaciones', 'motivo' => 'Viaje', 'dias' => $dias]);

    $post(['2026-10-12', '2026-10-12'])->assertStatus(422)->assertJsonValidationErrors('dias');
    $post(['2026-10-01'])->assertStatus(422)->assertJsonValidationErrors('dias');

    $post(['2026-10-12', '2026-10-13'])->assertCreated();
    $post(['2026-10-13', '2026-10-14'])->assertStatus(422)->assertJsonValidationErrors(['dias' => 'Ya tienes vacaciones pedidas o aprobadas el 13/10/2026.']);

    // 14 disponibles: 15 días hábiles (sin domingos) no alcanzan.
    $dias = [];

    for ($d = now()->setDate(2026, 11, 2); count($dias) < 15; $d = $d->addDay()) {
        if (! $d->isSunday()) {
            $dias[] = $d->toDateString();
        }
    }

    $post($dias)->assertStatus(422)->assertJsonValidationErrors('dias');
});

test('app anterior: un rango de vacaciones se traduce a sus días sin domingos', function () {
    // 2026-10-16 (viernes) a 2026-10-19 (lunes): viernes, sábado y lunes = 3 días.
    $this->withHeaders($this->headers)->postJson('/api/v1/colaborador/solicitudes', [
        'tipo' => 'vacaciones',
        'motivo' => 'Puente',
        'fecha_inicio' => '2026-10-16',
        'fecha_fin' => '2026-10-19',
        'dias_solicitados' => 4,
    ])->assertCreated()
        ->assertJsonPath('dias_solicitados', 3)
        ->assertJsonPath('dias', ['2026-10-16', '2026-10-17', '2026-10-19']);
});

test('el catálogo de formularios muestra solo los campos de cada tipo', function () {
    $tipos = collect($this->withHeaders($this->headers)->getJson('/api/v1/solicitudes/configuracion')->assertOk()->json('tipos'))->keyBy('clave');

    expect(array_column($tipos['permiso']['campos'], 'name'))->toBe(['permiso_tipo', 'fecha_inicio', 'duracion_dias', 'hora_salida', 'hora_entrada', 'permiso_goce', 'permiso_causal', 'observaciones'])
        ->and($tipos->keys()->all())->not->toContain('incapacidad', 'constancia_laboral', 'solicitud_general')
        ->and(array_column($tipos['vacaciones']['campos'], 'name'))->toBe(['dias', 'motivo', 'observaciones'])
        ->and($tipos['vacaciones']['dias_no_seleccionables'])->toBe([0])
        ->and(array_column($tipos['prestamo']['campos'], 'name'))->toBe(['monto_solicitado', 'motivo'])
        ->and(collect($tipos)->flatMap(fn ($t) => array_column($t['campos'], 'name'))->contains('fecha_fin'))->toBeFalse();
});
