<?php

use App\Enums\EstadoAvisoAltaSistemas;
use App\Enums\EstadoCandidato;
use App\Enums\EstadoUsuario;
use App\Enums\OrigenAvisoAltaSistemas;
use App\Mail\AltaColaboradorSistemasMail;
use App\Models\AvisoAltaSistemas;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Services\Colaboradores\AvisoAltaBajaService;
use App\Services\Notificaciones\AvisoAltaSistemasService;
use App\Services\Notificaciones\DestinoNotificacionService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
| Bandeja «Pendientes de alta en sistemas» (docs/SISTEMAS_PENDIENTES_ALTA.md):
| permisos, marcar como dado de alta, reenviar, contador del menú y
| regularización (sistemas:avisos-alta-pendientes).
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    Mail::fake();

    config([
        'notificaciones.sistemas.activo' => true,
        'notificaciones.sistemas.para' => ['sistemas@mr-lana.com'],
        'notificaciones.sistemas.cc' => [],
    ]);

    $this->estructura = clEstructura();
    $this->sistemas = clUsuario('sistemas');
});

function pasColaborador(object $t, array $atributos = []): Colaborador
{
    return Colaborador::factory()->create([
        'name' => 'Ana',
        'apellidos' => 'López Prueba',
        'sucursal_principal_id' => $t->estructura['sucursal']->id,
        'puesto_id' => $t->estructura['puesto']->id,
        'departamento_id' => $t->estructura['departamento']->id,
        'fecha_ingreso' => now()->subDays(2)->toDateString(),
        ...$atributos,
    ]);
}

function pasPendiente(object $t, array $atributos = []): AvisoAltaSistemas
{
    $colaborador = pasColaborador($t, $atributos);

    return app(AvisoAltaSistemasService::class)->programar($colaborador, OrigenAvisoAltaSistemas::Colaborador, $colaborador->id);
}

test('Sistemas ve su bandeja con los datos básicos y el texto para copiar', function () {
    $aviso = pasPendiente($this, ['curp' => 'LOPA900815MMSRRN09', 'rfc' => null]);

    $this->actingAs($this->sistemas)
        ->get(route('sistemas.pendientes-alta.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sistemas/PendientesAlta')
            ->where('totales.pendientes', 1)
            ->where('puedeAtender', true)
            ->has('pendientes.data', 1)
            ->where('pendientes.data.0.id', $aviso->id)
            ->where('pendientes.data.0.colaborador.nombre', 'Ana López Prueba')
            ->where('pendientes.data.0.puede_atender', true)
            ->where('pendientes.data.0.puede_reenviar', true)
            ->where('pendientes.data.0.atendido', null)
            ->where('pendientes.data.0.texto_copiar', fn (string $texto) => str_contains($texto, 'CURP: LOPA900815MMSRRN09') && str_contains($texto, 'RFC: Sin capturar'))
            ->where('altasSistemasPendientes', 1));
});

test('el detalle del aviso trae las tres tarjetas para copiar dato por dato; sin permiso es 403', function () {
    $aviso = pasPendiente($this, ['telefono' => '5512345678']);

    $this->actingAs($this->sistemas)
        ->get(route('sistemas.pendientes-alta.show', $aviso))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sistemas/PendienteAltaDetalle')
            ->where('pendiente.id', $aviso->id)
            ->where('pendiente.tipo_ingreso', 'Nuevo ingreso')
            ->where('pendiente.secciones.0.clave', 'persona')
            ->where('pendiente.secciones.1.clave', 'contacto')
            ->where('pendiente.secciones.2.clave', 'puesto')
            ->where('pendiente.secciones.1.datos.0', ['etiqueta' => 'Teléfono', 'valor' => '5512345678', 'falta' => false]));

    $this->actingAs(clUsuario('colaborador'))
        ->get(route('sistemas.pendientes-alta.show', $aviso))
        ->assertForbidden();
});

test('RH consulta la bandeja pero no marca; colaborador y gerente reciben 403', function () {
    $aviso = pasPendiente($this);
    $rh = clUsuario('rh_admin');

    $this->actingAs($rh)
        ->get(route('sistemas.pendientes-alta.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('puedeAtender', false)
            ->where('pendientes.data.0.puede_atender', false)
            // El contador del menú es solo para quien atiende.
            ->where('altasSistemasPendientes', 0));

    $this->actingAs($rh)->post(route('sistemas.pendientes-alta.atender', $aviso))->assertForbidden();

    foreach (['colaborador', 'gerente_sucursal'] as $rol) {
        $usuario = clUsuario($rol);
        $this->actingAs($usuario)->get(route('sistemas.pendientes-alta.index'))->assertForbidden();
        $this->actingAs($usuario)->post(route('sistemas.pendientes-alta.atender', $aviso))->assertForbidden();
    }

    expect($aviso->refresh()->atendido_en)->toBeNull();
});

test('marcar como dado de alta registra quién, cuándo y notas; no se marca dos veces y se puede deshacer', function () {
    $aviso = pasPendiente($this);

    $this->actingAs($this->sistemas)
        ->post(route('sistemas.pendientes-alta.atender', $aviso), ['notas' => 'Correo y VPN creados.'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $aviso->refresh();
    expect($aviso->atendido_en)->not->toBeNull()
        ->and($aviso->atendido_por)->toBe($this->sistemas->id)
        ->and($aviso->notas_atencion)->toBe('Correo y VPN creados.')
        ->and(Activity::query()->where('event', 'sistemas_alta_atendida')->exists())->toBeTrue();

    $this->actingAs($this->sistemas)
        ->post(route('sistemas.pendientes-alta.atender', $aviso))
        ->assertSessionHasErrors('aviso');

    $this->actingAs($this->sistemas)
        ->get(route('sistemas.pendientes-alta.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totales.pendientes', 0)
            ->where('totales.atendidos', 1)
            ->has('pendientes.data', 0)
            ->where('altasSistemasPendientes', 0));

    $this->actingAs($this->sistemas)
        ->get(route('sistemas.pendientes-alta.index', ['filtro' => 'atendidos']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('pendientes.data', 1)
            ->where('pendientes.data.0.atendido.notas', 'Correo y VPN creados.'));

    $this->actingAs($this->sistemas)
        ->post(route('sistemas.pendientes-alta.reabrir', $aviso))
        ->assertSessionHasNoErrors();

    expect($aviso->refresh()->atendido_en)->toBeNull()
        ->and($aviso->atendido_por)->toBeNull()
        ->and(Activity::query()->where('event', 'sistemas_alta_reabierta')->exists())->toBeTrue();
});

test('Sistemas reenvía el correo desde la bandeja', function () {
    $aviso = pasPendiente($this);
    Mail::assertSent(AltaColaboradorSistemasMail::class, 1);

    $this->actingAs($this->sistemas)
        ->post(route('sistemas.pendientes-alta.reenviar', $aviso))
        ->assertSessionHasNoErrors();

    Mail::assertSent(AltaColaboradorSistemasMail::class, 2);
    expect($aviso->refresh()->estado)->toBe(EstadoAvisoAltaSistemas::Enviado);
});

test('la búsqueda filtra por nombre y apellidos', function () {
    pasPendiente($this);
    pasPendiente($this, ['name' => 'Bruno', 'apellidos' => 'Martínez']);

    $this->actingAs($this->sistemas)
        ->get(route('sistemas.pendientes-alta.index', ['buscar' => 'ana lópez']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('pendientes.data', 1)
            ->where('pendientes.data.0.colaborador.nombre', 'Ana López Prueba'));
});

test('la notificación lleva a la bandeja y se ve atendida en cuanto se marca', function () {
    $aviso = pasPendiente($this);
    $notificacion = new DatabaseNotification([
        'id' => (string) Str::uuid(),
        'type' => 'x',
        'data' => ['type' => 'sistemas_alta', 'related_type' => 'AvisoAltaSistemas', 'resource_id' => $aviso->id, 'colaborador_id' => $aviso->colaborador_id],
    ]);
    $destinos = app(DestinoNotificacionService::class);

    expect($destinos->resolver($notificacion, $this->sistemas))
        ->toMatchArray(['url' => route('sistemas.pendientes-alta.index', ['filtro' => 'pendientes'], false), 'atendida' => false]);

    $aviso->update(['atendido_en' => now(), 'atendido_por' => $this->sistemas->id]);

    expect($destinos->resolver($notificacion, $this->sistemas)['atendida'])->toBeTrue();
});

test('un alta activada sin pasar por contratación también queda pendiente (una vez)', function () {
    $colaborador = pasColaborador($this);
    $avisos = app(AvisoAltaBajaService::class);

    $avisos->alta($colaborador);
    $avisos->alta($colaborador);

    expect(AvisoAltaSistemas::query()->where('colaborador_id', $colaborador->id)->count())->toBe(1)
        ->and(AvisoAltaSistemas::query()->where('colaborador_id', $colaborador->id)->value('origen_tipo'))->toBe(OrigenAvisoAltaSistemas::Colaborador);
});

test('sistemas:avisos-alta-pendientes: --dry-run no crea, por defecto crea sin correo, --enviar manda y es idempotente', function () {
    // Contratado (candidato) sin aviso, ingreso reciente.
    $contratado = pasColaborador($this, ['name' => 'Carla']);
    $candidato = Candidato::factory()->create([
        'estado' => EstadoCandidato::Contratado->value,
        'colaborador_id' => $contratado->id,
    ]);
    // Alta directa reciente sin aviso.
    $directo = pasColaborador($this, ['name' => 'Diego']);
    // Ingreso viejo (fuera de --dias) y baja: no se tocan.
    pasColaborador($this, ['name' => 'Viejo', 'fecha_ingreso' => now()->subYear()->toDateString()]);
    pasColaborador($this, ['name' => 'Baja', 'estatus' => EstadoUsuario::Inactivo->value]);

    $this->artisan('sistemas:avisos-alta-pendientes', ['--dry-run' => true, '--dias' => 30])
        ->expectsOutputToContain('Simulación: 2 persona(s)')
        ->assertSuccessful();
    expect(AvisoAltaSistemas::query()->count())->toBe(0);

    $this->artisan('sistemas:avisos-alta-pendientes', ['--dias' => 30])->assertSuccessful();

    $avisos = AvisoAltaSistemas::query()->orderBy('id')->get();
    expect($avisos)->toHaveCount(2)
        ->and($avisos->pluck('colaborador_id')->all())->toEqualCanonicalizing([$contratado->id, $directo->id])
        ->and($avisos->firstWhere('colaborador_id', $contratado->id)?->origen_tipo)->toBe(OrigenAvisoAltaSistemas::Candidato)
        ->and($avisos->firstWhere('colaborador_id', $contratado->id)?->origen_id)->toBe($candidato->id)
        ->and($avisos->every(fn (AvisoAltaSistemas $a) => $a->estado === EstadoAvisoAltaSistemas::SinEnviar))->toBeTrue();
    Mail::assertNothingSent();

    // Idempotente: una segunda corrida no encuentra nada.
    $this->artisan('sistemas:avisos-alta-pendientes', ['--dias' => 30, '--enviar' => true])
        ->expectsOutputToContain('Nada que regularizar')
        ->assertSuccessful();
    Mail::assertNothingSent();

    // --enviar sí manda el correo de lo nuevo.
    pasColaborador($this, ['name' => 'Elena']);
    $this->artisan('sistemas:avisos-alta-pendientes', ['--dias' => 30, '--enviar' => true])->assertSuccessful();
    Mail::assertSent(AltaColaboradorSistemasMail::class, 1);
    expect(AvisoAltaSistemas::query()->count())->toBe(3);
});

test('--dry-run y --enviar juntos se rechazan', function () {
    $this->artisan('sistemas:avisos-alta-pendientes', ['--dry-run' => true, '--enviar' => true])->assertFailed();
});
