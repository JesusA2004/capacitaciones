<?php

use App\Models\SolicitudInterna;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
});

test('crear una solicitud de baja requiere fecha efectiva y tipo de baja', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $colaborador = User::factory()->create();

    $this->actingAs($rh)
        ->post(route('solicitudes.store'), [
            'tipo' => 'baja_colaborador',
            'motivo' => 'Renuncia voluntaria.',
            'colaborador_objetivo_id' => $colaborador->id,
        ])
        ->assertSessionHasErrors(['fecha_efectiva', 'tipo_baja']);

    $this->actingAs($rh)
        ->post(route('solicitudes.store'), [
            'tipo' => 'baja_colaborador',
            'motivo' => 'Renuncia voluntaria.',
            'colaborador_objetivo_id' => $colaborador->id,
            'fecha_efectiva' => now()->addWeek()->toDateString(),
            'tipo_baja' => 'renuncia',
        ])
        ->assertSessionHasNoErrors();

    $solicitud = SolicitudInterna::where('colaborador_objetivo_id', $colaborador->id)->first();

    expect($solicitud)->not->toBeNull()
        ->and($solicitud->tipo_baja->value)->toBe('renuncia')
        ->and($solicitud->fecha_efectiva)->not->toBeNull();
});

test('no se puede aprobar una baja de colaborador sin evidencia adjunta', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $colaborador = User::factory()->create();
    $solicitud = SolicitudInterna::factory()->create([
        'tipo' => 'baja_colaborador',
        'estado' => 'en_revision',
        'colaborador_objetivo_id' => $colaborador->id,
        'fecha_efectiva' => now()->addWeek(),
        'tipo_baja' => 'renuncia',
    ]);

    $this->actingAs($rh)
        ->post(route('rh.solicitudes.aprobar', $solicitud))
        ->assertSessionHasErrors('evidencia');

    expect($solicitud->fresh()->estado->value)->toBe('en_revision')
        ->and($colaborador->fresh()->estatus->value)->toBe('activo');
});

test('una baja de colaborador con evidencia adjunta si se puede aprobar', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $colaborador = User::factory()->create();
    $solicitud = SolicitudInterna::factory()->create([
        'tipo' => 'baja_colaborador',
        'estado' => 'en_revision',
        'colaborador_objetivo_id' => $colaborador->id,
        'fecha_efectiva' => now()->addWeek(),
        'tipo_baja' => 'renuncia',
    ]);

    $this->actingAs($rh)
        ->post(route('solicitudes.documentos.store', $solicitud), [
            'archivo' => UploadedFile::fake()->create('autorizacion.pdf', 100, 'application/pdf'),
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($rh)
        ->post(route('rh.solicitudes.aprobar', $solicitud))
        ->assertSessionHasNoErrors();

    expect($solicitud->fresh())
        ->estado->value->toBe('aprobada')
        ->and($colaborador->fresh()->estatus->value)->toBe('inactivo');
});

test('rh puede ver la evidencia de una baja y un usuario sin acceso no puede', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $ajeno = User::factory()->create();
    $ajeno->assignRole('colaborador');

    $solicitud = SolicitudInterna::factory()->create(['tipo' => 'baja_colaborador']);

    $this->actingAs($rh)->post(route('solicitudes.documentos.store', $solicitud), [
        'archivo' => UploadedFile::fake()->create('evidencia.pdf', 100, 'application/pdf'),
    ]);

    $documento = $solicitud->documentos()->first();

    $this->actingAs($rh)
        ->get(route('rh.solicitudes.documentos.ver', [$solicitud, $documento]))
        ->assertOk();

    $this->actingAs($ajeno)
        ->get(route('rh.solicitudes.documentos.ver', [$solicitud, $documento]))
        ->assertForbidden();
});
