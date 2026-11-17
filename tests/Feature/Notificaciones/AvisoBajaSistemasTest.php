<?php

use App\Enums\EstadoAvisoBajaSistemas;
use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoUsuario;
use App\Enums\EventoAvisoBajaSistemas;
use App\Enums\OrigenAvisoBajaSistemas;
use App\Jobs\EnviarAvisoBajaSistemasJob;
use App\Mail\BajaColaboradorSistemasMail;
use App\Models\AvisoBajaSistemas;
use App\Models\Baja;
use App\Models\CausalRotacion;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\Bajas\CatalogoBajasService;
use App\Services\CierreLaboral\CierreLaboralService;
use App\Services\Notificaciones\AvisoBajaSistemasService;
use Database\Seeders\CausalesRotacionSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/*
| Correos de BAJA a Sistemas (AvisoBajaSistemasService): «dar de baja
| accesos» al aplicarse la baja (accesos suspendidos) y «baja concluida» al
| registrar la entrega del finiquito; una vez por evento por baja, y un fallo
| de envío nunca deshace la baja.
*/

beforeEach(function () {
    clRelojMediodiaMexico();
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(CausalesRotacionSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    config([
        'notificaciones.sistemas.activo' => true,
        'notificaciones.sistemas.para' => ['sistemas@mr-lana.com'],
        'notificaciones.sistemas.cc' => ['otro@mr-lana.com'],
        'notificaciones.sistemas.plataformas' => ['Correo corporativo', 'ERP de cobranza'],
    ]);

    $this->estructura = clEstructura();
    $this->estructura['puesto']->update(['nombre' => 'Gestor de cobranza']);
    $sucursal = $this->estructura['sucursal'];
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $sucursal->id]);
    $this->gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $sucursal->id]);
    $this->sistemas = clUsuario('sistemas', ['sucursal_principal_id' => $sucursal->id]);
    $this->persona = Colaborador::factory()->create([
        'sucursal_principal_id' => $sucursal->id,
        'puesto_id' => $this->estructura['puesto']->id,
        'jefe_id' => $this->gerente->colaborador_id,
        'estatus' => EstadoUsuario::Activo,
        'fecha_ingreso' => '2024-01-01',
        'sueldo_mensual' => 12000,
        'correo_corporativo' => 'persona.prueba@mr-lana.com',
    ]);
    $this->cuenta = User::factory()->create(['colaborador_id' => $this->persona->id]);
    $this->cuenta->assignRole('colaborador');
});

function abRegistrar(object $t): Baja
{
    $causal = CausalRotacion::query()->where('clave', 'RV-01')->firstOrFail();

    $t->actingAs($t->gerente)
        ->post(route('rh.bajas.store'), [
            'colaborador_id' => $t->persona->id,
            'forma_salida' => $causal->forma()->value,
            'causal_rotacion_id' => $causal->id,
            'fecha_baja' => now()->subDay()->toDateString(),
            'recontratable' => 'si',
            'comentarios' => 'La persona presentó su renuncia por escrito con su jefe.',
        ])
        ->assertSessionHasNoErrors();

    return Baja::query()->latest('id')->firstOrFail();
}

function abEntregarFiniquito(object $t, Baja $baja): void
{
    $baja->refresh()->load('causal');

    foreach (app(CatalogoBajasService::class)->documentosRequeridos($baja->forma_salida, $baja->causal) as $requerido) {
        $t->actingAs($t->gerente)
            ->post(route('rh.bajas.documentos.store', $baja), [
                'archivo' => UploadedFile::fake()->create(sprintf('%s.pdf', $requerido->clave), 30, 'application/pdf'),
                'documento_requerido_id' => $requerido->id,
            ])
            ->assertSessionHasNoErrors();
    }

    $cierres = app(CierreLaboralService::class);
    $cierre = $baja->refresh()->cierre;
    $cierres->finiquito($cierre) ?? $cierres->calcularFiniquito($cierre, $t->rh, 12000.0);
    $cierres->registrarEntregaFiniquito($cierre->refresh(), $t->rh, ['fecha' => now()->toDateString()]);
}

test('al aplicarse la baja (accesos suspendidos) sale UNA vez «dar de baja accesos» con los datos para Sistemas', function () {
    Mail::fake();

    $baja = abRegistrar($this);
    $cierre = $baja->cierre;

    Mail::assertSent(BajaColaboradorSistemasMail::class, 1);
    $mail = Mail::sent(BajaColaboradorSistemasMail::class)->first();
    $aviso = AvisoBajaSistemas::query()->firstOrFail();

    expect($this->cuenta->refresh()->acceso_bloqueado_en)->not->toBeNull()
        ->and($aviso->evento)->toBe(EventoAvisoBajaSistemas::AccesosBloqueados)
        ->and($aviso->origen_tipo)->toBe(OrigenAvisoBajaSistemas::Cierre)
        ->and($aviso->origen_id)->toBe($cierre->id)
        ->and($aviso->estado)->toBe(EstadoAvisoBajaSistemas::Enviado)
        ->and($mail->hasTo('sistemas@mr-lana.com'))->toBeTrue()
        ->and($mail->hasCc('otro@mr-lana.com'))->toBeTrue()
        ->and($mail->envelope()->subject)->toBe(sprintf('Baja en sistemas: dar de baja accesos — %s, Gestor de cobranza, %s', $this->persona->nombreCompleto(), $this->estructura['sucursal']->nombre))
        ->and($mail->dato('Número de empleado'))->toBe($this->persona->numero_empleado)
        ->and($mail->dato('Correo corporativo'))->toBe('persona.prueba@mr-lana.com')
        ->and($mail->dato('Usuario de PEOPLE'))->toBe($this->cuenta->username)
        ->and($mail->dato('Acceso a PEOPLE'))->toStartWith('Bloqueado desde el')
        ->and($mail->dato('Fecha efectiva de baja'))->toContain(now()->subDay()->format('d/m/Y'))
        ->and($mail->urlDetalle)->toBe(route('sistemas.pendientes-baja.show', $aviso->id))
        ->and(Activity::query()->where('event', 'aviso_sistemas_baja_enviado')->exists())->toBeTrue();

    // Sin causal (dato sensible), sin quién capturó la baja; con la lista de verificación.
    $mail->assertSeeInHtml('ERP de cobranza')
        ->assertSeeInHtml('Ver detalle de la baja')
        ->assertDontSeeInHtml('RV-01')
        ->assertDontSeeInHtml($this->gerente->name)
        ->assertSeeInText('[ ] ERP de cobranza')
        ->assertSeeInText('Correo corporativo: persona.prueba@mr-lana.com');

    // Reintento de la cola, re-suspender el acceso o repetir el aviso: no duplica.
    (new EnviarAvisoBajaSistemasJob($aviso->id))->handle(app(AvisoBajaSistemasService::class));
    app(CierreLaboralService::class)->suspenderAccesoPorBaja($cierre->refresh(), $this->rh);
    app(AvisoBajaSistemasService::class)->alAplicarBaja($cierre, $this->rh);

    Mail::assertSent(BajaColaboradorSistemasMail::class, 1);
    expect(AvisoBajaSistemas::query()->count())->toBe(1);
});

test('al registrar la entrega del finiquito sale UNA vez «baja concluida»', function () {
    Mail::fake();
    $baja = abRegistrar($this);

    abEntregarFiniquito($this, $baja);

    expect($baja->refresh()->cierre?->estado)->toBe(EstadoCierreLaboral::ExpedienteCerrado);
    Mail::assertSent(BajaColaboradorSistemasMail::class, 2);

    $concluida = Mail::sent(BajaColaboradorSistemasMail::class, fn (BajaColaboradorSistemasMail $m) => $m->evento === EventoAvisoBajaSistemas::BajaConcluida)->first();
    expect($concluida)->not->toBeNull()
        ->and($concluida->envelope()->subject)->toStartWith('Baja concluida — finiquito entregado')
        ->and($concluida->dato('Finiquito entregado'))->toContain(now()->format('d/m/Y'));
    $concluida->assertSeeInHtml('Confirmar desactivado')->assertDontSeeInHtml('12,000')->assertDontSeeInHtml('12000');

    // Repetir el aviso de cierre no duplica.
    app(AvisoBajaSistemasService::class)->alEntregarFiniquito($baja->cierre, $this->rh);
    Mail::assertSent(BajaColaboradorSistemasMail::class, 2);
    expect(AvisoBajaSistemas::query()->where('evento', EventoAvisoBajaSistemas::BajaConcluida->value)->count())->toBe(1);
});

test('si el correo falla la baja NO se revierte: queda fallido, warning y auditoría', function () {
    Log::spy();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP caído'));

    $baja = abRegistrar($this);

    $aviso = AvisoBajaSistemas::query()->firstOrFail();
    expect($baja->cierre)->not->toBeNull()
        ->and($this->cuenta->refresh()->acceso_bloqueado_en)->not->toBeNull()
        ->and($aviso->estado)->toBe(EstadoAvisoBajaSistemas::Fallido)
        ->and($aviso->ultimo_error)->toContain('SMTP caído')
        ->and(Activity::query()->where('event', 'aviso_sistemas_baja_fallido')->exists())->toBeTrue();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $mensaje) => str_contains($mensaje, 'correo de baja a Sistemas'))
        ->atLeast()->once();
});

test('baja cancelada después del aviso: se avisa a Sistemas que reactive; lo no enviado se cancela', function () {
    Mail::fake();
    $baja = abRegistrar($this);

    $this->actingAs($this->rh)->post(route('rh.bajas.cancelar', $baja), ['motivo' => 'La persona decidió quedarse.'])->assertSessionHasNoErrors();

    expect(AvisoBajaSistemas::query()->where('evento', EventoAvisoBajaSistemas::AccesosBloqueados->value)->value('estado'))->toBe(EstadoAvisoBajaSistemas::Cancelado)
        ->and(AvisoBajaSistemas::query()->where('evento', EventoAvisoBajaSistemas::BajaCancelada->value)->value('estado'))->toBe(EstadoAvisoBajaSistemas::Enviado);
    Mail::assertSent(BajaColaboradorSistemasMail::class, fn (BajaColaboradorSistemasMail $m) => $m->evento === EventoAvisoBajaSistemas::BajaCancelada);

    // Un aviso cancelado no se reenvía.
    $cancelado = AvisoBajaSistemas::query()->where('evento', EventoAvisoBajaSistemas::AccesosBloqueados->value)->firstOrFail();
    $this->actingAs($this->sistemas)->post(route('sistemas.pendientes-baja.reenviar', $cancelado))->assertSessionHasErrors('aviso_sistemas');
});

test('bandeja: Sistemas ve el detalle y lo marca atendido; un colaborador recibe 403', function () {
    $this->withoutVite();
    Mail::fake();
    abRegistrar($this);
    $aviso = AvisoBajaSistemas::query()->firstOrFail();

    $this->actingAs(clUsuario('colaborador'))->get(route('sistemas.pendientes-baja.index'))->assertForbidden();

    $this->actingAs($this->sistemas)->get(route('sistemas.pendientes-baja.index'))->assertOk();
    $this->actingAs($this->sistemas)->get(route('sistemas.pendientes-baja.show', $aviso))->assertOk();
    $this->actingAs($this->sistemas)->post(route('sistemas.pendientes-baja.atender', $aviso), ['notas' => 'ERP y correo desactivados.'])->assertSessionHasNoErrors();

    expect($aviso->refresh()->atendido_en)->not->toBeNull();

    $this->actingAs($this->sistemas)->post(route('sistemas.pendientes-baja.reenviar', $aviso))->assertSessionHasNoErrors();
    Mail::assertSent(BajaColaboradorSistemasMail::class, 2);
});
