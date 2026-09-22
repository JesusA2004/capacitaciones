<?php

use App\Enums\TipoTarea;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\SolicitudInterna;
use App\Models\TareaRh;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    $this->colaborador = Colaborador::factory()->create(['fecha_ingreso' => now()->subYears(2)]);
    $this->cuenta = User::factory()->create(['colaborador_id' => $this->colaborador->id]);
    $this->cuenta->assignRole('colaborador');
});

test('aprobar vacaciones genera el comprobante PDF archivado en el expediente y resuelve el pendiente', function () {
    Sanctum::actingAs($this->cuenta);
    $id = $this->postJson('/api/v1/solicitudes', [
        'tipo' => 'vacaciones',
        'motivo' => 'Descanso',
        'fecha_inicio' => now()->addDays(10)->toDateString(),
        'fecha_fin' => now()->addDays(11)->toDateString(),
        'dias_solicitados' => 2,
    ])->assertCreated()->json('id');

    expect(TareaRh::query()->where('tipo', TipoTarea::VacacionesPendiente->value)->whereNull('resuelta_en')->count())->toBe(1);

    Sanctum::actingAs($this->rh);
    $this->postJson("/api/v1/rh/solicitudes/{$id}/aprobar")->assertOk();

    $solicitud = SolicitudInterna::query()->findOrFail($id);
    $comprobante = GeneratedDocument::query()->where('documentable_type', $solicitud->getMorphClass())->where('documentable_id', $id)->firstOrFail();

    expect($comprobante->clave_plantilla)->toBe('comprobante_vacaciones')
        ->and($comprobante->categoria?->value)->toBe('vacaciones')
        ->and($comprobante->estado_flujo?->value)->toBe('archivado')
        ->and($comprobante->path)->toContain('/Vacaciones/');
    Storage::disk('nas')->assertExists($comprobante->path);

    expect(TareaRh::query()->where('tipo', TipoTarea::VacacionesPendiente->value)->whereNull('resuelta_en')->count())->toBe(0);

    // El colaborador ve su comprobante entre sus documentos.
    Sanctum::actingAs($this->cuenta);
    $this->getJson('/api/v1/colaborador/documentos-laborales')->assertOk()->assertJsonPath('data.0.id', $comprobante->id);
});

test('aprobar un permiso genera su comprobante en la carpeta de permisos', function () {
    $solicitud = SolicitudInterna::factory()->create([
        'user_id' => $this->cuenta->id,
        'colaborador_id' => $this->colaborador->id,
        'tipo' => 'permiso_con_goce',
        'estado' => 'enviada',
        'fecha_inicio' => now()->addDay()->toDateString(),
        'fecha_fin' => now()->addDay()->toDateString(),
    ]);

    Sanctum::actingAs($this->rh);
    $this->postJson("/api/v1/rh/solicitudes/{$solicitud->id}/aprobar")->assertOk();

    $comprobante = GeneratedDocument::query()->where('documentable_id', $solicitud->id)->where('clave_plantilla', 'comprobante_permiso')->firstOrFail();
    expect($comprobante->path)->toContain('/Permisos/');
});
