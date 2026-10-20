<?php

use App\Enums\EstadoOnboarding;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\TipoSolicitudInterna;
use App\Models\Colaborador;
use App\Models\EntregaActivo;
use App\Models\GeneratedDocument;
use App\Models\OnboardingProceso;
use App\Models\ReciboNomina;
use App\Models\SolicitudInterna;
use App\Models\TipoActivo;
use App\Models\User;
use App\Services\Autenticacion\CredencialesService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

/*
| Regla: todo formato que se genera para un colaborador se puede VER antes
| de generarlo/aprobarlo. La vista previa responde el PDF/HTML real y NO
| crea registros, archivos, avisos ni correos.
*/

beforeEach(function () {
    config(['features.recibos_colaborador' => false]);
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Storage::fake('local');
    Notification::fake();
    Mail::fake();
    $estructura = clEstructura();
    $this->jefe = User::factory()->create();
    $this->jefe->assignRole('jefe_directo');
    $this->persona = Colaborador::factory()->create([
        'name' => 'Ana', 'apellidos' => 'López Ruiz',
        'jefe_id' => $this->jefe->colaborador_id,
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'departamento_id' => $estructura['departamento']->id,
        'fecha_ingreso' => now()->subYears(2),
        'sueldo_mensual' => 12000,
    ]);
    $this->cuenta = User::factory()->create(['colaborador_id' => $this->persona->id, 'email' => 'ana.lopez@example.com']);
    $this->cuenta->assignRole('colaborador');
    $this->rh = clUsuario('rh_admin');
});

function vpPermisoEnviado(object $t): SolicitudInterna
{
    Sanctum::actingAs($t->cuenta);
    test()->postJson('/api/v1/solicitudes', [
        'tipo' => 'permiso',
        'permiso_tipo' => 'salir_temprano',
        'fecha_inicio' => now()->addDays(3)->toDateString(),
        'hora_salida' => '15:00',
        'permiso_goce' => 'especial',
        'permiso_causal' => 'cumpleanos',
        'observaciones' => 'Comida familiar',
    ])->assertCreated();

    return SolicitudInterna::query()->latest('id')->firstOrFail();
}

test('RH ve el formato de permiso ANTES de autorizar (web y API) sin generar nada', function () {
    $permiso = vpPermisoEnviado($this);
    // Los masters que trae la instalación ya existen: solo importa que no se agregue nada.
    $archivosAntes = [...Storage::disk('nas')->allFiles(), ...Storage::disk('local')->allFiles()];

    $this->actingAs($this->rh)->get(route('rh.solicitudes.show', $permiso))
        ->assertInertia(fn (AssertableInertia $pagina) => $pagina
            ->where('vistasPrevias.0.documento', 'comprobante')
            ->where('vistasPrevias.0.titulo', 'Formato de permiso'));

    $this->actingAs($this->rh)->get(route('rh.solicitudes.vista-previa', [$permiso, 'comprobante']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Cache-Control', 'no-store, private');

    Sanctum::actingAs($this->rh);
    $this->get("/api/v1/rh/solicitudes/{$permiso->id}/vista-previa/comprobante")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $permiso->refresh();
    expect($permiso->estado)->not->toBe(EstadoSolicitudInterna::Aprobada)
        ->and($permiso->revisado_por)->toBeNull()
        ->and($permiso->revisado_en)->toBeNull()
        ->and(GeneratedDocument::query()->count())->toBe(0)
        ->and([...Storage::disk('nas')->allFiles(), ...Storage::disk('local')->allFiles()])->toBe($archivosAntes);

    // Sigue sin liberarse el formato definitivo.
    $this->actingAs($this->rh)->get(route('rh.solicitudes.permiso-pdf', $permiso))->assertStatus(409);
});

test('el comprobante de vacaciones también se ve antes de aprobar', function () {
    $vacaciones = SolicitudInterna::factory()->create([
        'user_id' => $this->cuenta->id,
        'colaborador_id' => $this->persona->id,
        'tipo' => TipoSolicitudInterna::Vacaciones->value,
        'fecha_inicio' => now()->addDays(10)->toDateString(),
        'fecha_fin' => now()->addDays(12)->toDateString(),
        'dias_solicitados' => 3,
    ]);

    $this->actingAs($this->rh)->get(route('rh.solicitudes.vista-previa', [$vacaciones, 'comprobante']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect(GeneratedDocument::query()->count())->toBe(0)
        ->and($vacaciones->fresh()->estado)->toBe(EstadoSolicitudInterna::Enviada);
});

test('quien no puede autorizar no ve la vista previa del formato (403)', function () {
    $permiso = vpPermisoEnviado($this);

    // El jefe solo da visto bueno; el solicitante tampoco autoriza lo suyo.
    $this->actingAs($this->jefe)->get(route('rh.solicitudes.vista-previa', [$permiso, 'comprobante']))->assertForbidden();
    $this->actingAs($this->cuenta)->get(route('rh.solicitudes.vista-previa', [$permiso, 'comprobante']))->assertForbidden();
    Sanctum::actingAs($this->jefe);
    $this->get("/api/v1/rh/solicitudes/{$permiso->id}/vista-previa/comprobante")->assertForbidden();
});

function vpDatosRecibo(): array
{
    return [
        'periodo_inicio' => '2026-09-14',
        'periodo_fin' => '2026-09-20',
        'fecha_pago' => '2026-09-21',
        'sueldo_base' => 2800,
        'percepciones' => [['concepto' => 'Bono de puntualidad', 'monto' => 200]],
        'deducciones' => [['concepto' => 'Uniforme', 'monto' => 150]],
    ];
}

test('RH ve el recibo simple de pago antes de generarlo, sin crear el recibo ni su PDF', function () {
    $archivosAntes = Storage::disk('nas')->allFiles();

    $this->actingAs($this->rh)
        ->postJson(route('rh.expedientes.recibos-nomina.vista-previa', $this->persona), vpDatosRecibo())
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Cache-Control', 'no-store, private');

    expect(ReciboNomina::query()->count())->toBe(0)
        ->and(GeneratedDocument::query()->count())->toBe(0)
        ->and(Storage::disk('nas')->allFiles())->toBe($archivosAntes);
    Notification::assertNothingSent();
});

test('el colaborador nunca ve la vista previa de su propio recibo (recibos impresos)', function () {
    $this->actingAs($this->cuenta)
        ->postJson(route('rh.expedientes.recibos-nomina.vista-previa', $this->persona), vpDatosRecibo())
        ->assertForbidden();

    // Ni siquiera RH sobre su PROPIO expediente mientras el autoservicio esté apagado.
    $this->actingAs($this->rh)
        ->postJson(route('rh.expedientes.recibos-nomina.vista-previa', $this->rh->colaborador_id), vpDatosRecibo())
        ->assertForbidden();

    expect(ReciboNomina::query()->count())->toBe(0);
});

function vpEntregaPendiente(object $t): array
{
    clPlantilla('responsiva_prueba', ['contenido_html' => '<h1>Carta responsiva {{nombre_completo}}</h1><p>{{activo}} {{activo_identificador}}</p>']);
    $tipo = TipoActivo::query()->create(['clave' => 'celular', 'nombre' => 'Celular', 'requiere_identificador' => true, 'obligatorio' => true, 'plantilla_responsiva' => 'responsiva_prueba']);
    $proceso = OnboardingProceso::query()->create([
        'colaborador_id' => $t->persona->id,
        'estado' => EstadoOnboarding::EntregaActivos,
        'iniciado_en' => now(),
        'colaborador_abierto_id' => $t->persona->id,
    ]);

    return [$proceso, $tipo];
}

test('RH ve la carta responsiva antes de confirmar la entrega del activo (web y API)', function () {
    [$proceso, $tipo] = vpEntregaPendiente($this);
    $datos = ['tipo_activo_id' => $tipo->id, 'identificador' => 'IMEI-123'];

    $this->actingAs($this->rh)
        ->postJson(route('rh.onboarding.responsiva.vista-previa', $proceso), $datos)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    Sanctum::actingAs($this->rh);
    $this->postJson("/api/v1/rh/onboarding/{$proceso->id}/activos/vista-previa", $datos)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect(EntregaActivo::query()->count())->toBe(0)
        ->and(GeneratedDocument::query()->count())->toBe(0)
        ->and($proceso->fresh()->estado)->toBe(EstadoOnboarding::EntregaActivos);

    // Misma validación que la entrega: sin serie no hay vista previa.
    $this->actingAs($this->rh)
        ->postJson(route('rh.onboarding.responsiva.vista-previa', $proceso), ['tipo_activo_id' => $tipo->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('identificador');
});

test('sin permiso de entregar activos no hay vista previa de la responsiva (403)', function () {
    [$proceso, $tipo] = vpEntregaPendiente($this);

    $this->actingAs($this->cuenta)
        ->postJson(route('rh.onboarding.responsiva.vista-previa', $proceso), ['tipo_activo_id' => $tipo->id, 'identificador' => 'IMEI-123'])
        ->assertForbidden();
});

test('RH ve el correo de accesos con la contraseña enmascarada y no se envía nada', function () {
    $hashAntes = $this->cuenta->fresh()->password;

    $datos = $this->actingAs($this->rh)
        ->postJson(route('rh.colaboradores.credenciales.correo-vista-previa', $this->persona))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->json('data');

    expect($datos['destinatario'])->toBe('ana.lopez@example.com')
        ->and($datos['asunto'])->toBe('Tus accesos a MR. LANA PEOPLE')
        ->and($datos['html'])->toContain(CredencialesService::CONTRASENA_ENMASCARADA)
        ->and($datos['html'])->toContain((string) $this->cuenta->username)
        ->and($this->cuenta->fresh()->password)->toBe($hashAntes)
        ->and(Hash::check('password', $this->cuenta->fresh()->password))->toBeTrue();

    Notification::assertNothingSent();
    Mail::assertNothingSent();
});

test('sin permiso para dar accesos no hay vista previa del correo (403)', function () {
    $otro = clUsuario('colaborador');

    $this->actingAs($otro)
        ->postJson(route('rh.colaboradores.credenciales.correo-vista-previa', $this->persona))
        ->assertForbidden();

    Notification::assertNothingSent();
});
