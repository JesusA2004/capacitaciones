<?php

use App\Enums\CategoriaNotificacion;
use App\Enums\TipoTarea;
use App\Jobs\SendExpoPushJob;
use App\Jobs\SendWebPushJob;
use App\Models\Colaborador;
use App\Models\MobileDevice;
use App\Models\NotificacionEntrega;
use App\Models\NotificacionEventoRol;
use App\Models\NotificacionEventoRolHistorial;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\WebPushSuscripcion;
use App\Services\Colaboradores\AvisoAltaBajaService;
use App\Services\Cumpleanos\CumpleanosService;
use App\Services\Notificaciones\Aviso;
use App\Services\Notificaciones\NotificacionEventosService;
use App\Services\Notificaciones\PoliticaNotificacionRolService;
use App\Services\Notificaciones\ReconciliadorNotificacionesService;
use App\Services\Tareas\NotificadorRhService;
use App\Services\Tareas\TareaService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;

/*
| Roles y permisos → Notificaciones: qué acción le avisa a qué rol. Se prueba el
| efecto real sobre campana, Expo y Web Push, no sólo la pantalla.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    $this->sucursal = Sucursal::factory()->create();
    $this->persona = Colaborador::factory()->create(['sucursal_principal_id' => $this->sucursal->id]);
});

function chkRol(string $nombre): Role
{
    return Role::findByName($nombre, 'web');
}

/**
 * @return list<string>
 */
function chkMarcados(string $rol): array
{
    return app(PoliticaNotificacionRolService::class)->matrizParaPantalla(collect([chkRol($rol)]))['marcados'][chkRol($rol)->id];
}

function chkDispositivos(User $usuario): void
{
    MobileDevice::factory()->create(['user_id' => $usuario->id, 'push_token' => 'ExponentPushToken[u'.$usuario->id.']', 'revoked_at' => null]);
    WebPushSuscripcion::query()->create([
        'user_id' => $usuario->id,
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/u'.$usuario->id,
        'endpoint_hash' => WebPushSuscripcion::hashDe('https://fcm.googleapis.com/fcm/send/u'.$usuario->id),
        'clave_publica' => 'publica',
        'token_autenticacion' => 'auth',
        'codificacion' => 'aes128gcm',
    ]);
}

test('RH admin, Sistemas y super_admin guardan el checklist; se aplica al instante y deja historial', function (string $editor) {
    $actor = clUsuario($editor);
    $politica = app(PoliticaNotificacionRolService::class);
    expect($politica->marcado('sistemas', 'colaborador_baja'))->toBeTrue();

    $sinBaja = array_values(array_diff(chkMarcados('sistemas'), ['colaborador_baja']));

    $this->actingAs($actor)
        ->put(route('administracion.roles.notificaciones.update', chkRol('sistemas')), ['eventos' => $sinBaja])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($politica->marcado('sistemas', 'colaborador_baja'))->toBeFalse()
        ->and(NotificacionEventoRol::query()->where('evento', 'colaborador_baja')->where('role_id', chkRol('sistemas')->id)->value('activo'))->toBeFalse();

    $fila = NotificacionEventoRolHistorial::query()->where('evento', 'colaborador_baja')->sole();
    expect($fila->actor_id)->toBe($actor->id)
        ->and($fila->antes)->toBeTrue()
        ->and($fila->despues)->toBeFalse()
        ->and($fila->created_at)->not->toBeNull();
})->with(['rh_admin', 'sistemas', 'super_admin']);

test('quien no está autorizado recibe 403 al guardar, restablecer y entrar', function (string $rol) {
    $actor = clUsuario($rol);

    $this->actingAs($actor)->put(route('administracion.roles.notificaciones.update', chkRol('sistemas')), ['eventos' => []])->assertForbidden();
    $this->actingAs($actor)->post(route('administracion.roles.notificaciones.restablecer', chkRol('sistemas')))->assertForbidden();
    $this->actingAs($actor)->get(route('administracion.roles.index'))->assertForbidden();

    expect(NotificacionEventoRol::query()->count())->toBe(0);
})->with(['colaborador', 'gerente_sucursal', 'gerente_regional', 'rh_auxiliar', 'auditor', 'juridico', 'director_comercial', 'direccion']);

test('una obligatoria no se puede quitar desde el backend', function () {
    $sinAutorizacion = array_values(array_diff(chkMarcados('rh_admin'), ['candidato_autorizacion_rh']));

    $this->actingAs(clUsuario('sistemas'))
        ->put(route('administracion.roles.notificaciones.update', chkRol('rh_admin')), ['eventos' => $sinAutorizacion])
        ->assertSessionHasErrors('eventos');

    expect(NotificacionEventoRol::query()->count())->toBe(0)
        ->and(app(PoliticaNotificacionRolService::class)->marcado('rh_admin', 'candidato_autorizacion_rh'))->toBeTrue();
});

test('un aviso personal o asignado no se marca por rol', function (string $clave) {
    $this->actingAs(clUsuario('sistemas'))
        ->put(route('administracion.roles.notificaciones.update', chkRol('colaborador')), ['eventos' => [...chkMarcados('colaborador'), $clave]])
        ->assertSessionHasErrors('eventos');
})->with(['solicitud_actualizada', 'documento_revisado', 'solicitud_visto_bueno']);

test('desmarcar quita el aviso en campana, Expo y Web Push; volver a marcarlo lo devuelve', function () {
    Queue::fake();
    $sistemas = clUsuario('sistemas');
    chkDispositivos($sistemas);
    $politica = app(PoliticaNotificacionRolService::class);
    $politica->guardar(chkRol('sistemas'), array_values(array_diff(chkMarcados('sistemas'), ['colaborador_baja'])), $sistemas);

    app(AvisoAltaBajaService::class)->baja($this->persona);

    expect($sistemas->notifications()->count())->toBe(0)
        ->and(NotificacionEntrega::query()->where('user_id', $sistemas->id)->count())->toBe(0);
    Queue::assertNotPushed(SendExpoPushJob::class);
    Queue::assertNotPushed(SendWebPushJob::class);

    $politica->guardar(chkRol('sistemas'), [...chkMarcados('sistemas'), 'colaborador_baja'], $sistemas);
    app(AvisoAltaBajaService::class)->baja($this->persona);

    expect(NotificacionEntrega::query()->where('user_id', $sistemas->id)->where('evento', 'colaborador_baja')->count())->toBe(1);
    Queue::assertPushed(SendExpoPushJob::class);
    Queue::assertPushed(SendWebPushJob::class);
});

test('desmarcar no cierra la tarea y el reconciliador no repone el aviso', function () {
    $rh = clUsuario('rh_auxiliar');
    $politica = app(PoliticaNotificacionRolService::class);
    $politica->guardar(chkRol('rh_auxiliar'), array_values(array_diff(chkMarcados('rh_auxiliar'), ['expediente_completo', 'activacion_pendiente'])), clUsuario('sistemas'));

    $tarea = app(TareaService::class)->abrir(TipoTarea::ActivacionPendiente, $this->persona, [
        'titulo' => 'Activar alta',
        'colaborador' => $this->persona,
        'usuario' => $rh,
        'accion' => 'activar_alta',
    ]);
    $tarea->forceFill(['created_at' => now()->subMinutes(10)])->save();

    app(ReconciliadorNotificacionesService::class)->reconciliar();

    expect($rh->notifications()->count())->toBe(0)
        ->and($tarea->fresh()->resuelta_en)->toBeNull()
        ->and(app(TareaService::class)->destinatarios($tarea->fresh())->pluck('id')->all())->toContain($rh->id);
});

test('lo propio y la confirmación llegan aunque el rol esté desmarcado', function () {
    $sistemas = clUsuario('sistemas', ['sucursal_principal_id' => $this->sucursal->id]);
    $eventos = app(NotificacionEventosService::class);
    $politica = app(PoliticaNotificacionRolService::class);
    expect($politica->marcado('sistemas', 'documento_subido'))->toBeFalse();

    $ajeno = new Aviso('documento_subido', 'rh_documento', 'Documento por revisar', 'Texto', $this->persona, 'revisar_documento');
    $propio = new Aviso('documento_subido', 'rh_documento', 'Documento por revisar', 'Texto', $sistemas->colaborador, 'revisar_documento');
    $confirmacion = new Aviso('documento_subido', 'documento', 'Documento enviado', 'Texto', $this->persona, 'registrado');
    $directo = new Aviso('documento_subido', 'rh_documento', 'Documento por revisar', 'Otro', $this->persona, 'revisar_documento', directo: true);

    expect($eventos->entregar($sistemas, CategoriaNotificacion::Pendiente, $ajeno))->toBeFalse()
        ->and($eventos->entregar($sistemas, CategoriaNotificacion::Pendiente, $propio))->toBeTrue()
        ->and($eventos->confirmarAlActor($sistemas, $confirmacion))->toBeTrue()
        ->and($eventos->entregar($sistemas, CategoriaNotificacion::Pendiente, $directo))->toBeTrue();
});

test('con varios roles basta uno marcado, y el rol de colaborador no estorba al desmarcar', function () {
    $doble = clUsuario('sistemas');
    $doble->assignRole('rh_admin');
    $conColaborador = clUsuario('sistemas');
    $conColaborador->assignRole('colaborador');
    $politica = app(PoliticaNotificacionRolService::class);

    expect($politica->permiteClave($doble, 'documento_subido'))->toBeTrue()
        ->and($politica->permiteClave($conColaborador, 'documento_subido'))->toBeFalse();
});

test('marcar un rol lo amplía sólo dentro de su alcance', function () {
    $gerenteDeAqui = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->sucursal->id]);
    $gerenteDeOtra = clUsuario('gerente_sucursal', ['sucursal_principal_id' => Sucursal::factory()->create()->id]);
    $politica = app(PoliticaNotificacionRolService::class);
    $politica->guardar(chkRol('gerente_sucursal'), [...chkMarcados('gerente_sucursal'), 'colaborador_baja'], clUsuario('sistemas'));

    app(NotificadorRhService::class)->notificarEvento('colaborador_baja', $this->persona, [], 'Baja', 'Texto', $this->persona, 'ver_expediente');

    expect($gerenteDeAqui->notifications()->count())->toBe(1)
        ->and($gerenteDeOtra->notifications()->count())->toBe(0);
});

test('un aviso sin sujeto no se amplía: no hay alcance que comprobar', function () {
    $gerente = clUsuario('gerente_sucursal');
    app(PoliticaNotificacionRolService::class)->guardar(chkRol('gerente_sucursal'), [...chkMarcados('gerente_sucursal'), 'plantilla_faltante'], clUsuario('sistemas'));

    app(NotificadorRhService::class)->notificar([], 'plantilla_faltante', 'Falta plantilla', 'Texto');

    expect($gerente->notifications()->count())->toBe(0);
});

test('restablecer vuelve a los valores de fábrica y deja historial', function () {
    $rh = clUsuario('rh_admin');
    $politica = app(PoliticaNotificacionRolService::class);
    $politica->guardar(chkRol('sistemas'), [...array_diff(chkMarcados('sistemas'), ['colaborador_baja']), 'acta_registrada'], $rh);
    expect(NotificacionEventoRol::query()->count())->toBe(2);

    $this->actingAs($rh)->post(route('administracion.roles.notificaciones.restablecer', chkRol('sistemas')))->assertRedirect();

    expect(NotificacionEventoRol::query()->count())->toBe(0)
        ->and($politica->marcado('sistemas', 'colaborador_baja'))->toBeTrue()
        ->and($politica->marcado('sistemas', 'acta_registrada'))->toBeFalse()
        ->and(NotificacionEventoRolHistorial::query()->count())->toBe(4);
});

test('la pantalla: RH admin sólo ve avisos; Sistemas ve permisos y avisos', function () {
    $this->actingAs(clUsuario('rh_admin'))->get(route('administracion.roles.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Administracion/Roles/Index')
            ->where('puedeAdministrarRoles', false)
            ->has('checklistNotificaciones.modulos')
            ->where('roles.0.permisos', []));

    $this->actingAs(clUsuario('sistemas'))->get(route('administracion.roles.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('puedeAdministrarRoles', true)->has('checklistNotificaciones.modulos'));
});

test('RH admin no puede tocar permisos de roles con su acceso a la pestaña de avisos', function () {
    $this->actingAs(clUsuario('rh_admin'))
        ->put(route('administracion.roles.update', chkRol('colaborador')), ['nombre' => 'colaborador', 'permisos' => []])
        ->assertForbidden();
});

test('valores de fábrica corregidos: cada aviso a quien le corresponde', function (string $clave, string $rol, bool $esperado) {
    expect(app(PoliticaNotificacionRolService::class)->marcado($rol, $clave))->toBe($esperado);
})->with([
    'Sistemas no revisa documentos' => ['documento_subido', 'sistemas', false],
    'Auditoría no revisa documentos' => ['documento_subido', 'auditor', false],
    'RH auxiliar sí revisa documentos' => ['documento_subido', 'rh_auxiliar', true],
    'Dirección no recibe contrato por vencer' => ['contrato_por_vencer', 'direccion', false],
    'Gerente sí recibe contrato por vencer' => ['contrato_por_vencer', 'gerente_sucursal', true],
    'Regional no imprime contratos' => ['contratos_listos', 'gerente_regional', false],
    'Gerente sí imprime contratos' => ['contratos_listos', 'gerente', true],
    'Sistemas sin recordatorio de cumpleaños' => ['rh_cumpleanos', 'sistemas', false],
    'RH con recordatorio de cumpleaños' => ['rh_cumpleanos', 'rh_admin', true],
    'super_admin no recibe alta por permiso' => ['colaborador_alta', 'super_admin', false],
    'super_admin no recibe documentos por permiso' => ['documento_subido', 'super_admin', false],
    'super_admin sí ve celebraciones generales' => ['cumpleanos_muro', 'super_admin', true],
]);

test('el recordatorio de cumpleaños no le llega a Sistemas, sí a RH', function () {
    $sistemas = clUsuario('sistemas');
    $rh = clUsuario('rh_admin');
    Colaborador::factory()->create(['fecha_nacimiento' => now()->subYears(30)->toDateString(), 'sucursal_principal_id' => $this->sucursal->id]);

    app(CumpleanosService::class)->notificarRh();

    expect($sistemas->notifications()->count())->toBe(0)
        ->and($rh->notifications()->count())->toBe(1);
});

test('el push no lleva nombre, causal, calificación, texto libre ni montos', function () {
    Bus::fake([SendExpoPushJob::class, SendWebPushJob::class]);
    $rh = clUsuario('rh_admin');
    chkDispositivos($rh);
    $sensibles = 'Juana Pérez López · falta de probidad · calificó 12/30 · sueldo $18,500 · comentario privado';

    app(NotificacionEventosService::class)->entregar($rh, CategoriaNotificacion::Pendiente, new Aviso('baja_rotacion_temprana', 'baja', 'Rotación temprana: Juana Pérez López', $sensibles, $this->persona, 'ver_baja'));

    $revisar = function (string $texto): void {
        foreach (['Juana', 'Pérez', 'probidad', '12/30', '18,500', 'privado'] as $dato) {
            expect($texto)->not->toContain($dato);
        }
    };

    Bus::assertDispatched(SendExpoPushJob::class, function (SendExpoPushJob $job) use ($revisar): bool {
        $revisar($job->titulo.' '.$job->cuerpo.' '.json_encode($job->data));

        return true;
    });
    Bus::assertDispatched(SendWebPushJob::class, function (SendWebPushJob $job) use ($revisar): bool {
        $revisar((string) json_encode($job->payload));

        return true;
    });

    // La campana sí lleva el detalle: se lee ya autenticado.
    expect(data_get($rh->notifications()->first(), 'data.mensaje'))->toContain('Juana');
});

test('ninguna etiqueta del catálogo para push trae datos variables', function () {
    foreach ((array) config('notificaciones_catalogo.eventos') as $clave => $evento) {
        expect((string) ($evento['push_titulo'] ?? $evento['etiqueta']))->not->toMatch('/[%$]|\{/');
    }
});
