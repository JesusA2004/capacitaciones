<?php

use App\Enums\EstadoUsuario;
use App\Enums\EventoAvisoBajaSistemas;
use App\Enums\ModalidadBaja;
use App\Mail\BajaColaboradorSistemasMail;
use App\Models\AvisoBajaSistemas;
use App\Models\Baja;
use App\Models\CausalRotacion;
use App\Models\Colaborador;
use App\Models\User;
use App\Notifications\Bajas\AvisoBajaColaboradorNotification;
use App\Services\Notificaciones\AvisoBajaSistemasService;
use Database\Seeders\CausalesRotacionSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| Bajas inmediatas vs. programadas (docs/BAJAS_Y_CAUSALES.md): la
| programada conserva el acceso hasta su fecha, ese día el comando diario la
| ejecuta (idempotente); Sistemas recibe desde el registro el aviso de «baja
| anticipada»; sin modalidad (API anterior) todo sigue como inmediata.
*/

beforeEach(function () {
    clRelojMediodiaMexico();
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(CausalesRotacionSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    $estructura = clEstructura();
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $estructura['sucursal']->id]);
    $this->persona = Colaborador::factory()->create([
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'puesto_id' => $estructura['puesto']->id,
        'estatus' => EstadoUsuario::Activo,
        'fecha_ingreso' => '2024-01-01',
        'sueldo_mensual' => 12000,
    ]);
    $this->cuenta = User::factory()->create(['colaborador_id' => $this->persona->id]);
    $this->cuenta->assignRole('colaborador');
    $this->cuenta->createToken('app');
});

/** @return array<string, mixed> */
function bpDatos(array $extra = []): array
{
    $causal = CausalRotacion::query()->where('clave', 'RV-01')->firstOrFail();

    return [
        'colaborador_id' => test()->persona->id,
        'forma_salida' => $causal->forma()->value,
        'causal_rotacion_id' => $causal->id,
        'fecha_baja' => now()->addDays(20)->toDateString(),
        'recontratable' => 'si',
        'comentarios' => 'Renuncia por mejor oferta laboral, entregada por escrito.',
        'modalidad_baja' => 'programada',
        ...$extra,
    ];
}

it('programa la baja sin tocar el acceso y el comando la ejecuta en su fecha una sola vez', function () {
    $this->actingAs($this->rh)->post(route('rh.bajas.store'), bpDatos())->assertSessionHasNoErrors();

    $baja = Baja::query()->latest('id')->firstOrFail();
    $cierre = $baja->cierre;

    expect($cierre->modalidad_baja)->toBe(ModalidadBaja::Programada)
        ->and($cierre->avisar_colaborador)->toBeFalse()
        ->and($cierre->esProgramadaPendiente())->toBeTrue()
        ->and($this->cuenta->refresh()->acceso_bloqueado_en)->toBeNull()
        ->and($this->cuenta->tokens()->count())->toBe(1)
        ->and($this->persona->refresh()->estatus)->toBe(EstadoUsuario::Activo);

    // Sin «avisar», la persona no recibe su aviso.
    Notification::assertNotSentTo($this->cuenta, AvisoBajaColaboradorNotification::class);

    // Antes de la fecha el comando no hace nada.
    $this->artisan('cierres:revisar-fechas')->assertSuccessful();
    expect($this->cuenta->refresh()->acceso_bloqueado_en)->toBeNull();

    $this->travelTo(now()->addDays(20));
    $this->artisan('cierres:revisar-fechas')->assertSuccessful();
    $this->artisan('cierres:revisar-fechas')->assertSuccessful();

    expect($cierre->refresh()->programada_aplicada_en)->not->toBeNull()
        ->and($this->cuenta->refresh()->acceso_bloqueado_en)->not->toBeNull()
        ->and($this->cuenta->tokens()->count())->toBe(0)
        ->and($this->persona->refresh()->estatus)->toBe(EstadoUsuario::Inactivo)
        ->and($baja->refresh()->acceso_suspendido_en)->not->toBeNull()
        ->and($baja->bitacora()->where('accion', 'ejecuto_programada')->count())->toBe(1);
});

it('rechaza una programada con fecha de hoy y avisa al colaborador cuando se elige', function () {
    $this->actingAs($this->rh)->post(route('rh.bajas.store'), bpDatos(['fecha_baja' => now()->toDateString()]))
        ->assertSessionHasErrors('fecha_baja');

    $this->actingAs($this->rh)->post(route('rh.bajas.store'), bpDatos(['avisar_colaborador' => true]))->assertSessionHasNoErrors();

    Notification::assertSentTo($this->cuenta, AvisoBajaColaboradorNotification::class);
});

it('el correo a Sistemas dice baja anticipada y el cambio de fecha manda actualización', function () {
    $this->actingAs($this->rh)->post(route('rh.bajas.store'), bpDatos())->assertSessionHasNoErrors();
    $baja = Baja::query()->latest('id')->firstOrFail();

    $aviso = AvisoBajaSistemas::query()->where('origen_id', $baja->cierre_laboral_id)->where('evento', EventoAvisoBajaSistemas::AccesosBloqueados->value)->firstOrFail();
    $correo = app(AvisoBajaSistemasService::class)->mailable($aviso, $this->persona->refresh());

    expect($correo)->toBeInstanceOf(BajaColaboradorSistemasMail::class)
        ->and($correo->programada)->toBeTrue()
        ->and($correo->envelope()->subject)->toContain('Baja anticipada')
        ->and($correo->instruccion())->toContain('ese mismo día');

    $nueva = now()->addDays(30)->toDateString();
    $this->actingAs($this->rh)->post(route('rh.bajas.reprogramar', $baja), ['fecha_baja' => $nueva, 'motivo' => 'La persona pidió quedarse más tiempo.'])->assertSessionHasNoErrors();

    expect($baja->refresh()->fecha_baja->toDateString())->toBe($nueva)
        ->and($baja->cierre->fecha_efectiva->toDateString())->toBe($nueva)
        ->and(AvisoBajaSistemas::query()->where('origen_id', $baja->cierre_laboral_id)->where('evento', EventoAvisoBajaSistemas::BajaReprogramada->value)->exists())->toBeTrue();
});

it('sin modalidad (API anterior) la baja sigue siendo inmediata', function () {
    $datos = bpDatos(['fecha_baja' => now()->subDay()->toDateString()]);
    unset($datos['modalidad_baja']);

    $this->actingAs($this->rh)->postJson(route('api.v1.rh.bajas.store'), $datos)->assertCreated()
        ->assertJsonPath('data.modalidad_baja', 'inmediata');

    expect($this->cuenta->refresh()->acceso_bloqueado_en)->not->toBeNull();
});
