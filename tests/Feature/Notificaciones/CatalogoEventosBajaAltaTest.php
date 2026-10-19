<?php

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Notifications\Mobile\BirthdayRhReminderNotification;
use App\Services\Configuracion\WorkflowRoutingService;
use App\Services\Cumpleanos\CumpleanosService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

/*
| Catálogo de eventos de notificación (Configuración → Notificaciones): los
| eventos que el código dispara deben estar en el catálogo, con destinatarios
| coherentes con el ruteo configurable (nunca roles hardcodeados, nunca toda
| la plantilla). Cubre expediente_completo, cierre_negativa_firma,
| colaborador_alta/baja → Sistemas y el recordatorio de cumpleaños por permiso.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
});

test('expediente_completo y cierre_negativa_firma están en el catálogo (sin Log::warning de evento desconocido)', function () {
    $routing = app(WorkflowRoutingService::class);

    $expediente = $routing->regla('expediente_completo');
    expect($expediente['conocido'])->toBeTrue()
        ->and($expediente['destinatarios'])->toContain('usuarios_con_permiso')
        ->and($expediente['permiso'])->toBe('documentos_laborales.generar');

    $negativa = $routing->regla('cierre_negativa_firma');
    expect($negativa['conocido'])->toBeTrue()
        ->and($negativa['destinatarios'])->toContain('usuarios_con_permiso')
        ->and($negativa['permiso'])->toBe('cierres.gestionar');

    // `conocido === true` es justo la condición que evita el Log::warning de
    // "evento sin regla" y el fallback espurio a `rh`: al estar en el catálogo,
    // ninguno de los dos eventos lo dispara ya (P1-17).
});

test('alta y baja notifican a Sistemas (no a toda la plantilla)', function () {
    $sistemas = clUsuario('sistemas');
    $ajeno = clUsuario('colaborador');

    $colaborador = Colaborador::factory()->create(['estatus' => EstadoUsuario::Activo]);

    $altaIds = app(WorkflowRoutingService::class)->resolver('colaborador_alta', $colaborador)->pluck('usuario.id')->all();
    $bajaIds = app(WorkflowRoutingService::class)->resolver('colaborador_baja', $colaborador)->pluck('usuario.id')->all();

    expect($altaIds)->toContain($sistemas->id)
        ->and($bajaIds)->toContain($sistemas->id)
        // Un colaborador sin rol operativo NUNCA entra como destinatario.
        ->and($altaIds)->not->toContain($ajeno->id)
        ->and($bajaIds)->not->toContain($ajeno->id);

    // El catálogo declara a Sistemas en ambos eventos.
    expect(config('configuracion_sistema.eventos.colaborador_alta.destinatarios'))->toContain('sistemas')
        ->and(config('configuracion_sistema.eventos.colaborador_baja.destinatarios'))->toContain('sistemas');
});

test('el recordatorio de cumpleaños a RH se decide por PERMISO, no por nombre de rol', function () {
    config(['cumpleanos.notify_rh' => true]);

    // Rol que NO está en la antigua lista hardcodeada, pero CON el permiso.
    Role::query()->create(['name' => 'coordinadora_rh_prueba', 'guard_name' => 'web'])
        ->givePermissionTo('rh.cumpleanos.ver');

    $conPermiso = clUsuario('coordinadora_rh_prueba', [
        'estatus' => EstadoUsuario::Activo,
        'fecha_nacimiento' => now()->subYears(30)->toDateString(),
    ]);

    // Usuario sin el permiso (rol colaborador), también cumple hoy: no recibe.
    $sinPermiso = clUsuario('colaborador', [
        'estatus' => EstadoUsuario::Activo,
        'fecha_nacimiento' => now()->subYears(25)->toDateString(),
    ]);

    app(CumpleanosService::class)->notificarRh();

    Notification::assertSentTo($conPermiso, BirthdayRhReminderNotification::class);
    Notification::assertNotSentTo($sinPermiso, BirthdayRhReminderNotification::class);
});
