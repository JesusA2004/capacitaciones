<?php

use App\Enums\EstadoAvisoAltaSistemas;
use App\Enums\EstadoCandidato;
use App\Enums\EstadoVacante;
use App\Enums\OrigenAvisoAltaSistemas;
use App\Jobs\EnviarAvisoAltaSistemasJob;
use App\Mail\AltaColaboradorSistemasMail;
use App\Models\AvisoAltaSistemas;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\NotificacionEntrega;
use App\Models\Vacante;
use App\Services\Notificaciones\AvisoAltaSistemasService;
use App\Services\Reclutamiento\CandidatoWorkflowService;
use App\Services\Reclutamiento\ContratacionCandidatoService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/*
| Correo «Alta para asignar usuarios» a Sistemas al entrar a la fase 6
| «Contratación» (docs/DEPLOY.md § Correo a Sistemas al entrar a Contratación).
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    config([
        'notificaciones.sistemas.activo' => true,
        'notificaciones.sistemas.para' => ['sistemas@mr-lana.com'],
        'notificaciones.sistemas.cc' => ['jesus.arizmendi@mr-lana.com', 'otro@mr-lana.com'],
    ]);

    $this->estructura = clEstructura();
    $this->estructura['puesto']->update(['nombre' => 'Gestor de cobranza']);
    $this->rh = clUsuario('rh_admin');
    $this->reclutador = clUsuario('rh_auxiliar');
    $this->gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);
});

function aasCandidatoAutorizado(object $t): Candidato
{
    $vacante = Vacante::factory()->real()->create([
        'empresa_id' => $t->estructura['empresa']->id,
        'sucursal_id' => $t->estructura['sucursal']->id,
        'puesto_id' => $t->estructura['puesto']->id,
        'estado' => EstadoVacante::Abierta->value,
        'plazas_requeridas' => 1,
        'plazas_disponibles' => 1,
        'plazas_cubiertas' => 0,
    ]);

    $wf = app(CandidatoWorkflowService::class);
    $c = $wf->registrar([
        'empresa_id' => $t->estructura['empresa']->id,
        'sucursal_id' => $t->estructura['sucursal']->id,
        'departamento_id' => $t->estructura['departamento']->id,
        'puesto_objetivo_id' => $t->estructura['puesto']->id,
        'vacante_id' => $vacante->id,
        'nombre' => 'Ana',
        'apellidos' => 'López Prueba',
        'telefono' => '5512345678',
        'correo' => 'ana.lopez@example.test',
        'fuente' => 'facebook_grupos',
        'gerente_involucrado_id' => $t->gerente->id,
    ], $t->reclutador);

    $wf->evaluarPerfil($c, $t->reclutador, true, 'Cubre perfil.');
    $wf->registrarEntrevista($c, $t->gerente, ['realizada_en' => now()->subDay()->toDateTimeString(), 'resultado' => 'viable', 'observaciones' => 'Buena entrevista.']);
    $wf->enviarPsicometricas($c, $t->reclutador, 'https://pruebas.example.test/1');
    $wf->registrarResultadosPsicometricas($c, $t->reclutador, 'Resultados en perfil.');
    $wf->revisarPsicometricas($c, $t->rh, true, null);
    $wf->registrarSocioeconomico($c, $t->rh, ['fecha_visita' => now()->toDateString(), 'direccion' => 'Calle 1', 'checklist' => ['vivienda_en_orden' => true]]);
    $wf->revisarSocioeconomico($c, $t->rh, true, null);
    $wf->registrarReferencia($c, $t->reclutador, ['empresa' => 'Empresa X', 'contacto' => 'Jefe X', 'resultado' => 'positiva']);
    $wf->concluirReferencias($c, $t->reclutador, true, null);
    $wf->autorizarRh($c->refresh(), $t->rh, 'Autorizado.');

    return $c->refresh();
}

function aasContratar(object $t, Candidato $c): Colaborador
{
    return app(ContratacionCandidatoService::class)->iniciarContratacion($c, [
        'sueldo_mensual' => 9500,
        'fecha_ingreso' => now()->toDateString(),
        'jefe_id' => $t->gerente->colaborador_id,
    ], $t->rh)['colaborador']->refresh();
}

test('al entrar a contratación se encola UN aviso a Sistemas en la cola de avisos', function () {
    Queue::fake();
    $candidato = aasCandidatoAutorizado($this);

    $colaborador = aasContratar($this, $candidato);

    Queue::assertPushed(EnviarAvisoAltaSistemasJob::class, 1);
    Queue::assertPushedOn('notifications', EnviarAvisoAltaSistemasJob::class);
    expect(AvisoAltaSistemas::query()->where('colaborador_id', $colaborador->id)->count())->toBe(1);
});

test('el correo llega a para/cc configurados con los datos principales del colaborador', function () {
    Mail::fake();
    $candidato = aasCandidatoAutorizado($this);

    $colaborador = aasContratar($this, $candidato);

    Mail::assertSent(AltaColaboradorSistemasMail::class, 1);
    $mail = Mail::sent(AltaColaboradorSistemasMail::class)->first();

    expect($mail->hasTo('sistemas@mr-lana.com'))->toBeTrue()
        ->and($mail->hasCc('jesus.arizmendi@mr-lana.com'))->toBeTrue()
        ->and($mail->hasCc('otro@mr-lana.com'))->toBeTrue()
        ->and($mail->envelope()->subject)->toBe(sprintf('Alta en sistemas: %s — Gestor de cobranza, Cuernavaca', $colaborador->nombreCompleto()))
        ->and($mail->dato('Número de empleado'))->toBe($colaborador->numero_empleado)
        ->and($mail->dato('Sucursal'))->toBe('Cuernavaca')
        ->and($mail->dato('Puesto'))->toBe('Gestor de cobranza')
        ->and($mail->dato('Nombre completo'))->toBe($colaborador->nombreCompleto())
        ->and($mail->dato('Teléfono'))->toBe('5512345678')
        ->and($mail->dato('Correo personal'))->toBe('ana.lopez@example.test')
        ->and($mail->urlExpediente)->toBe(route('rh.expedientes.show', $colaborador->id))
        ->and($mail->attachments)->toBe([])
        ->and($mail->dato('Jefe directo'))->toContain($colaborador->jefe !== null ? $colaborador->jefe->nombreCompleto() : 'Sin capturar');

    $aviso = AvisoAltaSistemas::query()->where('colaborador_id', $colaborador->id)->firstOrFail();
    expect($aviso->estado)->toBe(EstadoAvisoAltaSistemas::Enviado)
        ->and($aviso->enviado_en)->not->toBeNull()
        ->and(Activity::query()->where('event', 'aviso_sistemas_alta_enviado')->where('subject_id', $colaborador->id)->exists())->toBeTrue()
        // «Abrir y copiar datos» lleva al detalle de ESTE aviso.
        ->and($mail->urlDetalle)->toBe(route('sistemas.pendientes-alta.show', $aviso->id));
});

test('los datos que faltan se muestran como «Sin capturar» y el correo se renderiza', function () {
    Mail::fake();
    $colaborador = aasContratar($this, aasCandidatoAutorizado($this));
    $colaborador->update(['curp' => null, 'rfc' => null, 'nss' => null, 'fecha_nacimiento' => null]);

    $mail = app(AvisoAltaSistemasService::class)->mailable($colaborador->refresh(), OrigenAvisoAltaSistemas::Candidato);

    expect($mail->dato('CURP'))->toBe('Sin capturar')
        ->and($mail->dato('RFC'))->toBe('Sin capturar')
        ->and($mail->dato('NSS'))->toBe('Sin capturar')
        ->and($mail->dato('Fecha de nacimiento'))->toBe('Sin capturar');

    $mail->assertSeeInHtml('Sin capturar')
        ->assertSeeInHtml('Persona')
        ->assertSeeInHtml('Para copiar y pegar')
        ->assertSeeInHtml('Ver expediente en PEOPLE');
});

test('repetir la contratación o reintentar el job no duplica el correo', function () {
    Mail::fake();
    $candidato = aasCandidatoAutorizado($this);
    $colaborador = aasContratar($this, $candidato);

    // Repetir «Iniciar contratación» (reanuda el QR) y reintentar el job.
    app(ContratacionCandidatoService::class)->iniciarOReanudar($candidato->refresh(), [], $this->rh);
    $aviso = AvisoAltaSistemas::query()->where('colaborador_id', $colaborador->id)->firstOrFail();
    (new EnviarAvisoAltaSistemasJob($aviso->id))->handle(app(AvisoAltaSistemasService::class));
    app(AvisoAltaSistemasService::class)->programar($colaborador, OrigenAvisoAltaSistemas::Candidato, $candidato->id, $this->rh);

    Mail::assertSent(AltaColaboradorSistemasMail::class, 1);
    expect(AvisoAltaSistemas::query()->count())->toBe(1);
});

test('con el correo desactivado no se manda nada, pero el pendiente para Sistemas sí queda', function () {
    Mail::fake();
    Queue::fake();
    config(['notificaciones.sistemas.activo' => false]);

    $colaborador = aasContratar($this, aasCandidatoAutorizado($this));

    Mail::assertNothingSent();
    Queue::assertNotPushed(EnviarAvisoAltaSistemasJob::class);

    $aviso = AvisoAltaSistemas::query()->where('colaborador_id', $colaborador->id)->firstOrFail();
    expect(AvisoAltaSistemas::query()->count())->toBe(1)
        ->and($aviso->estado)->toBe(EstadoAvisoAltaSistemas::SinEnviar)
        ->and($aviso->atendido_en)->toBeNull()
        ->and($colaborador->candidatoOrigen?->estado)->toBe(EstadoCandidato::EnContratacion);
});

test('el correo trae fecha de nacimiento doble, género, ingreso y CURP/RFC en mayúsculas', function () {
    Mail::fake();
    $colaborador = aasContratar($this, aasCandidatoAutorizado($this));
    $colaborador->update([
        'fecha_nacimiento' => '1990-08-15',
        'genero' => 'femenino',
        'curp' => 'lopa900815mmsrrn09',
        'rfc' => 'lopa900815ab1',
        'nss' => '12345678901',
        'fecha_ingreso' => '2026-11-03',
    ]);

    $mail = app(AvisoAltaSistemasService::class)->mailable($colaborador->refresh(), OrigenAvisoAltaSistemas::Candidato);

    expect($mail->dato('Fecha de nacimiento'))->toBe('15 de agosto de 1990 · 15/08/1990')
        ->and($mail->dato('Género'))->toBe('Femenino')
        ->and($mail->dato('CURP'))->toBe('LOPA900815MMSRRN09')
        ->and($mail->dato('RFC'))->toBe('LOPA900815AB1')
        ->and($mail->dato('NSS'))->toBe('12345678901')
        ->and($mail->dato('Fecha de ingreso'))->toBe('3 de noviembre de 2026 · 03/11/2026')
        ->and($mail->fechaIngresoLarga)->toBe('3 de noviembre de 2026')
        ->and($mail->fechaIngresoCorta)->toBe('03/11/2026')
        ->and($mail->tipoIngreso)->toBe('Nuevo ingreso')
        ->and($mail->urlDetalle)->toBe(route('sistemas.pendientes-alta.index'))
        ->and($mail->logoUrl)->toEndWith('/branding/MrLanaPeople_horizontal_color.png')
        ->and($mail->urlBandeja)->toBe(route('sistemas.pendientes-alta.index'));

    $mail->assertSeeInHtml('Ver mis pendientes de alta')
        ->assertSeeInHtml('Abrir y copiar datos')
        ->assertSeeInHtml('NUEVO INGRESO')
        ->assertSeeInHtml('3 de noviembre de 2026')
        ->assertSeeInHtml('15 de agosto de 1990')
        ->assertSeeInText('CURP: LOPA900815MMSRRN09')
        ->assertSeeInText('Mis pendientes de alta:');
});

test('al crear el pendiente se avisa en la plataforma a quien atiende Sistemas (una sola vez)', function () {
    Mail::fake();
    $sistemas = clUsuario('sistemas');
    $candidato = aasCandidatoAutorizado($this);

    $colaborador = aasContratar($this, $candidato);
    app(AvisoAltaSistemasService::class)->programar($colaborador, OrigenAvisoAltaSistemas::Candidato, $candidato->id, $this->rh);

    $aviso = AvisoAltaSistemas::query()->where('colaborador_id', $colaborador->id)->firstOrFail();

    expect(NotificacionEntrega::query()
        ->where('user_id', $sistemas->id)
        ->where('evento', AvisoAltaSistemasService::EVENTO_PENDIENTE)
        ->where('related_type', 'AvisoAltaSistemas')
        ->where('related_id', $aviso->id)
        ->count())->toBe(1)
        // RH no la atiende: no le llega este aviso.
        ->and(NotificacionEntrega::query()->where('user_id', $this->rh->id)->where('evento', AvisoAltaSistemasService::EVENTO_PENDIENTE)->exists())->toBeFalse();
});

test('si el correo falla la contratación NO se revierte y queda warning + auditoría del fallo', function () {
    Log::spy();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP caído'));
    $candidato = aasCandidatoAutorizado($this);

    $colaborador = aasContratar($this, $candidato);

    expect($candidato->refresh()->estado)->toBe(EstadoCandidato::EnContratacion)
        ->and($candidato->colaborador_id)->toBe($colaborador->id);

    $aviso = AvisoAltaSistemas::query()->where('colaborador_id', $colaborador->id)->firstOrFail();
    expect($aviso->estado)->toBe(EstadoAvisoAltaSistemas::Fallido)
        ->and($aviso->enviado_en)->toBeNull()
        ->and($aviso->ultimo_error)->toContain('SMTP caído')
        ->and(Activity::query()->where('event', 'aviso_sistemas_alta_fallido')->exists())->toBeTrue();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $mensaje) => str_contains($mensaje, 'correo de alta a Sistemas'))
        ->atLeast()->once();
});

test('RH puede reenviar el aviso desde la ficha del candidato; sin permiso es 403', function () {
    Mail::fake();
    $candidato = aasCandidatoAutorizado($this);
    aasContratar($this, $candidato);

    $this->actingAs(clUsuario('colaborador'))
        ->post(route('rh.candidatos.aviso-sistemas.reenviar', $candidato))
        ->assertForbidden();

    Mail::assertSent(AltaColaboradorSistemasMail::class, 1);

    $this->actingAs($this->rh)
        ->post(route('rh.candidatos.aviso-sistemas.reenviar', $candidato))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Mail::assertSent(AltaColaboradorSistemasMail::class, 2);
    expect(Activity::query()->where('event', 'aviso_sistemas_alta_reenvio_solicitado')->exists())->toBeTrue();
});

test('el comando sistemas:reenviar-aviso-alta reencola el aviso por número de empleado', function () {
    Mail::fake();
    $colaborador = aasContratar($this, aasCandidatoAutorizado($this));

    $this->artisan('sistemas:reenviar-aviso-alta', ['colaborador' => $colaborador->numero_empleado])
        ->assertSuccessful();

    Mail::assertSent(AltaColaboradorSistemasMail::class, 2);
});
