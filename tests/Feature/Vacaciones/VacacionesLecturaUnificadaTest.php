<?php

use App\Enums\EstadoSolicitudInterna;
use App\Enums\TipoSolicitudInterna;
use App\Models\SolicitudInterna;
use App\Models\SolicitudVacaciones;
use App\Models\User;
use App\Services\Solicitudes\SolicitudesService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
 * La ESCRITURA de vacaciones ya vive en `solicitudes_internas` (motor
 * unificado). Estas pruebas blindan que TODAS las superficies de LECTURA
 * móvil lean esa fuente viva — nunca la tabla legacy `solicitudes_vacaciones`,
 * que ya no recibe altas — para que una solicitud recién creada aparezca al
 * instante en todas ellas, y que el saldo no se pueda rebasar por concurrencia.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
});

function tokenBearerVac(User $u): array
{
    auth()->forgetGuards();

    return ['Authorization' => 'Bearer '.$u->createToken('t')->plainTextToken];
}

/**
 * Próximos $n días computables (sin domingos) a partir de $desde, como Y-m-d.
 *
 * @return list<string>
 */
function diasComputablesVac(int $n, CarbonImmutable $desde): array
{
    $dias = [];
    $cursor = $desde;

    while (count($dias) < $n) {
        if ($cursor->dayOfWeek !== 0) {
            $dias[] = $cursor->toDateString();
        }
        $cursor = $cursor->addDay();
    }

    return $dias;
}

test('una vacación recién creada se ve al instante en TODAS las superficies de lectura móvil', function () {
    $colaborador = clUsuario('colaborador', ['fecha_ingreso' => now()->subYears(3)]);
    $rh = clUsuario('rh_admin');

    // POST por el endpoint legacy de la app (que ya manda al motor unificado).
    $creacion = $this->withHeaders(tokenBearerVac($colaborador))
        ->postJson('/api/v1/vacaciones/solicitudes', [
            'fecha_inicio' => now()->addWeek()->next(CarbonImmutable::MONDAY)->toDateString(),
            'fecha_fin' => now()->addWeek()->next(CarbonImmutable::MONDAY)->addDays(2)->toDateString(),
            'dias_solicitados' => 3,
            'comentario' => 'Viaje',
        ])
        ->assertCreated();

    $id = $creacion->json('solicitud_id');

    // 0) Se creó en solicitudes_internas, NO en la tabla legacy.
    expect(SolicitudInterna::query()->whereKey($id)->value('tipo'))->toBe(TipoSolicitudInterna::Vacaciones)
        ->and(SolicitudVacaciones::query()->count())->toBe(0);

    // 1) GET móvil de vacaciones del propio colaborador.
    $mias = $this->withHeaders(tokenBearerVac($colaborador))
        ->getJson('/api/v1/vacaciones/solicitudes')
        ->assertOk();
    expect(collect($mias->json('data'))->pluck('solicitud_id'))->toContain($id);

    // 2) Bandeja RH móvil unificada (/rh/pendientes): la cuenta de vacaciones
    //    sube y la solicitud aparece en el listado.
    $pendientes = $this->withHeaders(tokenBearerVac($rh))
        ->getJson('/api/v1/rh/pendientes')
        ->assertOk();
    expect($pendientes->json('meta.vacaciones'))->toBe(1)
        ->and(collect($pendientes->json('data'))->pluck('resource_id'))->toContain($id);

    // 3) Bandeja RH unificada por id (/rh/solicitudes): mismo registro.
    $solicitudes = $this->withHeaders(tokenBearerVac($rh))
        ->getJson('/api/v1/rh/solicitudes')
        ->assertOk();
    expect(collect($solicitudes->json('data'))->pluck('resource_id'))->toContain($id);

    // 4) Conteo del dashboard móvil de RH.
    $dashboard = $this->withHeaders(tokenBearerVac($rh))
        ->getJson('/api/v1/rh/dashboard')
        ->assertOk();
    expect($dashboard->json('resumen.vacaciones'))->toBe(1);
});

test('baja_colaborador NO aparece en la bandeja de pendientes móvil', function () {
    $rh = clUsuario('rh_admin');

    SolicitudInterna::factory()->create(['estado' => 'enviada', 'tipo' => 'vacaciones']);
    SolicitudInterna::factory()->create(['estado' => 'enviada', 'tipo' => 'baja_colaborador']);

    $respuesta = $this->withHeaders(tokenBearerVac($rh))
        ->getJson('/api/v1/rh/pendientes')
        ->assertOk();

    expect($respuesta->json('meta.total'))->toBe(1)
        ->and(collect($respuesta->json('data'))->pluck('titulo'))->not->toContain('Baja de colaborador');
});

test('/rh/pendientes y /rh/solicitudes devuelven el MISMO conjunto para el mismo alcance', function () {
    $rh = clUsuario('rh_admin');

    SolicitudInterna::factory()->count(3)->create(['estado' => 'enviada']);
    SolicitudInterna::factory()->count(2)->create(['estado' => 'enviada', 'tipo' => 'vacaciones']);
    // La baja nunca entra a la bandeja de pendientes (Cierre laboral).
    SolicitudInterna::factory()->create(['estado' => 'enviada', 'tipo' => 'baja_colaborador']);

    $idsPendientes = collect($this->withHeaders(tokenBearerVac($rh))->getJson('/api/v1/rh/pendientes?per_page=100')->json('data'))
        ->pluck('resource_id')->sort()->values()->all();

    $idsSolicitudes = collect($this->withHeaders(tokenBearerVac($rh))->getJson('/api/v1/rh/solicitudes?per_page=100')->json('data'))
        ->pluck('resource_id')
        // /rh/solicitudes sí incluye la baja (se consulta, no se acciona);
        // la bandeja de pendientes la excluye a propósito.
        ->filter(fn ($id) => SolicitudInterna::query()->whereKey($id)->value('tipo') !== TipoSolicitudInterna::BajaColaborador)
        ->sort()->values()->all();

    expect($idsPendientes)->toBe($idsSolicitudes)
        ->and($idsPendientes)->toHaveCount(5);
});

test('dos solicitudes concurrentes del mismo colaborador nunca rebasan el saldo', function () {
    // 3 años → 16 días generados (config/vacaciones.php).
    $colaborador = clUsuario('colaborador', ['fecha_ingreso' => now()->subYears(3)]);
    $servicio = app(SolicitudesService::class);

    $base = CarbonImmutable::tomorrow();
    $primeros = diasComputablesVac(10, $base);
    $siguientes = diasComputablesVac(10, CarbonImmutable::parse(end($primeros))->addDay());

    // Primera solicitud: 10 días, deja 6 disponibles.
    $servicio->crear($colaborador, ['tipo' => 'vacaciones', 'dias' => $primeros, 'motivo' => 'A']);

    // Segunda: otros 10 días distintos (sin traslape) → debe rebasar el saldo
    // reservado por la primera y ser rechazada DENTRO de la transacción.
    expect(fn () => $servicio->crear($colaborador, ['tipo' => 'vacaciones', 'dias' => $siguientes, 'motivo' => 'B']))
        ->toThrow(ValidationException::class);

    $totalPedido = (int) SolicitudInterna::query()
        ->where('tipo', TipoSolicitudInterna::Vacaciones)
        ->whereIn('estado', [EstadoSolicitudInterna::Enviada, EstadoSolicitudInterna::EnRevision])
        ->sum('dias_solicitados');

    expect(SolicitudInterna::query()->where('tipo', 'vacaciones')->count())->toBe(1)
        ->and($totalPedido)->toBeLessThanOrEqual(16);
});
