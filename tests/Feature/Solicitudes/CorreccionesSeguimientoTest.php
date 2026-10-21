<?php

use App\Enums\EstadoSolicitudInterna;
use App\Enums\TipoTarea;
use App\Jobs\SendExpoPushJob;
use App\Models\Colaborador;
use App\Models\MobileDevice;
use App\Models\SolicitudInterna;
use App\Models\TareaRh;
use App\Models\User;
use App\Services\Expedientes\ActualizacionDatosService;
use App\Services\Nomina\PrestamoDisponibleService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

/*
 * Punto 19-G del cierre 2026-10-20: corrección → RH recibe aviso → resuelve
 * → el colaborador recibe aviso (web y app). Más «requiere información»,
 * auditoría antes/después, destinatarios correctos y «préstamo disponible».
 */

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Bus::fake([SendExpoPushJob::class]);
    Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00', 'America/Mexico_City'));

    $estructura = clEstructura();
    $this->estructura = $estructura;
    $this->persona = Colaborador::factory()->create([
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'puesto_id' => $estructura['puesto']->id,
        'curp' => null,
        'telefono' => '7770000000',
    ]);
    $this->cuenta = User::factory()->create(['colaborador_id' => $this->persona->id]);
    $this->cuenta->assignRole('colaborador');
    MobileDevice::factory()->for($this->cuenta, 'usuario')->create(['push_token' => 'ExponentPushToken[correccion]']);

    $this->rh = clUsuario('rh_admin');
    // Nadie más de la empresa debe enterarse: otro colaborador y un jefe sin relación.
    $this->otroColaborador = clUsuario('colaborador');
    $this->jefeAjeno = clUsuario('jefe_directo');
});

afterEach(function () {
    Carbon::setTestNow();
});

function corrNotificaciones(User $usuario): Collection
{
    return $usuario->fresh()->notifications()->get()->map(fn ($n) => $n->data);
}

test('G: corrección → RH recibe aviso con enlace → aprueba → el colaborador recibe aviso web, push y estado en la API', function () {
    $this->actingAs($this->cuenta)
        ->post(route('solicitudes.store'), ['tipo' => 'actualizacion_datos', 'datos' => ['curp' => 'XEXX900101MNEXXXA4', 'telefono' => '7779999999']])
        ->assertSessionHasNoErrors();

    $solicitud = SolicitudInterna::query()->latest('id')->firstOrFail();

    // RH: aviso persistente que dice QUÉ datos (nunca sus valores) y abre la solicitud exacta.
    $avisoRh = corrNotificaciones($this->rh)->firstWhere('type', 'rh_solicitud');
    expect($avisoRh)->not->toBeNull()
        ->and($avisoRh['titulo'])->toBe('Corrección de datos por revisar')
        ->and($avisoRh['mensaje'])->toContain('CURP')->not->toContain('XEXX900101MNEXXXA4')->not->toContain('7779999999')
        ->and($avisoRh['resource_id'])->toBe($solicitud->id);

    $idAviso = $this->rh->fresh()->notifications()->get()->first(fn ($n) => ($n->data['type'] ?? null) === 'rh_solicitud')->id;
    $destino = $this->actingAs($this->rh)->postJson(route('notificaciones.abrir', $idAviso))->assertOk()->json();
    expect(json_encode($destino, JSON_UNESCAPED_SLASHES))->toContain(route('rh.solicitudes.show', $solicitud, false));

    // Pendiente en la bandeja de RH.
    expect(TareaRh::query()->where('tipo', TipoTarea::CorreccionPorRevisar->value)->where('relacionado_id', $solicitud->id)->whereNull('resuelta_en')->exists())->toBeTrue();

    // No se notifica a toda la empresa.
    expect(corrNotificaciones($this->otroColaborador))->toBeEmpty()
        ->and(corrNotificaciones($this->jefeAjeno))->toBeEmpty();

    // Estado para el colaborador en la API (app) antes de resolver.
    Sanctum::actingAs($this->cuenta);
    $this->getJson('/api/v1/correcciones')->assertOk()
        ->assertJsonPath('data.0.estado', 'enviada')
        ->assertJsonPath('meta.conteo.en_proceso', 1);

    $this->actingAs($this->rh)->post(route('rh.solicitudes.aprobar', $solicitud), ['comentario' => 'Validado con su INE.'])->assertSessionHasNoErrors();

    // Expediente actualizado y auditado: antes, después, quién y comentario.
    expect($this->persona->refresh()->curp)->toBe('XEXX900101MNEXXXA4')
        ->and($this->persona->telefono)->toBe('7779999999');

    $auditoria = Activity::query()->where('event', 'expediente_datos_actualizados')->latest('id')->firstOrFail();
    expect($auditoria->causer_id)->toBe($this->rh->id)
        ->and($auditoria->properties['cambios']['telefono'])->toBe(['antes' => '7770000000', 'despues' => '7779999999'])
        ->and($auditoria->properties['cambios']['curp'])->toBe(['antes' => null, 'despues' => 'XEXX900101MNEXXXA4'])
        ->and($auditoria->properties['comentario'])->toBe('Validado con su INE.')
        ->and($auditoria->properties['origen'])->toBe('web');

    // Historial con origen; quién resolvió y cuándo.
    $aprobada = $solicitud->historial()->where('accion', 'aprobada')->firstOrFail();
    expect($aprobada->origen)->toBe('web')->and($aprobada->user_id)->toBe($this->rh->id)
        ->and($solicitud->refresh()->revisado_por)->toBe($this->rh->id);

    // Colaborador: aviso web (campana) + push a su dispositivo.
    $resultado = corrNotificaciones($this->cuenta)->firstWhere('titulo', 'Corrección aprobada');
    expect($resultado)->not->toBeNull()->and($resultado['type'])->toBe('solicitud');
    Bus::assertDispatched(SendExpoPushJob::class);

    // App: estado, quién resolvió, historial y contador.
    Sanctum::actingAs($this->cuenta);
    $api = $this->getJson('/api/v1/correcciones')->assertOk();
    expect($api->json('data.0.estado'))->toBe('aprobada')
        ->and($api->json('data.0.resuelta_por'))->not->toBeNull()
        ->and(collect($api->json('data.0.historial'))->pluck('accion'))->toContain('aprobada')
        ->and($api->json('meta.conteo.abiertas'))->toBe(0);

    $this->getJson('/api/v1/solicitudes/'.$solicitud->id)->assertOk()
        ->assertJsonPath('correccion.estado', 'aprobada')
        ->assertJsonPath('correccion.comentario_rh', 'Validado con su INE.');

    // Pendiente de RH resuelto.
    expect(TareaRh::query()->where('tipo', TipoTarea::CorreccionPorRevisar->value)->where('relacionado_id', $solicitud->id)->whereNull('resuelta_en')->exists())->toBeFalse();
});

test('requiere información: RH pide más datos, el colaborador lo ve con el mensaje, responde desde la app y vuelve a RH', function () {
    Sanctum::actingAs($this->cuenta);
    $this->postJson('/api/v1/solicitudes', ['tipo' => 'actualizacion_datos', 'datos' => ['curp' => 'XEXX900101MNEXXXA4']])->assertCreated();
    $solicitud = SolicitudInterna::query()->latest('id')->firstOrFail();

    $this->actingAs($this->rh)->post(route('rh.solicitudes.requerir-correccion', $solicitud), ['comentario' => 'La CURP no coincide con tu acta.'])->assertSessionHasNoErrors();
    expect($solicitud->refresh()->estado)->toBe(EstadoSolicitudInterna::RequiereCorreccion);

    // El colaborador recibe el mensaje de RH (web y push) y tiene su pendiente.
    $aviso = corrNotificaciones($this->cuenta)->firstWhere('titulo', 'Recursos Humanos necesita más información');
    expect($aviso)->not->toBeNull()->and($aviso['mensaje'])->toContain('La CURP no coincide con tu acta.');
    expect(TareaRh::query()->where('tipo', TipoTarea::CorreccionPorResponder->value)->where('asignado_user_id', $this->cuenta->id)->whereNull('resuelta_en')->exists())->toBeTrue();

    Sanctum::actingAs($this->cuenta);
    $this->getJson('/api/v1/correcciones/resumen')->assertOk()->assertJsonPath('data.requieren_accion', 1);
    $this->getJson('/api/v1/correcciones')->assertOk()
        ->assertJsonPath('data.0.estado', 'requiere_informacion')
        ->assertJsonPath('data.0.comentario_rh', 'La CURP no coincide con tu acta.')
        ->assertJsonPath('data.0.puede_responder', true);
    $this->getJson('/api/v1/mobile/bootstrap')->assertOk()->assertJsonPath('counts.correcciones_pendientes', 1);

    // Otro usuario no puede responder la solicitud ajena.
    Sanctum::actingAs($this->otroColaborador);
    $this->postJson('/api/v1/solicitudes/'.$solicitud->id.'/responder', ['mensaje' => 'Hola'])->assertForbidden();

    // Responde y corrige el dato propuesto.
    $rhNotificacionesAntes = corrNotificaciones($this->rh)->count();
    Sanctum::actingAs($this->cuenta);
    $this->postJson('/api/v1/solicitudes/'.$solicitud->id.'/responder', ['mensaje' => 'Corregí la CURP, era la del acta nueva.', 'datos' => ['curp' => 'XEXX900101HNEXXXA4']])
        ->assertOk()->assertJsonPath('data.estado', 'enviada');

    $solicitud->refresh();
    expect($solicitud->estado)->toBe(EstadoSolicitudInterna::Enviada)
        ->and($solicitud->datos_propuestos)->toBe(['curp' => 'XEXX900101HNEXXXA4'])
        ->and($solicitud->historial()->where('accion', 'respuesta_colaborador')->value('origen'))->toBe('app');

    // RH recibe un aviso NUEVO (no se pierde por idempotencia) y su pendiente se reabre.
    expect(corrNotificaciones($this->rh)->count())->toBe($rhNotificacionesAntes + 1)
        ->and(corrNotificaciones($this->rh)->firstWhere('titulo', 'Corrección respondida por revisar'))->not->toBeNull();
    expect(TareaRh::query()->where('tipo', TipoTarea::CorreccionPorRevisar->value)->where('relacionado_id', $solicitud->id)->whereNull('resuelta_en')->exists())->toBeTrue()
        ->and(TareaRh::query()->where('tipo', TipoTarea::CorreccionPorResponder->value)->whereNull('resuelta_en')->exists())->toBeFalse();

    // Responder dos veces no procede: ya no está en «requiere información».
    $this->postJson('/api/v1/solicitudes/'.$solicitud->id.'/responder', ['mensaje' => 'Otra vez'])->assertUnprocessable()->assertJsonValidationErrors('mensaje');

    // Al rechazar, el colaborador recibe el motivo.
    $this->actingAs($this->rh)->post(route('rh.solicitudes.rechazar', $solicitud), ['motivo_rechazo' => 'Trae tu CURP impresa a RH.'])->assertSessionHasNoErrors();
    expect(corrNotificaciones($this->cuenta)->firstWhere('titulo', 'Corrección rechazada')['mensaje'] ?? '')->toContain('Trae tu CURP impresa a RH.');
});

test('datos médicos: viajan cifrados, no aparecen en avisos ni a quien no puede verlos, y se aplican auditados sin su valor', function () {
    $this->actingAs($this->cuenta)
        ->post(route('solicitudes.store'), ['tipo' => 'actualizacion_datos', 'datos' => ['alergias' => 'Penicilina']])
        ->assertSessionHasNoErrors();

    $solicitud = SolicitudInterna::query()->latest('id')->firstOrFail();
    $crudo = (array) DB::table('solicitudes_internas')->where('id', $solicitud->id)->first();

    expect($solicitud->datos_sensibles_propuestos)->toBe(['alergias' => 'Penicilina'])
        ->and($solicitud->datos_propuestos)->toBeNull()
        ->and(json_encode($crudo))->not->toContain('Penicilina');

    // El aviso a RH no dice el dato médico (ni siquiera cuál).
    $avisoRh = corrNotificaciones($this->rh)->firstWhere('type', 'rh_solicitud');
    expect($avisoRh['mensaje'])->toContain('Datos médicos (confidencial)')->not->toContain('Penicilina')->not->toContain('Alergias');

    // El colaborador ve lo que propuso; quien no tiene expedientes.datos_medicos.ver, no.
    $this->actingAs($this->cuenta)->get(route('solicitudes.show', $solicitud))
        ->assertInertia(fn ($page) => $page->where('datosPropuestos.0.propuesto', 'Penicilina')->missing('solicitud.datos_sensibles_propuestos'));

    $sinPermiso = clUsuario('jefe_directo');
    expect(app(ActualizacionDatosService::class)->comparativo($solicitud, $sinPermiso)[0]['propuesto'])->toBe('Dato confidencial');

    $this->actingAs($this->rh)->post(route('rh.solicitudes.aprobar', $solicitud))->assertSessionHasNoErrors();

    expect($this->persona->refresh()->datosMedicos?->alergias)->toBe('Penicilina');
    $auditoria = Activity::query()->where('event', 'expediente_datos_actualizados')->latest('id')->firstOrFail();
    expect(json_encode($auditoria->properties))->not->toContain('Penicilina')
        ->and($auditoria->properties['cambios']['alergias']['despues'])->toBe('Dato confidencial');
});

test('préstamo disponible: aviso único al cumplir 6 meses, solo al colaborador, idempotente', function () {
    $this->persona->update(['fecha_ingreso' => '2026-04-20', 'estatus' => 'activo']);
    $nuevo = Colaborador::factory()->create(['fecha_ingreso' => '2026-05-21', 'estatus' => 'activo']);
    $cuentaNueva = User::factory()->create(['colaborador_id' => $nuevo->id]);
    $cuentaNueva->assignRole('colaborador');

    $servicio = app(PrestamoDisponibleService::class);
    $primero = $servicio->avisar();
    $segundo = $servicio->avisar();
    $this->artisan('prestamos:avisar-disponibles')->assertSuccessful();

    expect($primero['avisados'])->toBe(1)
        ->and($segundo['avisados'])->toBe(0);

    $avisos = corrNotificaciones($this->cuenta)->where('type', 'prestamo_disponible');
    expect($avisos)->toHaveCount(1)
        ->and($avisos->first()['mensaje'])->toContain('20/10/2026');

    // Con menos de 6 meses no hay aviso; RH y el resto tampoco reciben nada.
    expect(corrNotificaciones($cuentaNueva))->toBeEmpty()
        ->and(corrNotificaciones($this->rh)->where('type', 'prestamo_disponible'))->toBeEmpty()
        ->and(corrNotificaciones($this->otroColaborador))->toBeEmpty();
});
