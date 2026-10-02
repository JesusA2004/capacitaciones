<?php

use App\Models\Colaborador;
use App\Models\ReglaNotificacion;
use App\Models\SolicitudInterna;
use App\Models\Sucursal;
use App\Models\TareaRh;
use App\Models\User;
use App\Notifications\Mobile\PendienteRhNotification;
use App\Notifications\Mobile\RhSolicitudCreadaNotification;
use App\Services\Configuracion\WorkflowRoutingService;
use App\Services\MobilePush\PushNotifier;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

/*
| Administración → Configuración: organigrama de jefes (escenarios A–F),
| ruteo de notificaciones, apariencia/tema y parámetros de RH.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->sucursal = Sucursal::factory()->create();
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $this->sucursal->id]);
    $this->admin = clUsuario('super_admin');

    // ROL ≠ JEFE: los jefes tienen el rol más básico; manda la relación.
    $this->jefeA = clUsuario('colaborador', ['sucursal_principal_id' => $this->sucursal->id]);
    $this->jefeB = clUsuario('colaborador', ['sucursal_principal_id' => $this->sucursal->id]);
    $this->empleado = clUsuario('colaborador', ['sucursal_principal_id' => $this->sucursal->id, 'jefe_id' => $this->jefeA->colaborador_id]);
});

function avisoDe(string $tipo): Closure
{
    return fn (PendienteRhNotification $n, array $canales, User $destino) => $n->toDatabase($destino)['tipo'] === $tipo;
}

function pedirPrestamo(User $usuario): int
{
    Sanctum::actingAs($usuario);

    return (int) test()->postJson('/api/v1/solicitudes', ['tipo' => 'prestamo', 'monto_solicitado' => 5000, 'motivo' => 'Gastos escolares'])
        ->assertCreated()
        ->json('id');
}

test('A–B: la solicitud llega al jefe directo; al cambiarlo, la nueva llega al jefe nuevo y la anterior conserva al original', function () {
    $vieja = pedirPrestamo($this->empleado);

    Notification::assertSentTo($this->jefeA, PendienteRhNotification::class, function (PendienteRhNotification $n, array $c, User $d) {
        $datos = $n->toDatabase($d);

        return $datos['tipo'] === 'solicitud_visto_bueno' && $datos['route_rule'] === 'solicitud_visto_bueno' && str_contains($datos['recipient_reason'], 'aprobador');
    });

    $this->actingAs($this->rh)
        ->put(route('administracion.configuracion.jerarquia.update', $this->empleado->colaborador_id), ['jefe_id' => $this->jefeB->colaborador_id, 'motivo' => 'Cambio de equipo'])
        ->assertSessionHasNoErrors();

    $nueva = pedirPrestamo($this->empleado);

    Notification::assertSentTo($this->jefeB, PendienteRhNotification::class, avisoDe('solicitud_visto_bueno'));
    expect(TareaRh::query()->where('relacionado_id', $nueva)->where('relacionado_type', (new SolicitudInterna)->getMorphClass())->value('asignado_user_id'))->toBe($this->jefeB->id)
        // La solicitud vieja conserva su pendiente con el jefe original.
        ->and(TareaRh::query()->where('relacionado_id', $vieja)->where('relacionado_type', (new SolicitudInterna)->getMorphClass())->value('asignado_user_id'))->toBe($this->jefeA->id);

    // Auditoría del cambio: actor, antes, después y motivo.
    $registro = Activity::query()->where('event', 'jefe_directo_cambiado')->latest('id')->firstOrFail();
    expect($registro->causer_id)->toBe($this->rh->id)
        ->and($registro->properties['antes']['jefe_id'])->toBe($this->jefeA->colaborador_id)
        ->and($registro->properties['despues']['jefe_id'])->toBe($this->jefeB->colaborador_id)
        ->and($registro->properties['motivo'])->toBe('Cambio de equipo');
});

test('C: nadie puede ser su propio jefe (422)', function () {
    $this->actingAs($this->rh)
        ->putJson(route('administracion.configuracion.jerarquia.update', $this->jefeB->colaborador_id), ['jefe_id' => $this->jefeB->colaborador_id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('jefe_id');
});

test('D: se detectan ciclos directos e indirectos', function () {
    // empleado → A ; A → B. Poner B → empleado cierra el ciclo empleado → A → B → empleado.
    Colaborador::query()->whereKey($this->jefeA->colaborador_id)->update(['jefe_id' => $this->jefeB->colaborador_id]);

    $this->actingAs($this->rh)
        ->putJson(route('administracion.configuracion.jerarquia.update', $this->jefeB->colaborador_id), ['jefe_id' => $this->empleado->colaborador_id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('jefe_id');

    $this->actingAs($this->rh)
        ->putJson(route('administracion.configuracion.jerarquia.update', $this->jefeA->colaborador_id), ['jefe_id' => $this->empleado->colaborador_id])
        ->assertUnprocessable();

    // La misma regla protege la edición desde el expediente.
    $this->actingAs($this->rh)
        ->put(route('rh.expedientes.datos-laborales.update', $this->jefeB->colaborador_id), ['jefe_id' => $this->empleado->colaborador_id])
        ->assertSessionHasErrors('jefe_id');

    expect(Colaborador::query()->findOrFail($this->jefeB->colaborador_id)->jefe_id)->toBeNull();
});

test('E: sin jefe no se elige a nadie al azar — la solicitud va directo a quien autoriza y queda registro', function () {
    Log::spy();
    $sinJefe = clUsuario('colaborador', ['sucursal_principal_id' => $this->sucursal->id]);
    $otroGerente = clUsuario('gerente', ['sucursal_principal_id' => $this->sucursal->id]);

    $id = pedirPrestamo($sinJefe);

    Notification::assertNotSentTo($this->jefeA, PendienteRhNotification::class);
    Notification::assertNotSentTo($otroGerente, PendienteRhNotification::class, avisoDe('solicitud_visto_bueno'));
    expect(TareaRh::query()->where('relacionado_id', $id)->value('asignado_permiso'))->toBe('prestamos.autorizar');

    // El ruteo del visto bueno, sin jefe ni gerente, no inventa receptor y lo registra.
    $destinos = app(WorkflowRoutingService::class)->resolver('solicitud_visto_bueno', $sinJefe->colaborador);
    expect($destinos)->toBeEmpty();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $mensaje) => str_contains($mensaje, 'receptor requerido inexistente'))->atLeast()->once();
});

test('F: un gerente fuera del alcance no ve el ciclo ni recibe avisos de otra sucursal', function () {
    $otra = Sucursal::factory()->create();
    $gerenteOtra = clUsuario('gerente', ['sucursal_principal_id' => $otra->id]);

    $this->actingAs($gerenteOtra)->get(route('rh.colaboradores.ciclo', $this->empleado->colaborador_id))->assertForbidden();
    $this->actingAs($gerenteOtra)->get(route('administracion.configuracion.jerarquia'))->assertForbidden();

    $destinos = app(WorkflowRoutingService::class)->resolver('contrato_por_vencer', $this->empleado->colaborador)->pluck('usuario.id');
    expect($destinos)->not->toContain($gerenteOtra->id)
        ->and($destinos)->toContain($this->rh->id);
});

test('notificaciones: la solicitud creada avisa solo a los destinatarios configurados', function () {
    // Un usuario elegido por nombre sigue acotado a su alcance (aquí, RH global).
    $especial = clUsuario('rh_admin');
    ReglaNotificacion::query()->create(['evento' => 'solicitud_creada', 'destinatarios' => ['usuario_especifico'], 'usuario_ids' => [$especial->id], 'fallback' => [], 'activa' => true]);
    $sinJefe = clUsuario('colaborador', ['sucursal_principal_id' => $this->sucursal->id]);

    pedirPrestamo($sinJefe);

    Notification::assertSentTo($especial, RhSolicitudCreadaNotification::class);
    Notification::assertNotSentTo($this->rh, RhSolicitudCreadaNotification::class);
});

test('notificaciones: un jefe bloqueado no recibe aviso ni push', function () {
    $this->jefeA->forceFill(['acceso_bloqueado_en' => now()])->save();

    pedirPrestamo($this->empleado);

    Notification::assertNotSentTo($this->jefeA, PendienteRhNotification::class);
});

test('notificaciones: el jefe que además es RH recibe un solo aviso con ambos motivos', function () {
    $this->jefeA->assignRole('rh_admin');
    ReglaNotificacion::query()->create(['evento' => 'reingreso_solicitado', 'destinatarios' => ['jefe_directo', 'rh'], 'fallback' => [], 'activa' => true]);

    $destinos = app(WorkflowRoutingService::class)->resolver('reingreso_solicitado', $this->empleado->colaborador);
    $delJefe = $destinos->firstWhere('usuario.id', $this->jefeA->id);

    expect($destinos->where('usuario.id', $this->jefeA->id))->toHaveCount(1)
        ->and($delJefe['motivos'])->toHaveCount(2)
        ->and($destinos->pluck('usuario.id'))->toContain($this->rh->id)
        ->and($destinos->pluck('usuario.id'))->not->toContain($this->jefeB->id);
});

test('notificaciones: si el push falla la solicitud no se revierte', function () {
    $this->mock(PushNotifier::class, function ($mock): void {
        $mock->shouldReceive('aUsuarioConDatos')->andThrow(new RuntimeException('Expo caído'));
        $mock->shouldReceive('aUsuarios')->andThrow(new RuntimeException('Expo caído'));
        $mock->shouldIgnoreMissing();
    });

    $id = pedirPrestamo($this->empleado);

    expect(SolicitudInterna::query()->whereKey($id)->exists())->toBeTrue();
    Notification::assertSentTo($this->jefeA, PendienteRhNotification::class, avisoDe('solicitud_visto_bueno'));
});

test('notificaciones: un evento sin regla usa el respaldo seguro (RH con alcance) y lo registra', function () {
    Log::spy();

    $destinos = app(WorkflowRoutingService::class)->resolver('evento_inexistente', $this->empleado->colaborador)->pluck('usuario.id');

    expect($destinos)->toContain($this->rh->id)
        ->and($destinos)->not->toContain($this->jefeA->id);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $mensaje) => str_contains($mensaje, 'evento sin regla'))->once();
});

test('notificaciones: guardar una regla queda auditado y no da permisos de autorizar', function () {
    $this->actingAs($this->rh)
        ->put(route('administracion.configuracion.notificaciones.update', 'cierre_autorizacion_rh'), ['destinatarios' => ['jefe_directo', 'rh'], 'fallback' => ['gerencia_rh']])
        ->assertSessionHasNoErrors();

    expect(Activity::query()->where('event', 'regla_notificacion_actualizada')->exists())->toBeTrue()
        // Recibir aviso no da el permiso de autorizar la baja.
        ->and($this->jefeA->fresh()->can('ciclo.autorizar_rh'))->toBeFalse();

    // Tipo de destinatario desconocido o permiso inexistente: 422.
    $this->actingAs($this->rh)
        ->putJson(route('administracion.configuracion.notificaciones.update', 'cierre_autorizacion_rh'), ['destinatarios' => ['todos']])
        ->assertUnprocessable();
    $this->actingAs($this->rh)
        ->putJson(route('administracion.configuracion.notificaciones.update', 'cierre_autorizacion_rh'), ['destinatarios' => ['usuarios_con_permiso'], 'permiso' => 'no.existe'])
        ->assertUnprocessable();
});

test('apariencia: solo super_admin cambia colores; se validan, se inyectan en la web y la app los recibe', function () {
    $this->actingAs($this->rh)->put(route('administracion.configuracion.apariencia.update'), ['valores' => ['apariencia.primary' => '#112233']])->assertForbidden();

    $this->actingAs($this->admin)
        ->putJson(route('administracion.configuracion.apariencia.update'), ['valores' => ['apariencia.primary' => 'verde']])
        ->assertUnprocessable();

    $this->actingAs($this->admin)
        ->put(route('administracion.configuracion.apariencia.update'), ['valores' => ['apariencia.primary' => '#112233', 'apariencia.danger' => '#df4050']])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->get(route('administracion.configuracion.apariencia'))
        ->assertOk()
        ->assertSee('--mrl-petroleo:#112233', false);

    $this->getJson('/api/v1/app/theme')->assertOk()
        ->assertJsonPath('data.colors.primary', '#112233')
        ->assertJsonPath('data.colors.danger', '#DF4050');

    $registro = Activity::query()->where('event', 'configuracion_actualizada')->latest('id')->firstOrFail();
    expect($registro->properties['cambios']['apariencia.primary']['antes'])->toBe('#164E50')
        ->and($registro->properties['cambios']['apariencia.primary']['despues'])->toBe('#112233')
        // Igual al de fábrica: no se registra como cambio.
        ->and($registro->properties['cambios'])->not->toHaveKey('apariencia.danger');

    $this->actingAs($this->admin)->post(route('administracion.configuracion.restaurar'), ['clave' => 'apariencia.primary'])->assertSessionHasNoErrors();
    $this->getJson('/api/v1/app/theme')->assertJsonPath('data.colors.primary', '#164E50');
});

test('parámetros de RH: el cambio aplica de inmediato a la regla del ciclo', function () {
    $this->actingAs($this->rh)
        ->put(route('administracion.configuracion.parametros-rh.update'), ['valores' => ['rh.dias_aviso_vencimiento' => 20, 'rh.onboarding_calificacion_minima' => 9]])
        ->assertSessionHasNoErrors();

    expect(config('contratos.dias_aviso_vencimiento'))->toBe(20)
        ->and(config('ciclo_laboral.onboarding.calificacion_minima'))->toBe(9.0);

    $this->actingAs($this->rh)
        ->putJson(route('administracion.configuracion.parametros-rh.update'), ['valores' => ['rh.dias_aviso_vencimiento' => 0]])
        ->assertUnprocessable();

    $this->actingAs($this->rh)
        ->putJson(route('administracion.configuracion.parametros-rh.update'), ['valores' => ['apariencia.primary' => '#000000']])
        ->assertUnprocessable();
});

test('las pantallas de configuración responden según permisos', function () {
    $this->actingAs($this->rh)->get(route('administracion.configuracion.index'))->assertRedirect(route('administracion.configuracion.jerarquia'));
    $this->actingAs($this->rh)->get(route('administracion.configuracion.jerarquia'))->assertOk();
    $this->actingAs($this->rh)->get(route('administracion.configuracion.notificaciones'))->assertOk();
    $this->actingAs($this->rh)->get(route('administracion.configuracion.parametros-rh'))->assertOk();
    $this->actingAs($this->rh)->get(route('administracion.configuracion.apariencia'))->assertForbidden();
    $this->actingAs($this->empleado)->get(route('administracion.configuracion.index'))->assertForbidden();
});
