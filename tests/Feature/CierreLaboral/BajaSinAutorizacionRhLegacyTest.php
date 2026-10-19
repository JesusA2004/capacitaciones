<?php

use App\Enums\EstadoUsuario;
use App\Enums\PrioridadTarea;
use App\Enums\TipoTarea;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\NotificacionEntrega;
use App\Models\TareaRh;
use App\Models\User;
use App\Services\Notificaciones\ReconciliadorNotificacionesService;
use App\Services\Tareas\NotificadorRhService;
use App\Services\Tareas\TareaService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| Flujo DEFINITIVO de la baja: jefe directo autorizado o RH la inicia →
| documentos → finiquito → registrar entrega → cierre. Sin preautorización
| ni autorización posterior de RH: la clave `cierre_autorizacion_rh` (y
| `cierre_preautorizacion`) es LEGACY y nada la genera para un cierre nuevo
| — ni el servicio, ni la bandeja, ni el reconciliador de avisos.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    $estructura = clEstructura();
    $this->gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $estructura['sucursal']->id]);
    $this->rh = clUsuario('rh_admin');
    $this->persona = Colaborador::factory()->create([
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'jefe_id' => $this->gerente->colaborador_id,
        'estatus' => EstadoUsuario::Activo,
        'fecha_ingreso' => '2024-01-01',
    ]);
    User::factory()->create(['colaborador_id' => $this->persona->id])->assignRole('colaborador');
});

function bajaLegacyAutorizacionNoGenerada(): void
{
    $legacy = [TipoTarea::CierreAutorizacionRh->value, TipoTarea::CierrePreautorizacion->value];

    expect(TareaRh::query()->whereIn('tipo', $legacy)->exists())->toBeFalse()
        ->and(NotificacionEntrega::query()->whereIn('evento', [...$legacy, 'pendiente_cierre_autorizacion_rh', 'pendiente_cierre_preautorizacion'])->exists())->toBeFalse()
        ->and(DB::table('notifications')->where('data', 'like', '%cierre_autorizacion_rh%')->exists())->toBeFalse();
}

test('una baja nueva iniciada por el jefe directo no genera cierre_autorizacion_rh (tarea ni aviso), tampoco tras reconciliar', function () {
    $this->actingAs($this->gerente)
        ->post(route('rh.colaboradores.cierres.store', $this->persona), ['tipo_baja' => 'renuncia', 'motivo' => 'Renuncia voluntaria.', 'fecha_efectiva' => now()->toDateString()])
        ->assertSessionHasNoErrors();

    $cierre = CierreLaboral::query()->where('colaborador_id', $this->persona->id)->firstOrFail();
    bajaLegacyAutorizacionNoGenerada();

    // Nadie recibe «revisar la solicitud» de la baja: no se autoriza.
    expect(NotificacionEntrega::query()->where('accion', 'revisar_solicitud')->where('related_id', $cierre->solicitud_interna_id)->exists())->toBeFalse();

    // El reconciliador (red de seguridad) tampoco repone un aviso de autorización.
    $this->travel(5)->minutes();
    app(ReconciliadorNotificacionesService::class)->reconciliar();

    bajaLegacyAutorizacionNoGenerada();
    expect(NotificacionEntrega::query()->where('accion', 'revisar_solicitud')->where('related_id', $cierre->solicitud_interna_id)->exists())->toBeFalse()
        // Lo que sigue es el finiquito.
        ->and(TareaRh::query()->where('tipo', TipoTarea::FiniquitoPendiente->value)->whereNull('resuelta_en')->exists())->toBeTrue();
});

test('una baja nueva iniciada por RH tampoco genera cierre_autorizacion_rh', function () {
    $this->actingAs($this->rh)
        ->post(route('rh.colaboradores.cierres.store', $this->persona), ['tipo_baja' => 'renuncia', 'motivo' => 'Renuncia voluntaria.', 'fecha_efectiva' => now()->toDateString()])
        ->assertSessionHasNoErrors();

    $this->travel(5)->minutes();
    app(ReconciliadorNotificacionesService::class)->reconciliar();

    bajaLegacyAutorizacionNoGenerada();
});

test('los tipos/eventos legacy nunca se crean aunque algún código los pida', function () {
    expect(TipoTarea::CierreAutorizacionRh->esLegacy())->toBeTrue()
        ->and(TipoTarea::CierreAutorizacionRh->etiqueta())->toStartWith('Legacy')
        ->and(config('configuracion_sistema.eventos.cierre_autorizacion_rh.legacy'))->toBeTrue()
        ->and(TipoTarea::FiniquitoPendiente->esLegacy())->toBeFalse();

    $tarea = app(TareaService::class)->abrir(TipoTarea::CierreAutorizacionRh, $this->persona, ['permiso' => 'cierres.gestionar', 'prioridad' => PrioridadTarea::Alta]);
    $avisados = app(NotificadorRhService::class)->notificarEvento('cierre_autorizacion_rh', $this->persona, [], 'Baja por autorizar', 'Prueba', $this->persona, 'autorizar_cierre');

    expect($tarea)->toBeNull()
        ->and($avisados)->toBeEmpty();
    bajaLegacyAutorizacionNoGenerada();
});

test('el reconciliador cierra un pendiente legacy histórico abierto y no repone su aviso', function () {
    $historica = TareaRh::query()->create([
        'tipo' => TipoTarea::CierreAutorizacionRh,
        'titulo' => 'Baja pendiente de autorización (histórica)',
        'prioridad' => PrioridadTarea::Alta,
        'colaborador_id' => $this->persona->id,
        'asignado_permiso' => 'cierres.gestionar',
        'clave' => 'legacy-historica',
        'clave_abierta' => 'legacy-historica',
    ]);

    $this->travel(5)->minutes();
    app(ReconciliadorNotificacionesService::class)->reconciliar();

    expect($historica->refresh()->resuelta_en)->not->toBeNull()
        ->and(NotificacionEntrega::query()->where('evento', 'pendiente_cierre_autorizacion_rh')->exists())->toBeFalse();
});
