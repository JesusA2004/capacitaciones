<?php

use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\ReciboNomina;
use App\Models\User;
use App\Notifications\Mobile\PendienteRhNotification;
use App\Services\Notificaciones\DestinoNotificacionService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| Por defecto el recibo de nómina se entrega IMPRESO (RH genera el PDF):
| el colaborador no lo ve ni lo descarga en Mi espacio (web/app) ni como
| documento de su expediente, y no recibe aviso. RH sí lo sigue viendo.
| config('features.recibos_colaborador') = false.
*/

beforeEach(function () {
    config(['features.recibos_colaborador' => false]);
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    $estructura = clEstructura();
    $this->colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $estructura['sucursal']->id, 'numero_empleado' => 'EMP-0300']);
    $this->cuenta = User::factory()->create(['colaborador_id' => $this->colaborador->id]);
    $this->cuenta->assignRole('colaborador');

    Sanctum::actingAs($this->rh);
    $this->reciboId = $this->postJson("/api/v1/rh/colaboradores/{$this->colaborador->id}/recibos", [
        'periodo_inicio' => '2026-09-14',
        'periodo_fin' => '2026-09-20',
        'fecha_pago' => '2026-09-21',
        'conceptos' => [['tipo' => 'percepcion', 'concepto' => 'Sueldo semanal', 'importe' => 3500]],
    ])->assertCreated()->json('data.id');
});

test('el colaborador no ve, no descarga ni recibe aviso de su recibo de nómina', function () {
    $recibo = ReciboNomina::query()->findOrFail($this->reciboId);
    $documento = GeneratedDocument::query()->where('documentable_type', $recibo->getMorphClass())->where('documentable_id', $recibo->id)->firstOrFail();

    Notification::assertNotSentTo($this->cuenta, PendienteRhNotification::class);

    Sanctum::actingAs($this->cuenta);
    $this->getJson('/api/v1/colaborador/recibos')->assertNotFound();
    $this->getJson("/api/v1/colaborador/recibos/{$recibo->id}")->assertForbidden();
    $this->get("/api/v1/colaborador/recibos/{$recibo->id}/pdf")->assertForbidden();

    // Tampoco como documento laboral de su propio expediente.
    $ids = collect($this->getJson('/api/v1/colaborador/documentos-laborales')->assertOk()->json('data'))->pluck('id');
    expect($ids)->not->toContain($documento->id);
    $this->getJson("/api/v1/colaborador/documentos-laborales/{$documento->id}/descargar")->assertForbidden();

    // Web: "Mis recibos" ya no existe para el colaborador.
    $this->actingAs($this->cuenta)->get(route('portal.recibos'))->assertNotFound();
});

test('RH sigue consultando y descargando el recibo del colaborador', function () {
    Sanctum::actingAs($this->rh);

    $this->getJson("/api/v1/rh/recibos/{$this->reciboId}")->assertOk();
});

test('Mi expediente del colaborador no trae recibos de nómina ni puede descargarlos por la ruta del expediente', function () {
    $recibo = ReciboNomina::query()->findOrFail($this->reciboId);

    $this->actingAs($this->cuenta)
        ->get(route('mi-expediente'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('recibosNomina', null));

    $this->actingAs($this->cuenta)
        ->get(route('rh.expedientes.recibos-nomina.descargar', $recibo))
        ->assertForbidden();

    $this->actingAs($this->cuenta)->get(route('portal.recibos.pdf', $recibo))->assertForbidden();
});

test('RH sí ve la pestaña de recibos en el expediente del colaborador', function () {
    $this->actingAs($this->rh)
        ->get(route('rh.expedientes.show', $this->colaborador))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('recibosNomina', 1));
});

test('el enlace de una notificación de recibo nunca lleva al colaborador a la pestaña de recibos', function () {
    $destino = app(DestinoNotificacionService::class);
    $notificacion = new DatabaseNotification([
        'id' => (string) Str::uuid(),
        'type' => 'x',
        'data' => ['type' => 'recibo_nomina', 'related_type' => 'ReciboNomina', 'resource_id' => $this->reciboId, 'colaborador_id' => $this->colaborador->id],
    ]);

    $resultado = json_encode($destino->resolver($notificacion, $this->cuenta));

    expect($resultado)->not->toContain('tab=recibos');
});
